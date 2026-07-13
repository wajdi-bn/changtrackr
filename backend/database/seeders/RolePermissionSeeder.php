<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionSeeder extends Seeder
{
    /**
     * Seed the application's roles and permissions.
     */
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = [
            'organizations.view',
            'organizations.manage',
            'users.view',
            'users.create',
            'users.update',
            'users.delete',
            'roles.view',
            'roles.manage',
            'stations.view',
            'stations.create',
            'stations.update',
            'stations.delete',
            'connectors.view',
            'connectors.manage',
            'sessions.view',
            'sessions.manage',
            'sessions.start',
            'sessions.stop',
            'alerts.view',
            'alerts.manage',
            'alerts.assign',
            'interventions.view',
            'interventions.manage',
            'interventions.report',
            'tariffs.view',
            'tariffs.manage',
            'payments.view',
            'payments.manage',
            'payments.pay',
            'vehicles.manage',
            'reports.view',
            'reports.export',
            'settings.manage',
            'audit.view',
            'integrations.manage',
        ];

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $rolePermissions = [
            'super_admin' => $permissions,
            'admin' => [
                'users.view',
                'users.create',
                'users.update',
                'users.delete',
                'roles.view',
                'stations.view',
                'tariffs.view',
                'tariffs.manage',
                'payments.view',
                'reports.view',
                'reports.export',
                'settings.manage',
            ],
            'operator' => [
                'stations.view',
                'stations.create',
                'stations.update',
                'stations.delete',
                'connectors.view',
                'connectors.manage',
                'sessions.view',
                'sessions.manage',
                'tariffs.view',
                'alerts.view',
                'alerts.manage',
                'alerts.assign',
                'interventions.view',
                'interventions.manage',
                'payments.view',
                'reports.view',
                'reports.export',
            ],
            'technician' => [
                'stations.view',
                'connectors.view',
                'alerts.view',
                'interventions.view',
                'interventions.report',
            ],
            'client' => [
                'stations.view',
                'sessions.view',
                'sessions.start',
                'sessions.stop',
                'payments.view',
                'payments.pay',
                'vehicles.manage',
            ],
        ];

        foreach ($rolePermissions as $roleName => $rolePermissionNames) {
            Role::findOrCreate($roleName, 'web')->syncPermissions($rolePermissionNames);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
