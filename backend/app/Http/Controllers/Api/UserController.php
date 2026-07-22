<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Users\StoreUserRequest;
use App\Http\Requests\Users\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Notifications\AccountInvitationNotification;
use App\Services\AccountInvitationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class UserController extends Controller
{
    private const RELATIONS = ['organization', 'roles.permissions', 'permissions', 'latestAccountInvitation'];

    private const COUNTS = ['assignedAlerts', 'assignedInterventions', 'chargingSessions', 'payments'];

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', User::class);
        $filters = $this->validateFilters($request);
        /** @var User $actor */
        $actor = $request->user();
        $scope = $this->scopedQuery($actor);
        $summary = clone $scope;
        $users = $this->applyFilters($scope, $filters)
            ->with(self::RELATIONS)
            ->withCount(self::COUNTS)
            ->orderBy('name')
            ->paginate($filters['per_page'] ?? 20)
            ->withQueryString();

        return response()->json([
            'data' => UserResource::collection($users->items()),
            'summary' => [
                'total' => (clone $summary)->count(),
                'active' => (clone $summary)->where('status', 'active')->count(),
                'inactive' => (clone $summary)->where('status', 'inactive')->count(),
                'pending' => (clone $summary)->where('status', 'pending')->count(),
                'by_role' => $this->roleSummary(clone $summary),
            ],
            'meta' => [
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
            ],
        ]);
    }

    public function store(StoreUserRequest $request, AccountInvitationService $invitations): JsonResponse
    {
        Gate::authorize('create', User::class);
        /** @var User $actor */
        $actor = $request->user();
        $attributes = $request->validated();
        $this->assertRoleCanBeAssigned($actor, $attributes['role']);

        if (! $actor->hasRole('super_admin')) {
            $actor->loadMissing('organization');
            $result = $invitations->invite(
                $actor->organization,
                $actor,
                $attributes['name'],
                $attributes['email'],
                $attributes['role'],
                null,
                $attributes,
            );
            $result['user']->notify(new AccountInvitationNotification($result['invitation'], $result['token']));

            return (new UserResource($this->loadUser($result['user'])))
                ->response()
                ->setStatusCode(201);
        }

        $role = $attributes['role'];
        unset($attributes['role']);
        $attributes['organization_id'] = $role === 'super_admin'
            ? null
            : ($actor->hasRole('super_admin') ? ($attributes['organization_id'] ?? null) : $actor->organization_id);
        $this->assertOrganizationAssignment($role, $attributes['organization_id']);
        $attributes['team'] ??= $this->defaultTeam($role);

        $user = User::query()->create($attributes);
        $user->syncRoles([$role]);

        return (new UserResource($this->loadUser($user)))
            ->response()
            ->setStatusCode(201);
    }

    public function show(User $user): UserResource
    {
        Gate::authorize('view', $user);

        return new UserResource($this->loadUser($user));
    }

    public function update(UpdateUserRequest $request, User $user): UserResource
    {
        Gate::authorize('update', $user);
        /** @var User $actor */
        $actor = $request->user();
        $attributes = $request->validated();
        $currentRole = $user->primaryRoleName();
        if ($actor->is($user) && (
            (isset($attributes['status']) && $attributes['status'] !== 'active')
            || (isset($attributes['role']) && $attributes['role'] !== $currentRole)
        )) {
            throw ValidationException::withMessages(['user' => ['You cannot deactivate or remove your own administrator role.']]);
        }
        if (isset($attributes['role'])) {
            $this->assertRoleCanBeAssigned($actor, $attributes['role']);
        }
        $role = $attributes['role'] ?? null;
        unset($attributes['role']);
        $nextRole = $role ?? $currentRole;
        $latestInvitation = $user->latestAccountInvitation()->first();
        if (! $actor->hasRole('super_admin') && $user->status === 'pending') {
            if (isset($attributes['status']) && $attributes['status'] !== 'pending') {
                throw ValidationException::withMessages([
                    'status' => ['A pending employee must activate the account from the invitation link.'],
                ]);
            }
            if ($latestInvitation?->effectiveStatus() === 'pending' && (
                (isset($attributes['email']) && mb_strtolower($attributes['email']) !== mb_strtolower($user->email))
                || ($role !== null && $role !== $currentRole)
            )) {
                throw ValidationException::withMessages([
                    'invitation' => ['Cancel the pending invitation before changing its email or role.'],
                ]);
            }
        }
        $organizationId = $nextRole === 'super_admin'
            ? null
            : ($actor->hasRole('super_admin')
                ? (array_key_exists('organization_id', $attributes) ? $attributes['organization_id'] : $user->organization_id)
                : $user->organization_id);
        unset($attributes['organization_id']);
        $this->assertOrganizationAssignment($nextRole, $organizationId);
        $attributes['organization_id'] = $organizationId;
        if (($attributes['status'] ?? null) === 'inactive') {
            $this->assertCanDeactivate($user);
        }

        $user->update($attributes);
        if ($role) {
            $user->syncRoles([$role]);
        }
        if ($user->status === 'inactive') {
            $user->tokens()->delete();
        }

        return new UserResource($this->loadUser($user->refresh()));
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        Gate::authorize('delete', $user);
        /** @var User $actor */
        $actor = $request->user();
        if ($actor->is($user)) {
            throw ValidationException::withMessages(['user' => ['You cannot deactivate your own account.']]);
        }
        $this->assertCanDeactivate($user);
        $user->update(['status' => 'inactive']);
        $user->tokens()->delete();

        return response()->json(['data' => new UserResource($this->loadUser($user->refresh()))]);
    }

    public function export(Request $request): JsonResponse|StreamedResponse
    {
        Gate::authorize('viewAny', User::class);
        $filters = $this->validateFilters($request);
        $format = $request->validate(['format' => ['required', Rule::in(['csv', 'json'])]])['format'];
        /** @var User $actor */
        $actor = $request->user();
        $users = $this->applyFilters($this->scopedQuery($actor), $filters)
            ->with(['roles', 'organization'])
            ->orderBy('name')
            ->get();

        if ($format === 'json') {
            return response()->json(['data' => $users->map(fn (User $user) => $this->exportRow($user))]);
        }

        return response()->streamDownload(function () use ($users): void {
            $output = fopen('php://output', 'w');
            if ($output === false) {
                return;
            }
            fputcsv($output, ['Name', 'Email', 'Phone', 'Role', 'Organization', 'Team', 'Status', 'Last login']);
            foreach ($users as $user) {
                fputcsv($output, array_values($this->exportRow($user)));
            }
            fclose($output);
        }, 'organization-employees.csv', ['Content-Type' => 'text/csv']);
    }

    /** @return array<string, mixed> */
    private function validateFilters(Request $request): array
    {
        return $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'role' => ['nullable', Rule::in(User::EMPLOYEE_ROLES)],
            'organization_id' => ['nullable', 'integer', 'exists:organizations,id'],
            'status' => ['nullable', Rule::in(['active', 'inactive', 'pending', 'expired', 'revoked'])],
            'team' => ['nullable', 'string', 'max:120'],
            'last_login' => ['nullable', Rule::in(['today', 'week', 'month'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
    }

    private function scopedQuery(User $actor): Builder
    {
        return User::query()
            ->whereHas('roles', fn (Builder $query) => $query->whereIn('name', User::EMPLOYEE_ROLES))
            ->when(
                ! $actor->hasRole('super_admin'),
                fn (Builder $query) => $query->where('organization_id', $actor->organization_id),
            );
    }

    /** @param array<string, mixed> $filters */
    private function applyFilters(Builder $query, array $filters): Builder
    {
        return $query
            ->when($filters['search'] ?? null, function (Builder $query, string $search): void {
                $needle = '%'.mb_strtolower($search).'%';
                $query->where(fn (Builder $query) => $query
                    ->whereRaw('LOWER(name) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(email) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(team) LIKE ?', [$needle]));
            })
            ->when($filters['role'] ?? null, fn (Builder $query, string $role) => $query->whereHas('roles', fn (Builder $query) => $query->where('name', $role)))
            ->when(array_key_exists('organization_id', $filters), fn (Builder $query) => $query->where('organization_id', $filters['organization_id']))
            ->when($filters['status'] ?? null, function (Builder $query, string $status): void {
                if (in_array($status, ['active', 'inactive'], true)) {
                    $query->where('status', $status);

                    return;
                }

                $query->where('status', 'pending')->whereHas('latestAccountInvitation', function (Builder $query) use ($status): void {
                    match ($status) {
                        'pending' => $query->where('status', 'pending')->where('expires_at', '>=', now()),
                        'expired' => $query->where(fn (Builder $query) => $query
                            ->where('status', 'expired')
                            ->orWhere(fn (Builder $query) => $query->where('status', 'pending')->where('expires_at', '<', now()))),
                        'revoked' => $query->where('status', 'revoked'),
                    };
                });
            })
            ->when($filters['team'] ?? null, fn (Builder $query, string $team) => $query->where('team', $team))
            ->when($filters['last_login'] ?? null, function (Builder $query, string $period): void {
                $start = match ($period) {
                    'today' => now()->startOfDay(),
                    'week' => now()->subWeek(),
                    'month' => now()->subMonth(),
                };
                $query->where('last_login_at', '>=', $start);
            });
    }

    /** @return array<string, int> */
    private function roleSummary(Builder $query): array
    {
        return collect(User::EMPLOYEE_ROLES)
            ->mapWithKeys(fn (string $role) => [$role => (clone $query)->whereHas('roles', fn (Builder $query) => $query->where('name', $role))->count()])
            ->all();
    }

    private function loadUser(User $user): User
    {
        return $user->load(self::RELATIONS)->loadCount(self::COUNTS);
    }

    private function assertRoleCanBeAssigned(User $actor, string $role): void
    {
        $allowedRoles = $actor->hasRole('super_admin')
            ? User::EMPLOYEE_ROLES
            : ['operator', 'technician'];

        if (! in_array($role, $allowedRoles, true)) {
            throw ValidationException::withMessages(['role' => ['You cannot assign this role.']]);
        }
    }

    private function assertOrganizationAssignment(?string $role, ?int $organizationId): void
    {
        if ($role === null) {
            throw ValidationException::withMessages(['role' => ['The employee must have exactly one role.']]);
        }

        if ($role === 'super_admin' && $organizationId !== null) {
            throw ValidationException::withMessages(['organization_id' => ['Super administrators cannot belong to an organization.']]);
        }

        if (in_array($role, User::ORGANIZATION_ROLES, true) && $organizationId === null) {
            throw ValidationException::withMessages(['organization_id' => ['Organization employees must belong to exactly one organization.']]);
        }
    }

    private function assertCanDeactivate(User $user): void
    {
        if (! $user->hasRole('admin') || $user->status !== 'active') {
            return;
        }
        $activeAdministrators = User::query()
            ->where('organization_id', $user->organization_id)
            ->where('status', 'active')
            ->whereHas('roles', fn (Builder $query) => $query->where('name', 'admin'))
            ->count();
        if ($activeAdministrators <= 1) {
            throw ValidationException::withMessages(['user' => ['The last active organization administrator cannot be deactivated.']]);
        }
    }

    private function defaultTeam(string $role): string
    {
        return match ($role) {
            'admin' => 'Management',
            'operator' => 'Network Operations',
            'technician' => 'Field Maintenance',
            default => 'Platform Administration',
        };
    }

    /** @return array<string, mixed> */
    private function exportRow(User $user): array
    {
        return [
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'role' => $user->getRoleNames()->first(),
            'organization' => $user->organization?->name,
            'team' => $user->team,
            'status' => $user->status,
            'last_login_at' => $user->last_login_at?->toISOString(),
        ];
    }
}
