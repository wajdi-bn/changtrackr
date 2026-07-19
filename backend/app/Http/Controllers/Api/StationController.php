<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Stations\StoreStationRequest;
use App\Http\Requests\Stations\UpdateStationRequest;
use App\Http\Resources\StationMapResource;
use App\Http\Resources\StationResource;
use App\Models\Connector;
use App\Models\Station;
use App\Models\User;
use App\Services\Availability\AvailabilityProjectionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class StationController extends Controller
{
    private const STATUSES = ['available', 'charging', 'faulted', 'offline', 'maintenance', 'reserved', 'unavailable'];

    public function __construct(private readonly AvailabilityProjectionService $availabilityProjector) {}

    private const CONNECTOR_TYPES = ['CCS2', 'Type 2', 'CHAdeMO'];

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Station::class);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(self::STATUSES)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $scope = $this->visibleScope($user);

        $summaryQuery = clone $scope;
        $query = $scope
            ->with(['organization', 'connectors' => fn ($query) => $query->orderBy('external_id')])
            ->withCount('connectors')
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['search'] ?? null, function (Builder $query, string $search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $query
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('reference', 'like', "%{$search}%")
                        ->orWhere('city', 'like', "%{$search}%")
                        ->orWhere('location_name', 'like', "%{$search}%");
                });
            })
            ->orderBy('name');

        $paginator = $query->paginate($filters['per_page'] ?? 24)->withQueryString();
        $stationIds = (clone $summaryQuery)->pluck('id');

        return response()->json([
            'data' => StationResource::collection($paginator->getCollection()),
            'summary' => [
                'stations' => (clone $summaryQuery)->count(),
                'connectors' => Connector::query()->whereIn('station_id', $stationIds)->count(),
                'availability_percent' => round((float) ((clone $summaryQuery)->avg('uptime_percent') ?? 0), 1),
            ],
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function map(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Station::class);

        $availableOnly = $request->query('available_only');
        if (is_string($availableOnly) && in_array(strtolower($availableOnly), ['true', 'false'], true)) {
            $request->merge(['available_only' => filter_var($availableOnly, FILTER_VALIDATE_BOOLEAN)]);
        }

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(self::STATUSES)],
            'city' => ['nullable', 'string', 'max:120'],
            'connector_type' => ['nullable', Rule::in(self::CONNECTOR_TYPES)],
            'min_power_kw' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'available_only' => ['nullable', 'boolean'],
            'north' => ['nullable', 'numeric', 'between:-90,90'],
            'south' => ['nullable', 'numeric', 'between:-90,90'],
            'east' => ['nullable', 'numeric', 'between:-180,180'],
            'west' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $scope = $this->visibleScope($user);
        $cities = (clone $scope)->whereNotNull('city')->distinct()->orderBy('city')->pluck('city')->values();
        $query = $scope
            ->with([
                'organization',
                'connectors' => fn ($query) => $query->orderBy('external_id'),
            ])
            ->withCount([
                'connectors',
                'connectors as available_connectors_count' => fn (Builder $query) => $query->where('status', 'available'),
            ]);

        $this->applyMapFilters($query, $filters);
        $stations = $query->orderBy('name')->limit(1000)->get();

        return response()->json([
            'data' => StationMapResource::collection($stations),
            'summary' => [
                'stations' => $stations->count(),
                'available_connectors' => $stations->sum('available_connectors_count'),
                'by_status' => $stations->countBy('status'),
            ],
            'facets' => [
                'cities' => $cities,
            ],
        ]);
    }

    public function store(StoreStationRequest $request): JsonResponse
    {
        Gate::authorize('create', Station::class);

        /** @var User $user */
        $user = $request->user();
        $attributes = $request->validated();
        $attributes['organization_id'] = $user->hasRole('super_admin')
            ? $attributes['organization_id']
            : $user->organization_id;
        $attributes['ocpp_identity'] = $attributes['reference'];

        $station = Station::query()->create($attributes);

        return (new StationResource($station->load(['organization', 'connectors'])->loadCount('connectors')))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Station $station): StationResource
    {
        Gate::authorize('view', $station);

        return new StationResource($station->load(['organization', 'connectors'])->loadCount('connectors'));
    }

    public function update(UpdateStationRequest $request, Station $station): StationResource
    {
        Gate::authorize('update', $station);
        $station->update($request->validated());

        if ($station->isOcppManaged()) {
            $station = $this->availabilityProjector->project($station->id)['station'];
        }

        return new StationResource($station->fresh()->load(['organization', 'connectors'])->loadCount('connectors'));
    }

    public function destroy(Station $station): JsonResponse
    {
        Gate::authorize('delete', $station);
        $station->delete();

        return response()->json(status: 204);
    }

    private function visibleScope(User $user): Builder
    {
        return Station::query()
            ->when(
                $user->hasRole('client'),
                fn (Builder $query) => $query->whereHas('organization', fn (Builder $query) => $query->where('status', 'active')),
            )
            ->when(
                ! $user->hasAnyRole(['super_admin', 'client']),
                fn (Builder $query) => $query->where('organization_id', $user->organization_id),
            );
    }

    /** @param array<string, mixed> $filters */
    private function applyMapFilters(Builder $query, array $filters): void
    {
        $query
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['city'] ?? null, fn (Builder $query, string $city) => $query->where('city', $city))
            ->when($filters['min_power_kw'] ?? null, fn (Builder $query, float|int|string $power) => $query->where('max_power_kw', '>=', $power))
            ->when($filters['connector_type'] ?? null, fn (Builder $query, string $type) => $query->whereHas('connectors', fn (Builder $query) => $query->where('type', $type)))
            ->when($filters['available_only'] ?? false, fn (Builder $query) => $query->whereHas('connectors', fn (Builder $query) => $query->where('status', 'available')))
            ->when($filters['search'] ?? null, function (Builder $query, string $search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $query
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('reference', 'like', "%{$search}%")
                        ->orWhere('city', 'like', "%{$search}%")
                        ->orWhere('location_name', 'like', "%{$search}%");
                });
            });

        $bounds = ['north', 'south', 'east', 'west'];
        if (collect($bounds)->every(fn (string $bound) => array_key_exists($bound, $filters))) {
            $query
                ->whereBetween('latitude', [$filters['south'], $filters['north']])
                ->whereBetween('longitude', [$filters['west'], $filters['east']]);
        }
    }
}
