<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PlanSubscriptionInvoiceResource;
use App\Http\Resources\PlanSubscriptionResource;
use App\Http\Resources\SubscriptionPlanResource;
use App\Models\ChargingPlan;
use App\Models\PlanSubscription;
use App\Models\PlanSubscriptionInvoice;
use App\Models\User;
use App\Services\PlanSubscriptionService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

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
            ->withCount([
                'subscriptions as current_member_count' => fn (Builder $query) => $query->current(),
            ])
            ->withSum([
                'subscriptionInvoices as collected_millimes' => fn (Builder $query) => $query->where('status', 'paid'),
            ], 'amount_millimes')
            ->with([
                'organization',
                'subscriptions' => fn ($query) => $query
                    ->where('user_id', $client->id)
                    ->current()
                    ->with(['organization', 'chargingPlan', 'invoices']),
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
            ->with(['organization', 'chargingPlan', 'invoices'])
            ->orderByRaw("case when status = 'active' then 0 when status = 'past_due' then 1 else 2 end")
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['data' => PlanSubscriptionResource::collection($subscriptions)]);
    }

    public function invoices(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', PlanSubscription::class);
        /** @var User $client */
        $client = $request->user();
        $invoices = PlanSubscriptionInvoice::query()
            ->where('user_id', $client->id)
            ->with(['organization', 'chargingPlan'])
            ->latest()
            ->paginate(min(50, max(1, $request->integer('per_page', 20))));

        return PlanSubscriptionInvoiceResource::collection($invoices)->response();
    }

    public function store(Request $request, PlanSubscriptionService $service): JsonResponse
    {
        Gate::authorize('create', PlanSubscription::class);
        $attributes = $request->validate([
            'charging_plan_id' => ['required', 'integer', 'exists:charging_plans,id'],
            'auto_renew' => ['required', 'boolean'],
            'payment_method' => ['required', Rule::in(['simulated_card', 'simulated_edinar', 'simulated_d17'])],
            'idempotency_key' => ['required', 'uuid'],
            'simulation_outcome' => ['sometimes', Rule::in(['success', 'declined'])],
        ]);
        /** @var User $client */
        $client = $request->user();

        return (new PlanSubscriptionResource($service->subscribe(
            $client,
            $attributes['charging_plan_id'],
            $attributes['auto_renew'],
            $attributes['payment_method'],
            $attributes['idempotency_key'],
            app()->environment(['local', 'testing']) ? ($attributes['simulation_outcome'] ?? 'success') : 'success',
        )))->response()->setStatusCode(201);
    }

    public function update(
        Request $request,
        PlanSubscription $subscription,
        PlanSubscriptionService $service,
    ): PlanSubscriptionResource {
        Gate::authorize('update', $subscription);
        $attributes = $request->validate(['auto_renew' => ['required', 'boolean']]);

        return new PlanSubscriptionResource($service->updateAutoRenew($subscription, $attributes['auto_renew']));
    }

    public function destroy(
        PlanSubscription $subscription,
        PlanSubscriptionService $service,
    ): PlanSubscriptionResource {
        Gate::authorize('delete', $subscription);

        return new PlanSubscriptionResource($service->requestCancellation($subscription));
    }

    public function resume(
        PlanSubscription $subscription,
        PlanSubscriptionService $service,
    ): PlanSubscriptionResource {
        Gate::authorize('update', $subscription);

        return new PlanSubscriptionResource($service->resume($subscription));
    }

    public function retry(
        Request $request,
        PlanSubscription $subscription,
        PlanSubscriptionService $service,
    ): PlanSubscriptionResource {
        Gate::authorize('update', $subscription);
        $attributes = $request->validate([
            'payment_method' => ['required', Rule::in(['simulated_card', 'simulated_edinar', 'simulated_d17'])],
            'idempotency_key' => ['required', 'uuid'],
            'simulation_outcome' => ['sometimes', Rule::in(['success', 'declined'])],
        ]);

        return new PlanSubscriptionResource($service->retry(
            $subscription,
            $attributes['payment_method'],
            $attributes['idempotency_key'],
            app()->environment(['local', 'testing']) ? ($attributes['simulation_outcome'] ?? 'success') : 'success',
        ));
    }

    public function invoiceDocument(Request $request, PlanSubscriptionInvoice $invoice): Response
    {
        /** @var User $client */
        $client = $request->user();
        abort_unless(
            $client->hasRole('client')
            && $client->can('subscriptions.view')
            && $invoice->user_id === $client->id,
            403,
        );
        $invoice->load(['organization', 'chargingPlan', 'user']);
        $pdf = Pdf::loadView('reports.plan-subscription-invoice', [
            'invoice' => $invoice,
            'issuedAt' => now()->format('d M Y, H:i'),
        ])->setPaper('a4');
        $filename = "plan-invoice-{$invoice->reference}.pdf";

        return $request->boolean('download')
            ? $pdf->download($filename)
            : $pdf->stream($filename);
    }
}
