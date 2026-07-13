<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Stations\StoreStationRequest;
use App\Http\Requests\Stations\UpdateStationRequest;
use App\Http\Resources\StationResource;
use App\Models\Connector;
use App\Models\Station;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class StationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Station::class);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(['available', 'charging', 'faulted', 'offline', 'maintenance'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $scope = Station::query()
            ->when(
                $user->hasRole('client'),
                fn (Builder $query) => $query->whereHas('organization', fn (Builder $query) => $query->where('status', 'active')),
            )
            ->when(
                ! $user->hasAnyRole(['super_admin', 'client']),
                fn (Builder $query) => $query->where('organization_id', $user->organization_id),
            );

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

    public function store(StoreStationRequest $request): JsonResponse
    {
        Gate::authorize('create', Station::class);

        /** @var User $user */
        $user = $request->user();
        $attributes = $request->validated();
        $attributes['organization_id'] = $user->hasRole('super_admin')
            ? $attributes['organization_id']
            : $user->organization_id;

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

        return new StationResource($station->fresh()->load(['organization', 'connectors'])->loadCount('connectors'));
    }

    public function destroy(Station $station): JsonResponse
    {
        Gate::authorize('delete', $station);
        $station->delete();

        return response()->json(status: 204);
    }
}
