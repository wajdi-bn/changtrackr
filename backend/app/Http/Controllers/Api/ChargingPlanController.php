<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ChargingPlans\StoreChargingPlanRequest;
use App\Http\Requests\ChargingPlans\UpdateChargingPlanRequest;
use App\Http\Resources\ChargingPlanResource;
use App\Models\ChargingPlan;
use App\Models\PlanSubscription;
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
            ->withCount([
                'subscriptions as current_member_count' => fn (Builder $query) => $query->current(),
                'subscriptionInvoices as failed_payments_count' => fn (Builder $query) => $query->where('status', 'failed'),
            ])
            ->withSum([
                'subscriptionInvoices as collected_millimes' => fn (Builder $query) => $query->where('status', 'paid'),
            ], 'amount_millimes')
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

    public function subscribers(ChargingPlan $chargingPlan): JsonResponse
    {
        Gate::authorize('view', $chargingPlan);
        $subscriptions = PlanSubscription::query()
            ->where('charging_plan_id', $chargingPlan->id)
            ->with(['user', 'invoices' => fn ($query) => $query->latest()])
            ->orderByRaw("case when status = 'active' then 0 when status = 'past_due' then 1 else 2 end")
            ->latest()
            ->get();

        return response()->json([
            'summary' => [
                'current_members' => PlanSubscription::query()
                    ->where('charging_plan_id', $chargingPlan->id)
                    ->current()
                    ->count(),
                'past_due' => $subscriptions->where('status', 'past_due')->count(),
                'collected_millimes' => $subscriptions->flatMap->invoices
                    ->where('status', 'paid')
                    ->sum('amount_millimes'),
            ],
            'data' => $subscriptions->map(fn (PlanSubscription $subscription) => [
                'id' => $subscription->id,
                'customer' => [
                    'id' => $subscription->user->id,
                    'name' => $subscription->user->name,
                    'email' => $subscription->user->email,
                    'avatar_url' => $subscription->user->avatar_url,
                ],
                'status' => $subscription->status,
                'auto_renew' => $subscription->auto_renew,
                'cancel_at_period_end' => $subscription->cancel_at_period_end,
                'current_period_ends_at' => $subscription->current_period_ends_at?->toISOString(),
                'grace_ends_at' => $subscription->grace_ends_at?->toISOString(),
                'invoices_count' => $subscription->invoices->count(),
                'paid_millimes' => $subscription->invoices->where('status', 'paid')->sum('amount_millimes'),
                'latest_invoice_status' => $subscription->invoices->first()?->status,
            ])->values(),
        ]);
    }
}
