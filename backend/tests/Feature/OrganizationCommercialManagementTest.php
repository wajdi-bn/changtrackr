<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\OrganizationInvoice;
use App\Models\SaasPlan;
use App\Models\User;
use App\Services\OrganizationBillingService;
use App\Services\OrganizationSubscriptionLifecycleService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SaasPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrganizationCommercialManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolePermissionSeeder::class, SaasPlanSeeder::class]);
    }

    public function test_trial_has_four_employee_quota_excluding_the_administrator(): void
    {
        Notification::fake();
        $organization = $this->organization('trial-network');
        $subscription = app(OrganizationBillingService::class)->createTrial($organization);
        $administrator = $this->user($organization, 'admin');
        Sanctum::actingAs($administrator);

        $this->assertEquals(14, $subscription->trial_started_at->startOfDay()->diffInDays($subscription->trial_ends_at->startOfDay()));

        foreach (range(1, 2) as $index) {
            $this->postJson('/api/users', [
                'name' => "Trial Operator {$index}",
                'email' => "trial.operator.{$index}@example.com",
                'role' => 'operator',
            ])->assertCreated();
        }

        $this->postJson('/api/users', [
            'name' => 'Third Trial Operator',
            'email' => 'trial.operator.3@example.com',
            'role' => 'operator',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('plan_limit');

        foreach (range(1, 2) as $index) {
            $this->postJson('/api/users', [
                'name' => "Trial Technician {$index}",
                'email' => "trial.technician.{$index}@example.com",
                'role' => 'technician',
            ])->assertCreated();
        }

        $this->postJson('/api/users', [
            'name' => 'Employee Above Total Limit',
            'email' => 'employee.above.total.limit@example.com',
            'role' => 'technician',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('plan_limit');

        $this->assertSame(5, User::query()->where('organization_id', $organization->id)->count());
    }

    public function test_administrator_requests_a_plan_and_super_admin_settles_the_simulated_invoice(): void
    {
        $organization = $this->organization('billing-network');
        $subscription = app(OrganizationBillingService::class)->createTrial($organization);
        $administrator = $this->user($organization, 'admin');
        $superAdministrator = $this->user(null, 'super_admin');
        $starter = SaasPlan::query()->where('code', 'STARTER')->firstOrFail();
        Sanctum::actingAs($administrator);

        $invoiceId = $this->postJson('/api/organization-billing/requests', [
            'saas_plan_id' => $starter->id,
            'billing_cycle' => 'annual',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.plan.code', 'STARTER')
            ->json('data.id');

        Sanctum::actingAs($superAdministrator);
        $this->postJson("/api/commercial/invoices/{$invoiceId}/settle")
            ->assertOk()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.payment_provider', 'simulated');

        $this->assertDatabaseHas('organization_subscriptions', [
            'id' => $subscription->id,
            'saas_plan_id' => $starter->id,
            'status' => 'active',
            'billing_cycle' => 'annual',
        ]);
        $this->assertNotNull(OrganizationInvoice::query()->findOrFail($invoiceId)->paid_at);

        Sanctum::actingAs($administrator);
        $this->get("/api/commercial/invoices/{$invoiceId}/document")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_a_manually_suspended_valid_trial_is_restored_as_a_trial(): void
    {
        $organization = $this->organization('restored-trial-network');
        $billing = app(OrganizationBillingService::class);
        $subscription = $billing->createTrial($organization);
        $superAdministrator = $this->user(null, 'super_admin');

        $this->assertSame('suspended', $billing->suspend($subscription, $superAdministrator, null)->status);
        $this->assertSame('trialing', $billing->restore($subscription->fresh(), $superAdministrator, null)->status);
    }

    public function test_expired_trial_enters_grace_then_blocks_operations_without_blocking_billing(): void
    {
        $organization = $this->organization('lifecycle-network');
        $subscription = app(OrganizationBillingService::class)->createTrial($organization);
        $administrator = $this->user($organization, 'admin');
        $subscription->update(['trial_ends_at' => now()->subDay()]);

        app(OrganizationSubscriptionLifecycleService::class)->scan();
        $this->assertSame('grace_period', $subscription->fresh()->status);

        $subscription->update(['grace_ends_at' => now()->subMinute()]);
        app(OrganizationSubscriptionLifecycleService::class)->scan();
        $this->assertSame('suspended', $subscription->fresh()->status);

        Sanctum::actingAs($administrator);
        $this->getJson('/api/stations')
            ->assertStatus(402)
            ->assertJsonPath('code', 'organization_subscription_required');
        $this->getJson('/api/organization-billing')
            ->assertOk()
            ->assertJsonPath('subscription.status', 'suspended');
        $this->getJson('/api/profile')->assertOk();
    }

    public function test_commercial_documents_and_management_are_tenant_scoped(): void
    {
        $first = $this->organization('first-network');
        $second = $this->organization('second-network');
        app(OrganizationBillingService::class)->createTrial($first);
        app(OrganizationBillingService::class)->createTrial($second);
        $firstAdmin = $this->user($first, 'admin');
        $secondAdmin = $this->user($second, 'admin');
        $plan = SaasPlan::query()->where('code', 'BUSINESS')->firstOrFail();
        $invoice = app(OrganizationBillingService::class)->requestPlan($first, $firstAdmin, $plan, 'monthly');

        Sanctum::actingAs($secondAdmin);
        $this->get("/api/commercial/invoices/{$invoice->id}/document")->assertForbidden();
        $this->getJson('/api/commercial/portfolio')->assertForbidden();

        Sanctum::actingAs($firstAdmin);
        $this->getJson('/api/organization-billing')
            ->assertOk()
            ->assertJsonCount(1, 'invoices')
            ->assertJsonPath('organization.id', $first->id);
    }

    private function organization(string $slug): Organization
    {
        return Organization::query()->create([
            'name' => str($slug)->replace('-', ' ')->title(),
            'slug' => $slug,
            'contact_email' => "admin@{$slug}.example",
            'status' => 'active',
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function user(?Organization $organization, string $role, array $attributes = []): User
    {
        $user = User::factory()->create([
            'organization_id' => $organization?->id,
            'status' => 'active',
            ...$attributes,
        ]);
        $user->assignRole($role);

        return $user;
    }
}
