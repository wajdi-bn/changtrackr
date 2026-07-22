<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PlatformAuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolePermissionController extends Controller
{
    /** @var array<string, array{label: string, description: string, boundary: string, immutable: bool}> */
    private const ROLE_METADATA = [
        'super_admin' => [
            'label' => 'Super Administrator',
            'description' => 'Governs organizations, elevated accounts, platform services and audit history.',
            'boundary' => 'Platform-wide',
            'immutable' => true,
        ],
        'admin' => [
            'label' => 'Organization Administrator',
            'description' => 'Manages employees, customers, charging assets, pricing and reporting for one organization.',
            'boundary' => 'One organization',
            'immutable' => false,
        ],
        'operator' => [
            'label' => 'Network Operator',
            'description' => 'Supervises stations, sessions, alerts, maintenance and day-to-day charging operations.',
            'boundary' => 'One organization',
            'immutable' => false,
        ],
        'technician' => [
            'label' => 'Field Technician',
            'description' => 'Consults assigned assets and completes alert, intervention and maintenance work.',
            'boundary' => 'One organization',
            'immutable' => false,
        ],
        'client' => [
            'label' => 'Client',
            'description' => 'Finds stations and manages personal charging sessions, payments, vehicles and subscriptions.',
            'boundary' => 'Personal account',
            'immutable' => false,
        ],
    ];

    public function index(Request $request): JsonResponse
    {
        $this->authorizeView($request);

        $permissions = Permission::query()
            ->where('guard_name', 'web')
            ->orderBy('name')
            ->get();
        $roles = Role::query()
            ->whereIn('name', array_keys(self::ROLE_METADATA))
            ->with('permissions')
            ->get()
            ->sortBy(fn (Role $role) => array_search($role->name, array_keys(self::ROLE_METADATA), true));
        $assignmentCounts = DB::table(config('permission.table_names.model_has_roles'))
            ->selectRaw('role_id, COUNT(*) as aggregate')
            ->groupBy('role_id')
            ->pluck('aggregate', 'role_id');
        $roles->each(fn (Role $role) => $role->setAttribute('users_count', (int) ($assignmentCounts[$role->id] ?? 0)));

        return response()->json([
            'data' => [
                'roles' => $roles->map(fn (Role $role) => $this->rolePayload($role))->values(),
                'permissions' => $permissions->map(fn (Permission $permission) => $this->permissionPayload($permission))->values(),
            ],
            'summary' => [
                'roles' => $roles->count(),
                'permissions' => $permissions->count(),
                'assignments' => $roles->sum('users_count'),
                'editable_roles' => $roles->filter(fn (Role $role) => ! self::ROLE_METADATA[$role->name]['immutable'])->count(),
            ],
        ]);
    }

    public function update(Request $request, string $roleName, PlatformAuditService $audit): JsonResponse
    {
        $this->authorizeManage($request);
        if (! isset(self::ROLE_METADATA[$roleName])) {
            abort(404);
        }
        if (self::ROLE_METADATA[$roleName]['immutable']) {
            throw ValidationException::withMessages([
                'role' => ['The Super Administrator permission set is protected and cannot be changed.'],
            ]);
        }

        $attributes = $request->validate([
            'permissions' => ['required', 'array'],
            'permissions.*' => [
                'string',
                'distinct',
                Rule::exists('permissions', 'name')->where('guard_name', 'web'),
            ],
        ]);
        $role = Role::query()->where('guard_name', 'web')->where('name', $roleName)->firstOrFail();
        $before = $role->permissions()->pluck('name')->sort()->values();
        $after = collect($attributes['permissions'])->sort()->values();

        $role->syncPermissions($after->all());
        $audit->record(
            $request->user(),
            'role.permissions_updated',
            $role,
            'Updated permissions for '.self::ROLE_METADATA[$roleName]['label'].'.',
            [
                'changed_fields' => ['permissions'],
                'added_permissions' => $after->diff($before)->values()->all(),
                'removed_permissions' => $before->diff($after)->values()->all(),
            ],
        );

        $role = $role->fresh(['permissions']);
        $role->setAttribute('users_count', (int) DB::table(config('permission.table_names.model_has_roles'))->where('role_id', $role->id)->count());

        return response()->json(['data' => $this->rolePayload($role)]);
    }

    /** @return array<string, mixed> */
    private function rolePayload(Role $role): array
    {
        $metadata = self::ROLE_METADATA[$role->name];

        return [
            'name' => $role->name,
            'label' => $metadata['label'],
            'description' => $metadata['description'],
            'boundary' => $metadata['boundary'],
            'immutable' => $metadata['immutable'],
            'users_count' => (int) ($role->users_count ?? 0),
            'permissions' => $role->permissions->pluck('name')->sort()->values(),
        ];
    }

    /** @return array<string, string> */
    private function permissionPayload(Permission $permission): array
    {
        [$module, $action] = array_pad(explode('.', $permission->name, 2), 2, 'access');

        return [
            'name' => $permission->name,
            'module' => $module,
            'action' => $action,
            'label' => str($action)->replace('_', ' ')->headline()->toString(),
        ];
    }

    private function authorizeView(Request $request): void
    {
        abort_unless($request->user()?->hasRole('super_admin') && $request->user()->can('roles.view'), 403);
    }

    private function authorizeManage(Request $request): void
    {
        abort_unless($request->user()?->hasRole('super_admin') && $request->user()->can('roles.manage'), 403);
    }
}
