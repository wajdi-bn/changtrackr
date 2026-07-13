<?php

namespace Database\Seeders;

use App\Models\ChargingPlan;
use App\Models\Organization;
use Illuminate\Database\Seeder;

class ChargingPlanSeeder extends Seeder
{
    public function run(): void
    {
        $catalogs = [
            'tunis-network-ops' => [
                ['name' => 'Pay as you go', 'code' => 'PAYG', 'description' => 'Charge without a recurring commitment.', 'monthly_fee_millimes' => 0, 'discount_basis_points' => 0, 'audience' => 'Public clients', 'status' => 'active'],
                ['name' => 'Member Plan', 'code' => 'MEMBER', 'description' => 'A monthly discount for frequent urban charging.', 'monthly_fee_millimes' => 19000, 'discount_basis_points' => 800, 'audience' => 'Frequent drivers', 'status' => 'active'],
                ['name' => 'Fleet Plan', 'code' => 'FLEET', 'description' => 'Higher savings for professional fleet accounts.', 'monthly_fee_millimes' => 149000, 'discount_basis_points' => 1800, 'audience' => 'Fleet accounts', 'status' => 'active'],
                ['name' => 'Staff / Internal Plan', 'code' => 'STAFF', 'description' => 'Internal plan kept outside the public catalog.', 'monthly_fee_millimes' => 0, 'discount_basis_points' => 4500, 'audience' => 'Internal users', 'status' => 'draft'],
            ],
            'sahel-charge-network' => [
                ['name' => 'Coast Flex', 'code' => 'COAST-FLEX', 'description' => 'No monthly fee for occasional coastal trips.', 'monthly_fee_millimes' => 0, 'discount_basis_points' => 0, 'audience' => 'Occasional drivers', 'status' => 'active'],
                ['name' => 'Coast Saver', 'code' => 'COAST-SAVER', 'description' => 'Monthly savings across the Sahel charging network.', 'monthly_fee_millimes' => 12000, 'discount_basis_points' => 600, 'audience' => 'Regular Sahel drivers', 'status' => 'active'],
            ],
        ];

        foreach ($catalogs as $slug => $plans) {
            $organization = Organization::query()->where('slug', $slug)->first();
            if (! $organization) {
                continue;
            }
            foreach ($plans as $plan) {
                ChargingPlan::query()->updateOrCreate(
                    ['organization_id' => $organization->id, 'code' => $plan['code']],
                    [...$plan, 'organization_id' => $organization->id, 'member_count' => 0],
                );
            }
        }
    }
}
