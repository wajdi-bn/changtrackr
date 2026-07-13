<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ChargingPlans\StoreChargingPlanRequest;
use App\Http\Requests\ChargingPlans\UpdateChargingPlanRequest;
use App\Http\Resources\ChargingPlanResource;
use App\Models\ChargingPlan;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ChargingPlanController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', ChargingPlan::class);
        /** @var User $user */
        $user = $request->user();
        $plans = ChargingPlan::query()
            ->when(! $user->hasRole('super_admin'), fn (Builder $query) => $query->where('organization_id', $user->organization_id))
            ->orderByRaw("case when status = 'active' then 0 when status = 'draft' then 1 else 2 end")
            ->orderBy('monthly_fee_millimes')
            ->get();

        return response()->json(['data' => ChargingPlanResource::collection($plans)]);
    }

    public function store(StoreChargingPlanRequest $request): JsonResponse
    {
        Gate::authorize('create', ChargingPlan::class);
        /** @var User $user */
        $user = $request->user();
        $attributes = $request->validated();
        $organizationId = $user->hasRole('super_admin') ? $attributes['organization_id'] : $user->organization_id;
        $request->validate(['code' => [Rule::unique('charging_plans')->where('organization_id', $organizationId)]]);
        $attributes['organization_id'] = $organizationId;

        return (new ChargingPlanResource(ChargingPlan::query()->create($attributes)))
            ->response()
            ->setStatusCode(201);
    }

    public function show(ChargingPlan $chargingPlan): ChargingPlanResource
    {
        Gate::authorize('view', $chargingPlan);

        return new ChargingPlanResource($chargingPlan);
    }

    public function update(UpdateChargingPlanRequest $request, ChargingPlan $chargingPlan): ChargingPlanResource
    {
        Gate::authorize('update', $chargingPlan);
        $attributes = $request->validated();
        if (array_key_exists('code', $attributes)) {
            $request->validate(['code' => [Rule::unique('charging_plans')->where('organization_id', $chargingPlan->organization_id)->ignore($chargingPlan->id)]]);
        }
        $chargingPlan->update($attributes);

        return new ChargingPlanResource($chargingPlan->refresh());
    }

    public function destroy(ChargingPlan $chargingPlan): JsonResponse
    {
        Gate::authorize('delete', $chargingPlan);
        $chargingPlan->delete();

        return response()->json(status: 204);
    }
}
