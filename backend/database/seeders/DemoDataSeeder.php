<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoDataSeeder extends Seeder
{
    /**
     * Seed local demo data.
     */
    public function run(): void
    {
        $organization = Organization::firstOrCreate(
            ['slug' => 'tunis-network-ops'],
            [
                'name' => 'Tunis Network Ops',
                'contact_email' => 'ops@chargetrackr.local',
                'contact_phone' => '+216 00 000 000',
                'status' => 'active',
            ],
        );

        $users = [
            ['name' => 'Meriem Haddad', 'email' => 'superadmin@chargetrackr.local', 'role' => 'super_admin', 'organization_id' => null],
            ['name' => 'Sami Ben Amor', 'email' => 'admin@chargetrackr.local', 'role' => 'admin', 'organization_id' => $organization->id],
            ['name' => 'Meriem Haddad', 'email' => 'operator@chargetrackr.local', 'role' => 'operator', 'organization_id' => $organization->id],
            ['name' => 'Nour Trabelsi', 'email' => 'technician@chargetrackr.local', 'role' => 'technician', 'organization_id' => $organization->id],
            ['name' => 'Yasmine B.', 'email' => 'client@chargetrackr.local', 'role' => 'client', 'organization_id' => $organization->id],
        ];

        foreach ($users as $userData) {
            $role = $userData['role'];
            unset($userData['role']);

            $user = User::updateOrCreate(
                ['email' => $userData['email']],
                [
                    ...$userData,
                    'password' => Hash::make('password'),
                    'status' => 'active',
                    'email_verified_at' => now(),
                ],
            );

            $user->syncRoles([$role]);
        }
    }
}
