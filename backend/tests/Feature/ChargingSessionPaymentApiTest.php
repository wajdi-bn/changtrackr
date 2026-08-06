<?php

namespace Tests\Feature;

use App\Models\ChargingAttempt;
use App\Models\ChargingSession;
use App\Models\Connector;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\Station;
use App\Models\User;
use App\Services\PaymentService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ChargingSessionPaymentApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_client_can_start_and_stop_a_session_on_an_available_connector(): void
    {
        [$client, $organization] = $this->userWithRole('client');
        [$station, $connector] = $this->stationWithConnector($organization);
        Sanctum::actingAs($client);

        $sessionId = $this->postJson('/api/charging-sessions', [
            'station_id' => $station->id,
            'connector_id' => $connector->id,
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'charging')
            ->assertJsonPath('data.connector.id', $connector->id)
            ->json('data.id');

        $this->assertDatabaseHas('connectors', ['id' => $connector->id, 'status' => 'charging']);

        $this->postJson("/api/charging-sessions/{$sessionId}/stop")
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.payment_status', 'unpaid')
            ->assertJson(fn ($json) => $json->whereType('data.total_millimes', 'integer')->etc());

        $this->assertDatabaseHas('connectors', ['id' => $connector->id, 'status' => 'available']);
    }

    public function test_global_client_can_charge_with_two_different_organizations(): void
    {
        [$client, $firstOrganization] = $this->userWithRole('client');
        $secondOrganization = $this->organization('second-network');
        [$firstStation, $firstConnector] = $this->stationWithConnector($firstOrganization, 'CT-FIRST-SESSION');
        [$secondStation, $secondConnector] = $this->stationWithConnector($secondOrganization, 'CT-SECOND-SESSION');
        Sanctum::actingAs($client);

        $firstSessionId = $this->postJson('/api/charging-sessions', [
            'station_id' => $firstStation->id,
            'connector_id' => $firstConnector->id,
        ])->assertCreated()
            ->assertJsonPath('data.organization.id', $firstOrganization->id)
            ->assertJsonPath('data.organization.name', $firstOrganization->name)
            ->json('data.id');
        $this->postJson("/api/charging-sessions/{$firstSessionId}/stop")->assertOk();

        $secondSessionId = $this->postJson('/api/charging-sessions', [
            'station_id' => $secondStation->id,
            'connector_id' => $secondConnector->id,
        ])->assertCreated()
            ->assertJsonPath('data.organization.id', $secondOrganization->id)
            ->assertJsonPath('data.organization.name', $secondOrganization->name)
            ->json('data.id');
        $this->postJson("/api/charging-sessions/{$secondSessionId}/stop")->assertOk();

        $this->getJson('/api/charging-sessions')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['id' => $firstOrganization->id, 'name' => $firstOrganization->name])
            ->assertJsonFragment(['id' => $secondOrganization->id, 'name' => $secondOrganization->name]);
    }

    public function test_session_index_can_be_scoped_to_one_station(): void
    {
        $organization = $this->organization('station-session-filter');
        $admin = $this->user($organization, 'admin');
        $client = $this->user(null, 'client');
        [$selectedStation, $selectedConnector] = $this->stationWithConnector($organization, 'CT-SESSION-FILTER-001');
        [$otherStation, $otherConnector] = $this->stationWithConnector($organization, 'CT-SESSION-FILTER-002');
        $visible = $this->completedSession($client, $selectedStation, $selectedConnector, 'SES-FILTER-001');
        $this->completedSession($client, $otherStation, $otherConnector, 'SES-FILTER-002');
        Sanctum::actingAs($admin);

        $this->getJson("/api/charging-sessions?station_id={$selectedStation->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $visible->id)
            ->assertJsonPath('summary.total', 1)
            ->assertJsonPath('summary.completed', 1)
            ->assertJsonPath('summary.energy_kwh', 10);
    }

    public function test_session_index_is_paginated_and_keeps_the_clients_active_session_available(): void
    {
        [$client, $organization] = $this->userWithRole('client');
        [$station, $connector] = $this->stationWithConnector($organization, 'CT-SESSION-PAGE');

        foreach (range(1, 12) as $index) {
            $this->completedSession($client, $station, $connector, sprintf('SES-PAGE-%03d', $index));
        }

        $activeSession = $this->completedSession($client, $station, $connector, 'SES-PAGE-ACTIVE');
        $activeSession->update([
            'status' => 'charging',
            'payment_status' => 'authorized',
            'started_at' => now(),
            'ended_at' => null,
            'duration_seconds' => 0,
            'meter_stop_kwh' => null,
            'energy_kwh' => 0,
            'total_millimes' => 0,
        ]);
        Sanctum::actingAs($client);

        $this->getJson('/api/charging-sessions?page=2&per_page=5')
            ->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('summary.total', 13)
            ->assertJsonPath('summary.active', 1)
            ->assertJsonPath('active_session.id', $activeSession->id)
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.last_page', 3)
            ->assertJsonPath('meta.per_page', 5)
            ->assertJsonPath('meta.total', 13);

        $this->getJson('/api/charging-sessions?per_page=101')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('per_page');
    }

    public function test_global_client_cannot_start_a_session_for_an_inactive_organization(): void
    {
        [$client] = $this->userWithRole('client');
        $inactiveOrganization = Organization::query()->create([
            'name' => 'Suspended Network',
            'slug' => 'suspended-network',
            'status' => 'inactive',
        ]);
        [$station, $connector] = $this->stationWithConnector($inactiveOrganization, 'CT-INACTIVE-SESSION');
        Sanctum::actingAs($client);

        $this->postJson('/api/charging-sessions', [
            'station_id' => $station->id,
            'connector_id' => $connector->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('station_id');
    }

    public function test_completed_session_can_be_paid_through_the_simulated_adapter(): void
    {
        [$client, $organization] = $this->userWithRole('client');
        [$station, $connector] = $this->stationWithConnector($organization, 'CT-PAYMENT-001');
        $session = $this->completedSession($client, $station, $connector);
        Sanctum::actingAs($client);

        $paymentId = $this->postJson("/api/charging-sessions/{$session->id}/payments", [
            'method' => 'simulated_card',
            'simulation_outcome' => 'success',
            'idempotency_key' => '10000000-0000-4000-8000-000000000001',
        ])
            ->assertSuccessful()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.provider', 'simulated')
            ->json('data.id');

        $this->assertDatabaseHas('charging_sessions', ['id' => $session->id, 'payment_status' => 'paid']);
        $this->assertDatabaseHas('payments', ['charging_session_id' => $session->id, 'amount_millimes' => 9000, 'status' => 'paid']);
        $this->get("/api/payments/{$paymentId}/receipt")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_payment_index_is_paginated_without_truncating_the_summary(): void
    {
        [$client, $organization] = $this->userWithRole('client');
        [$station, $connector] = $this->stationWithConnector($organization, 'CT-PAYMENT-PAGE');

        foreach (range(1, 12) as $index) {
            $session = $this->completedSession($client, $station, $connector, sprintf('SES-PAY-PAGE-%03d', $index));
            $this->payment(
                $session,
                $client,
                sprintf('PAY-PAGE-%03d', $index),
                sprintf('70000000-0000-4000-8000-%012d', $index),
            );
        }

        Sanctum::actingAs($client);

        $this->getJson('/api/payments?page=2&per_page=5')
            ->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('summary.total', 12)
            ->assertJsonPath('summary.paid', 12)
            ->assertJsonPath('summary.revenue_millimes', 108000)
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.last_page', 3)
            ->assertJsonPath('meta.per_page', 5)
            ->assertJsonPath('meta.total', 12);

        $this->getJson('/api/payments?per_page=101')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('per_page');
    }

    public function test_failed_simulated_payment_can_be_retried_successfully(): void
    {
        [$client, $organization] = $this->userWithRole('client');
        [$station, $connector] = $this->stationWithConnector($organization, 'CT-PAYMENT-RETRY');
        $session = $this->completedSession($client, $station, $connector);
        Sanctum::actingAs($client);

        $this->postJson("/api/charging-sessions/{$session->id}/payments", [
            'method' => 'simulated_d17',
            'simulation_outcome' => 'declined',
            'idempotency_key' => '20000000-0000-4000-8000-000000000001',
        ])->assertSuccessful()->assertJsonPath('data.status', 'failed');

        $this->postJson("/api/charging-sessions/{$session->id}/payments", [
            'method' => 'simulated_edinar',
            'simulation_outcome' => 'success',
            'idempotency_key' => '20000000-0000-4000-8000-000000000002',
        ])->assertSuccessful()->assertJsonPath('data.status', 'paid');
    }

    public function test_manual_payment_is_rejected_when_the_session_has_an_unsettled_authorization(): void
    {
        [$client, $organization] = $this->userWithRole('client');
        [$station, $connector] = $this->stationWithConnector($organization, 'CT-PAYMENT-AUTHORIZED');
        $session = $this->completedSession($client, $station, $connector, 'SES-PAYMENT-AUTHORIZED');
        $this->authorizedAttempt($session, '50000000-0000-4000-8000-000000000001');
        Sanctum::actingAs($client);

        $this->postJson("/api/charging-sessions/{$session->id}/payments", [
            'method' => 'simulated_card',
            'simulation_outcome' => 'success',
            'idempotency_key' => '50000000-0000-4000-8000-000000000002',
        ])->assertUnprocessable()->assertJsonValidationErrors('session');

        $this->assertDatabaseCount('payments', 0);
        $this->assertSame('authorized', $session->fresh()->payment_status);
    }

    public function test_duplicate_request_reuses_the_pending_session_payment_without_charging_again(): void
    {
        [$client, $organization] = $this->userWithRole('client');
        [$station, $connector] = $this->stationWithConnector($organization, 'CT-PAYMENT-PENDING');
        $session = $this->completedSession($client, $station, $connector, 'SES-PAYMENT-PENDING');
        $key = '60000000-0000-4000-8000-000000000001';
        $this->pendingPayment($session, $client, $key);
        Sanctum::actingAs($client);

        $this->postJson("/api/charging-sessions/{$session->id}/payments", [
            'method' => 'simulated_card',
            'simulation_outcome' => 'success',
            'idempotency_key' => $key,
        ])->assertOk()
            ->assertJsonPath('data.status', 'pending');

        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseHas('payments', [
            'charging_session_id' => $session->id,
            'status' => 'pending',
            'provider_transaction_id' => null,
            'idempotency_key' => $key,
        ]);
    }

    public function test_parallel_payment_with_another_key_is_rejected_while_settlement_is_pending(): void
    {
        [$client, $organization] = $this->userWithRole('client');
        [$station, $connector] = $this->stationWithConnector($organization, 'CT-PAYMENT-CONCURRENT');
        $session = $this->completedSession($client, $station, $connector, 'SES-PAYMENT-CONCURRENT');
        $originalKey = '70000000-0000-4000-8000-000000000001';
        $this->pendingPayment($session, $client, $originalKey);
        Sanctum::actingAs($client);

        $this->postJson("/api/charging-sessions/{$session->id}/payments", [
            'method' => 'simulated_edinar',
            'simulation_outcome' => 'success',
            'idempotency_key' => '70000000-0000-4000-8000-000000000002',
        ])->assertUnprocessable()->assertJsonValidationErrors('payment');

        $this->assertDatabaseHas('payments', [
            'charging_session_id' => $session->id,
            'method' => 'simulated_card',
            'status' => 'pending',
            'idempotency_key' => $originalKey,
        ]);
    }

    public function test_duplicate_capture_reuses_the_pending_session_settlement(): void
    {
        [$client, $organization] = $this->userWithRole('client');
        [$station, $connector] = $this->stationWithConnector($organization, 'CT-CAPTURE-PENDING');
        $session = $this->completedSession($client, $station, $connector, 'SES-CAPTURE-PENDING');
        $captureKey = '80000000-0000-4000-8000-000000000001';
        $this->authorizedAttempt($session, $captureKey);
        $pending = $this->pendingPayment($session, $client, $captureKey);

        $payment = app(PaymentService::class)->captureAuthorized($session);

        $this->assertNotNull($payment);
        $this->assertSame($pending->id, $payment->id);
        $this->assertSame('pending', $payment->status);
        $this->assertNull($payment->provider_transaction_id);
        $this->assertSame('authorized', $session->fresh()->payment_status);
    }

    public function test_client_only_sees_their_sessions_while_operator_sees_the_organization(): void
    {
        $organization = $this->organization('scope-network');
        $client = $this->user(null, 'client');
        $otherClient = $this->user(null, 'client');
        $operator = $this->user($organization, 'operator');
        [$station, $connector] = $this->stationWithConnector($organization, 'CT-SCOPE-001');
        $this->completedSession($client, $station, $connector, 'SES-SCOPE-ONE');
        $this->completedSession($otherClient, $station, $connector, 'SES-SCOPE-TWO');

        Sanctum::actingAs($client);
        $this->getJson('/api/charging-sessions')->assertOk()->assertJsonCount(1, 'data');

        Sanctum::actingAs($operator);
        $this->getJson('/api/charging-sessions')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_sessions_and_payments_cannot_be_accessed_across_clients_or_organizations(): void
    {
        $firstOrganization = $this->organization('first-isolation-network');
        $secondOrganization = $this->organization('second-isolation-network');
        $client = $this->user(null, 'client');
        $otherClient = $this->user(null, 'client');
        $operator = $this->user($firstOrganization, 'operator');
        [$station, $connector] = $this->stationWithConnector($secondOrganization, 'CT-ISOLATED-SESSION');
        $session = $this->completedSession($otherClient, $station, $connector, 'SES-ISOLATED');

        Sanctum::actingAs($client);
        $this->getJson("/api/charging-sessions/{$session->id}")->assertForbidden();
        $this->postJson("/api/charging-sessions/{$session->id}/stop")->assertForbidden();
        $this->postJson("/api/charging-sessions/{$session->id}/payments", [
            'method' => 'simulated_card',
            'simulation_outcome' => 'success',
            'idempotency_key' => '30000000-0000-4000-8000-000000000001',
        ])->assertForbidden();

        Sanctum::actingAs($operator);
        $this->getJson("/api/charging-sessions/{$session->id}")->assertForbidden();
        $this->getJson('/api/charging-sessions')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/payments')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_admin_can_view_and_export_only_their_organization_sessions_and_payments(): void
    {
        $organization = $this->organization('admin-finance-network');
        $otherOrganization = $this->organization('external-finance-network');
        $admin = $this->user($organization, 'admin');
        $client = $this->user(null, 'client');
        $otherClient = $this->user(null, 'client');
        [$station, $connector] = $this->stationWithConnector($organization, 'CT-ADMIN-FINANCE');
        [$otherStation, $otherConnector] = $this->stationWithConnector($otherOrganization, 'CT-OTHER-FINANCE');
        $session = $this->completedSession($client, $station, $connector, 'SES-ADMIN-VISIBLE');
        $otherSession = $this->completedSession($otherClient, $otherStation, $otherConnector, 'SES-ADMIN-HIDDEN');
        $this->payment($session, $client, 'PAY-ADMIN-VISIBLE', '40000000-0000-4000-8000-000000000001');
        $this->payment($otherSession, $otherClient, 'PAY-ADMIN-HIDDEN', '40000000-0000-4000-8000-000000000002');
        Sanctum::actingAs($admin);

        $this->getJson('/api/charging-sessions')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reference', 'SES-ADMIN-VISIBLE');
        $this->getJson("/api/charging-sessions/{$otherSession->id}")->assertForbidden();
        $this->postJson("/api/charging-sessions/{$session->id}/remote-stop")->assertForbidden();

        $this->getJson('/api/payments')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reference', 'PAY-ADMIN-VISIBLE');

        $this->getJson('/api/charging-sessions/export?format=json')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reference', 'SES-ADMIN-VISIBLE')
            ->assertJsonMissing(['reference' => 'SES-ADMIN-HIDDEN']);
        $this->getJson('/api/payments/export?format=json')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reference', 'PAY-ADMIN-VISIBLE')
            ->assertJsonMissing(['reference' => 'PAY-ADMIN-HIDDEN']);

        Sanctum::actingAs($client);
        $this->getJson('/api/charging-sessions/export?format=json')->assertForbidden();
        $this->getJson('/api/payments/export?format=json')->assertForbidden();
    }

    /** @return array{User, Organization} */
    private function userWithRole(string $role): array
    {
        $organization = $this->organization($role.'-'.uniqid());

        return [$this->user($role === 'client' ? null : $organization, $role), $organization];
    }

    private function organization(string $slug): Organization
    {
        return Organization::query()->create(['name' => ucfirst($slug), 'slug' => $slug, 'status' => 'active']);
    }

    private function user(?Organization $organization, string $role): User
    {
        $user = User::factory()->create(['organization_id' => $organization?->id, 'status' => 'active']);
        $user->assignRole($role);

        return $user;
    }

    /** @return array{Station, Connector} */
    private function stationWithConnector(Organization $organization, string $reference = 'CT-SESSION-001'): array
    {
        $station = Station::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Session Test Station',
            'reference' => $reference,
            'location_name' => 'Lac 1',
            'city' => 'Tunis',
            'address' => 'Test address',
            'latitude' => 36.8,
            'longitude' => 10.2,
            'status' => 'available',
            'max_power_kw' => 120,
            'model' => 'Test Model',
            'manufacturer' => 'Test Manufacturer',
            'ocpp_version' => 'OCPP 1.6J',
        ]);
        $connector = Connector::query()->create([
            'station_id' => $station->id,
            'external_id' => 'A1',
            'type' => 'CCS2',
            'current_type' => 'DC',
            'max_power_kw' => 120,
            'status' => 'available',
        ]);

        return [$station, $connector];
    }

    private function completedSession(User $client, Station $station, Connector $connector, string $reference = 'SES-PAYMENT-001'): ChargingSession
    {
        return ChargingSession::query()->create([
            'organization_id' => $station->organization_id,
            'client_id' => $client->id,
            'station_id' => $station->id,
            'connector_id' => $connector->id,
            'reference' => $reference,
            'client_name' => $client->name,
            'station_name' => $station->name,
            'connector_external_id' => $connector->external_id,
            'status' => 'completed',
            'payment_status' => 'unpaid',
            'started_at' => now()->subMinutes(30),
            'ended_at' => now(),
            'duration_seconds' => 1800,
            'meter_start_kwh' => 100,
            'meter_stop_kwh' => 110,
            'energy_kwh' => 10,
            'price_per_kwh_millimes' => 850,
            'session_fee_millimes' => 500,
            'total_millimes' => 9000,
            'currency' => 'TND',
        ]);
    }

    private function payment(ChargingSession $session, User $client, string $reference, string $idempotencyKey): Payment
    {
        return Payment::query()->create([
            'organization_id' => $session->organization_id,
            'user_id' => $client->id,
            'charging_session_id' => $session->id,
            'reference' => $reference,
            'provider' => 'simulated',
            'method' => 'simulated_card',
            'status' => 'paid',
            'amount_millimes' => $session->total_millimes,
            'currency' => $session->currency,
            'idempotency_key' => $idempotencyKey,
            'provider_transaction_id' => 'SIM-'.$reference,
            'paid_at' => now(),
        ]);
    }

    private function pendingPayment(ChargingSession $session, User $client, string $idempotencyKey): Payment
    {
        return Payment::query()->create([
            'organization_id' => $session->organization_id,
            'user_id' => $client->id,
            'charging_session_id' => $session->id,
            'reference' => 'PAY-PENDING-'.substr($idempotencyKey, 0, 8),
            'provider' => 'simulated',
            'method' => 'simulated_card',
            'status' => 'pending',
            'amount_millimes' => $session->total_millimes,
            'currency' => $session->currency,
            'idempotency_key' => $idempotencyKey,
        ]);
    }

    private function authorizedAttempt(ChargingSession $session, string $captureIdempotencyKey): ChargingAttempt
    {
        $session->update(['payment_status' => 'authorized']);

        return ChargingAttempt::query()->create([
            'uuid' => $captureIdempotencyKey,
            'organization_id' => $session->organization_id,
            'user_id' => $session->client_id,
            'station_id' => $session->station_id,
            'connector_id' => $session->connector_id,
            'charging_session_id' => $session->id,
            'status' => 'charging',
            'payment_provider' => 'simulated',
            'payment_method' => 'simulated_card',
            'payment_status' => 'authorized',
            'preauthorized_amount_millimes' => 30000,
            'currency' => $session->currency,
            'payment_idempotency_key' => str_replace('80000000', '81000000', $captureIdempotencyKey),
            'capture_idempotency_key' => $captureIdempotencyKey,
            'provider_authorization_id' => 'SIM-AUTH-'.substr($captureIdempotencyKey, 0, 8),
            'simulation_outcome' => 'success',
            'authorized_at' => now()->subMinutes(30),
            'started_at' => $session->started_at,
            'expires_at' => now()->addHour(),
        ]);
    }
}
