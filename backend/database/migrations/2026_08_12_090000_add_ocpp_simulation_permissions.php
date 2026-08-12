<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /** @var list<string> */
    private const PERMISSIONS = [
        'ocpp_simulation.view',
        'ocpp_simulation.diagnose',
        'ocpp_simulation.control',
    ];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $this->grant(['super_admin', 'admin', 'operator'], self::PERMISSIONS);
        $this->grant(['technician'], [
            'ocpp_simulation.view',
            'ocpp_simulation.diagnose',
        ]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::query()
            ->where('guard_name', 'web')
            ->whereIn('name', self::PERMISSIONS)
            ->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** @param list<string> $roles
     * @param  list<string>  $permissions
     */
    private function grant(array $roles, array $permissions): void
    {
        Role::query()
            ->where('guard_name', 'web')
            ->whereIn('name', $roles)
            ->each(fn (Role $role) => $role->givePermissionTo($permissions));
    }
};
