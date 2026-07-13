<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PlanSubscriptionResource;
use App\Http\Resources\SubscriptionPlanResource;
use App\Models\ChargingPlan;
use App\Models\PlanSubscription;
use App\Models\User;
use App\Services\PlanSubscriptionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class PlanSubscriptionController extends Controller
{
    public function catalog(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', PlanSubscription::class);
        /** @var User $client */
        $client = $request->user();
        $plans = ChargingPlan::query()
            ->where('status', 'active')
            ->whereHas('organization', fn (Builder $query) => $query->where('status', 'active'))
            ->with([
                'organization',
                'subscriptions' => fn ($query) => $query
                    ->where('user_id', $client->id)
                    ->current()
                    ->with(['organization', 'chargingPlan']),
            ])
            ->orderBy('organization_id')
            ->orderBy('monthly_fee_millimes')
            ->get();

        return response()->json(['data' => SubscriptionPlanResource::collection($plans)]);
    }

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', PlanSubscription::class);
        /** @var User $client */
        $client = $request->user();
        $subscriptions = PlanSubscription::query()
            ->where('user_id', $client->id)
            ->with(['organization', 'chargingPlan'])
            ->orderByRaw("case when status = 'active' then 0 else 1 end")
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['data' => PlanSubscriptionResource::collection($subscriptions)]);
    }

    public function store(Request $request, PlanSubscriptionService $service): JsonResponse
    {
        Gate::authorize('create', PlanSubscription::class);
        $attributes = $request->validate([
            'charging_plan_id' => ['required', 'integer', 'exists:charging_plans,id'],
            'auto_renew' => ['required', 'boolean'],
        ]);
        /** @var User $client */
        $client = $request->user();

        return (new PlanSubscriptionResource($service->subscribe(
            $client,
            $attributes['charging_plan_id'],
            $attributes['auto_renew'],
        )))->response()->setStatusCode(201);
    }

    public function update(Request $request, PlanSubscription $subscription): PlanSubscriptionResource
    {
        Gate::authorize('update', $subscription);
        $attributes = $request->validate(['auto_renew' => ['required', 'boolean']]);
        $subscription->update($attributes);

        return new PlanSubscriptionResource($subscription->refresh()->load(['organization', 'chargingPlan']));
    }

    public function destroy(PlanSubscription $subscription, PlanSubscriptionService $service): PlanSubscriptionResource
    {
        Gate::authorize('delete', $subscription);

        return new PlanSubscriptionResource($service->cancel($subscription));
    }
}
