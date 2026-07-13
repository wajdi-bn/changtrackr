<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tariffs\StoreTariffRequest;
use App\Http\Requests\Tariffs\UpdateTariffRequest;
use App\Http\Resources\TariffResource;
use App\Models\Tariff;
use App\Models\User;
use App\Services\TariffService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class TariffController extends Controller
{
    private const RELATIONS = ['assignments.station', 'assignments.connector.station'];

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Tariff::class);
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(['draft', 'active', 'archived'])],
        ]);
        /** @var User $user */
        $user = $request->user();
        $scope = Tariff::query()->when(
            ! $user->hasRole('super_admin'),
            fn (Builder $query) => $query->where('organization_id', $user->organization_id),
        );
        $summary = clone $scope;
        $tariffs = $scope
            ->with(self::RELATIONS)
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['search'] ?? null, fn (Builder $query, string $search) => $query
                ->where(fn (Builder $query) => $query->where('name', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%")))
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => TariffResource::collection($tariffs),
            'summary' => [
                'total' => (clone $summary)->count(),
                'active' => (clone $summary)->where('status', 'active')->count(),
                'draft' => (clone $summary)->where('status', 'draft')->count(),
                'assignments' => (clone $summary)->withCount('assignments')->get()->sum('assignments_count'),
            ],
        ]);
    }

    public function store(StoreTariffRequest $request, TariffService $service): JsonResponse
    {
        Gate::authorize('create', Tariff::class);
        /** @var User $user */
        $user = $request->user();
        $attributes = $request->validated();
        $organizationId = $user->hasRole('super_admin') ? $attributes['organization_id'] : $user->organization_id;
        $request->validate(['code' => [Rule::unique('tariffs')->where('organization_id', $organizationId)]]);
        $attributes['organization_id'] = $organizationId;

        return (new TariffResource($service->create($attributes)->load(self::RELATIONS)))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Tariff $tariff): TariffResource
    {
        Gate::authorize('view', $tariff);

        return new TariffResource($tariff->load(self::RELATIONS));
    }

    public function update(UpdateTariffRequest $request, Tariff $tariff, TariffService $service): TariffResource
    {
        Gate::authorize('update', $tariff);
        $attributes = $request->validated();
        if (array_key_exists('code', $attributes)) {
            $request->validate(['code' => [Rule::unique('tariffs')->where('organization_id', $tariff->organization_id)->ignore($tariff->id)]]);
        }

        return new TariffResource($service->update($tariff, $attributes)->load(self::RELATIONS));
    }

    public function destroy(Tariff $tariff): JsonResponse
    {
        Gate::authorize('delete', $tariff);
        $tariff->delete();

        return response()->json(status: 204);
    }
}
