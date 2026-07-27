<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Services\OrganizationBillingService;
use App\Services\PlatformAuditService;
use App\Services\Reports\ReportExportService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

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

    public function bulkStatus(Request $request, PlatformAuditService $audit): JsonResponse
    {
        $this->authorizeSuperAdmin($request);
        $attributes = $request->validate([
            'organization_ids' => ['required', 'array', 'min:1', 'max:100'],
            'organization_ids.*' => ['required', 'integer', 'distinct', 'exists:organizations,id'],
            'status' => ['required', Rule::in(['active', 'suspended'])],
        ]);

        $updatedIds = DB::transaction(function () use ($attributes, $audit, $request): array {
            $organizations = Organization::query()
                ->whereKey($attributes['organization_ids'])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $updatedIds = [];

            foreach ($organizations as $organization) {
                if ($organization->status === $attributes['status']) {
                    continue;
                }

                $previousStatus = $organization->status;
                $organization->update(['status' => $attributes['status']]);
                $audit->record(
                    $request->user(),
                    'organization.bulk_status_updated',
                    $organization,
                    "Changed organization {$organization->name} status from {$previousStatus} to {$attributes['status']}.",
                    [
                        'previous_status' => $previousStatus,
                        'new_status' => $attributes['status'],
                        'bulk_operation' => true,
                    ],
                );
                $updatedIds[] = $organization->id;
            }

            return $updatedIds;
        });

        return response()->json([
            'data' => [
                'updated' => count($updatedIds),
                'organization_ids' => $updatedIds,
                'status' => $attributes['status'],
            ],
        ]);
    }

    public function export(Request $request, ReportExportService $exports): Response
    {
        $this->authorizeSuperAdmin($request);
        $attributes = $request->validate([
            'format' => ['nullable', Rule::in(['csv', 'json', 'pdf'])],
            'organization_ids' => ['required', 'array', 'min:1', 'max:100'],
            'organization_ids.*' => ['required', 'integer', 'distinct', 'exists:organizations,id'],
        ]);
        $format = $attributes['format'] ?? 'csv';
        $organizations = Organization::query()
            ->whereKey($attributes['organization_ids'])
            ->withCount(['users', 'stations', 'chargingSessions'])
            ->withSum(['payments as settled_revenue_millimes' => fn (Builder $query) => $query->where('status', 'paid')], 'amount_millimes')
            ->orderBy('name')
            ->get();

        return $exports->dataset(
            $format,
            'selected-organizations',
            'Selected organizations',
            'Tenant access, network capacity and recorded charging activity.',
            [
                'name' => 'Organization',
                'slug' => 'Slug',
                'contact_email' => 'Contact email',
                'contact_phone' => 'Contact phone',
                'status' => 'Status',
                'users' => 'Users',
                'stations' => 'Stations',
                'sessions' => 'Sessions',
                'settled_revenue_tnd' => 'Settled revenue (TND)',
                'created_at' => 'Created',
            ],
            $organizations->map(fn (Organization $organization) => [
                'name' => $organization->name,
                'slug' => $organization->slug,
                'contact_email' => $organization->contact_email,
                'contact_phone' => $organization->contact_phone,
                'status' => $organization->status,
                'users' => (int) $organization->users_count,
                'stations' => (int) $organization->stations_count,
                'sessions' => (int) $organization->charging_sessions_count,
                'settled_revenue_tnd' => number_format(((int) $organization->settled_revenue_millimes) / 1000, 3, '.', ''),
                'created_at' => $organization->created_at?->toIso8601String(),
            ]),
            $request->user(),
            ['organization_ids' => $attributes['organization_ids']],
        );
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
