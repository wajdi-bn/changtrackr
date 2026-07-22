<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\SaasPlan;
use Illuminate\Database\Seeder;

class SaasPlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'name' => 'Starter', 'code' => 'STARTER',
                'description' => 'Essential supervision for a small charging network.',
                'monthly_price_millimes' => 149000, 'annual_price_millimes' => 1490000,
                'max_stations' => 5, 'max_employees' => 5, 'sort_order' => 10,
                'features' => ['Live station monitoring', 'Alerts and interventions', 'Standard reports'],
            ],
            [
                'name' => 'Business', 'code' => 'BUSINESS',
                'description' => 'Operations, maintenance and analytics for a growing network.',
                'monthly_price_millimes' => 399000, 'annual_price_millimes' => 3990000,
                'max_stations' => 50, 'max_employees' => 25, 'sort_order' => 20, 'is_featured' => true,
                'features' => ['Everything in Starter', 'Remote OCPP operations', 'Advanced analytics and exports', 'Priority support'],
            ],
            [
                'name' => 'Enterprise', 'code' => 'ENTERPRISE',
                'description' => 'Governance and unlimited scale for large charging portfolios.',
                'monthly_price_millimes' => 999000, 'annual_price_millimes' => 9990000,
                'max_stations' => null, 'max_employees' => null, 'sort_order' => 30,
                'features' => ['Everything in Business', 'Unlimited stations and employees', 'Custom onboarding', 'Dedicated support'],
            ],
        ];

        foreach ($plans as $plan) {
            SaasPlan::query()->updateOrCreate(
                ['code' => $plan['code']],
                [...$plan, 'status' => 'active', 'is_featured' => $plan['is_featured'] ?? false],
            );
        }

        $businessPlan = SaasPlan::query()->where('code', 'BUSINESS')->firstOrFail();
        Organization::query()
            ->whereDoesntHave('commercialSubscription')
            ->each(fn (Organization $organization) => OrganizationSubscription::query()->create([
                'organization_id' => $organization->id,
                'saas_plan_id' => $businessPlan->id,
                'status' => 'active',
                'billing_cycle' => 'annual',
                'source' => 'legacy_backfill',
                'current_period_starts_at' => now(),
                'current_period_ends_at' => now()->addYear(),
            ]));
    }
}
