<?php

namespace Database\Seeders;

use App\Models\ChargingPlan;
use App\Models\Organization;
use Illuminate\Database\Seeder;

class ChargingPlanSeeder extends Seeder
{
    public function run(): void
    {
        $organization = Organization::query()->where('slug', 'tunis-network-ops')->first();
        if (! $organization) {
            return;
        }

        $plans = [
            ['name' => 'Pay as you go', 'code' => 'PAYG', 'monthly_fee_millimes' => 0, 'discount_basis_points' => 0, 'audience' => 'Public clients', 'status' => 'active', 'member_count' => 2140],
            ['name' => 'Member Plan', 'code' => 'MEMBER', 'monthly_fee_millimes' => 19000, 'discount_basis_points' => 800, 'audience' => 'Frequent drivers', 'status' => 'active', 'member_count' => 368],
            ['name' => 'Fleet Plan', 'code' => 'FLEET', 'monthly_fee_millimes' => 149000, 'discount_basis_points' => 1800, 'audience' => 'Fleet accounts', 'status' => 'active', 'member_count' => 42],
            ['name' => 'Staff / Internal Plan', 'code' => 'STAFF', 'monthly_fee_millimes' => 0, 'discount_basis_points' => 4500, 'audience' => 'Internal users', 'status' => 'draft', 'member_count' => 21],
        ];

        foreach ($plans as $plan) {
            ChargingPlan::query()->updateOrCreate(
                ['organization_id' => $organization->id, 'code' => $plan['code']],
                [...$plan, 'organization_id' => $organization->id],
            );
        }
    }
}
