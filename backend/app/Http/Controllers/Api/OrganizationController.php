<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Services\OrganizationBillingService;
use App\Services\PlatformAuditService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class OrganizationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizeSuperAdmin($request);
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(['active', 'suspended'])],
        ]);
        $scope = Organization::query()
            ->withCount(['users', 'stations', 'chargingSessions'])
            ->withSum(['payments as settled_revenue_millimes' => fn (Builder $query) => $query->where('status', 'paid')], 'amount_millimes')
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['search'] ?? null, function (Builder $query, string $search): void {
                $needle = '%'.mb_strtolower($search).'%';
                $query->where(fn (Builder $query) => $query
                    ->whereRaw('LOWER(name) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(slug) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(contact_email) LIKE ?', [$needle]));
            });

        $organizations = $scope->orderBy('name')->get();

        return response()->json([
            'data' => $organizations->map(fn (Organization $organization) => $this->summary($organization))->values(),
            'summary' => [
                'total' => $organizations->count(),
                'active' => $organizations->where('status', 'active')->count(),
                'suspended' => $organizations->where('status', 'suspended')->count(),
            ],
        ]);
    }

    public function show(Request $request, Organization $organization): JsonResponse
    {
        $this->authorizeSuperAdmin($request);
        $organization->loadCount(['users', 'stations', 'chargingSessions', 'alerts as open_alerts_count' => fn (Builder $query) => $query->where('status', '!=', 'resolved')]);
        $organization->loadSum(['payments as settled_revenue_millimes' => fn (Builder $query) => $query->where('status', 'paid')], 'amount_millimes');
        $organization->load([
            'users' => fn ($query) => $query->with('roles')->orderBy('name')->limit(8),
            'stations' => fn ($query) => $query->orderBy('name')->limit(6),
        ]);

        return response()->json(['data' => [
            ...$this->summary($organization),
            'open_alerts_count' => $organization->open_alerts_count,
            'settings' => $organization->settings,
            'admins' => $organization->users
                ->filter(fn ($user) => $user->hasRole('admin'))
                ->map(fn ($user) => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'status' => $user->status])
                ->values(),
            'stations_preview' => $organization->stations
                ->map(fn ($station) => ['id' => $station->id, 'name' => $station->name, 'reference' => $station->reference, 'status' => $station->status])
                ->values(),
        ]]);
    }

    public function store(Request $request, PlatformAuditService $audit, OrganizationBillingService $billing): JsonResponse
    {
        $this->authorizeSuperAdmin($request);
        $attributes = $this->validatedAttributes($request);
        $organization = Organization::query()->create($attributes);
        $billing->createTrial($organization, $request->user());
        $audit->record($request->user(), 'organization.created', $organization, "Created organization {$organization->name}.");

        return response()->json(['data' => $this->summary($organization)], 201);
    }

    public function update(Request $request, Organization $organization, PlatformAuditService $audit): JsonResponse
    {
        $this->authorizeSuperAdmin($request);
        $attributes = $this->validatedAttributes($request, $organization);
        $changes = array_keys(array_filter($attributes, fn ($value, string $key) => $organization->getAttribute($key) !== $value, ARRAY_FILTER_USE_BOTH));
        $organization->update($attributes);
        if ($changes !== []) {
            $audit->record($request->user(), 'organization.updated', $organization, "Updated organization {$organization->name}.", ['changed_fields' => $changes]);
        }

        return response()->json(['data' => $this->summary($organization->fresh())]);
    }

    /** @return array<string, mixed> */
    private function validatedAttributes(Request $request, ?Organization $organization = null): array
    {
        $slugRule = Rule::unique('organizations', 'slug')->ignore($organization?->id);
        $emailRule = Rule::unique('organizations', 'contact_email')->ignore($organization?->id);
        $attributes = $request->validate([
            'name' => [$organization ? 'sometimes' : 'required', 'string', 'max:160'],
            'slug' => ['nullable', 'string', 'max:180', 'alpha_dash', $slugRule],
            'contact_email' => ['nullable', 'email', 'max:160', $emailRule],
            'contact_phone' => ['nullable', 'string', 'max:40'],
            'status' => ['sometimes', Rule::in(['active', 'suspended'])],
        ]);

        if (blank($attributes['slug'] ?? null) && isset($attributes['name'])) {
            $attributes['slug'] = $this->availableSlug($attributes['name'], $organization?->id);
        } elseif (isset($attributes['slug'])) {
            $attributes['slug'] = Str::slug($attributes['slug']);
        }

        return $attributes;
    }

    private function availableSlug(string $name, ?int $exceptOrganizationId = null): string
    {
        $base = Str::slug($name) ?: 'organization';
        $slug = $base;
        $suffix = 2;

        while (Organization::query()
            ->where('slug', $slug)
            ->when($exceptOrganizationId, fn (Builder $query, int $id) => $query->whereKeyNot($id))
            ->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }

    /** @return array<string, mixed> */
    private function summary(Organization $organization): array
    {
        return [
            'id' => $organization->id,
            'name' => $organization->name,
            'slug' => $organization->slug,
            'contact_email' => $organization->contact_email,
            'contact_phone' => $organization->contact_phone,
            'status' => $organization->status,
            'users_count' => (int) ($organization->users_count ?? 0),
            'stations_count' => (int) ($organization->stations_count ?? 0),
            'charging_sessions_count' => (int) ($organization->charging_sessions_count ?? 0),
            'settled_revenue_millimes' => (int) ($organization->settled_revenue_millimes ?? 0),
            'created_at' => $organization->created_at?->toIso8601String(),
        ];
    }

    private function authorizeSuperAdmin(Request $request): void
    {
        abort_unless($request->user()?->hasRole('super_admin'), 403);
    }
}
