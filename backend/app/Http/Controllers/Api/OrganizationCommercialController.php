<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\OrganizationInvoice;
use App\Models\OrganizationSubscription;
use App\Models\SaasPlan;
use App\Models\User;
use App\Services\OrganizationBillingService;
use App\Services\OrganizationEntitlementService;
use App\Services\PlatformAuditService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class OrganizationCommercialController extends Controller
{
    private const PUBLIC_PLANS_CACHE_KEY = 'commercial:public-plans:v1';

    public function __construct(
        private readonly OrganizationBillingService $billing,
        private readonly OrganizationEntitlementService $entitlements,
    ) {}

    public function plans(Request $request): JsonResponse
    {
        $this->authorizeCommercialViewer($request);
        $plans = SaasPlan::query()
            ->when(! $request->user()->hasRole('super_admin'), fn ($query) => $query->where('status', 'active'))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return response()->json(['data' => $plans->map(fn (SaasPlan $plan) => $this->planPayload($plan))->values()]);
    }

    public function publicPlans(): JsonResponse
    {
        $plans = Cache::remember(self::PUBLIC_PLANS_CACHE_KEY, now()->addMinutes(10), fn () => SaasPlan::query()
            ->where('status', 'active')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (SaasPlan $plan) => $this->publicPlanPayload($plan))
            ->values()
            ->all());

        return response()->json(['data' => $plans]);
    }

    public function storePlan(Request $request, PlatformAuditService $audit): JsonResponse
    {
        $this->authorizeSuperAdmin($request);
        $plan = SaasPlan::query()->create($this->validatedPlan($request));
        Cache::forget(self::PUBLIC_PLANS_CACHE_KEY);
        $audit->record($request->user(), 'saas_plan.created', $plan, "Created SaaS plan {$plan->name}.");

        return response()->json(['data' => $this->planPayload($plan)], 201);
    }

    public function updatePlan(Request $request, SaasPlan $saasPlan, PlatformAuditService $audit): JsonResponse
    {
        $this->authorizeSuperAdmin($request);
        $attributes = $this->validatedPlan($request, $saasPlan);
        $saasPlan->update($attributes);
        Cache::forget(self::PUBLIC_PLANS_CACHE_KEY);
        $audit->record($request->user(), 'saas_plan.updated', $saasPlan, "Updated SaaS plan {$saasPlan->name}.", ['changed_fields' => array_keys($attributes)]);

        return response()->json(['data' => $this->planPayload($saasPlan->fresh())]);
    }

    public function portfolio(Request $request): JsonResponse
    {
        $this->authorizeSuperAdmin($request);
        $subscriptions = OrganizationSubscription::query()
            ->with(['organization', 'plan', 'events.actor'])
            ->withCount(['invoices as open_invoices_count' => fn ($query) => $query->whereIn('status', ['open', 'overdue'])])
            ->latest('updated_at')
            ->get();
        $invoices = OrganizationInvoice::query()
            ->with(['organization', 'plan', 'requestedBy', 'settledBy'])
            ->latest()
            ->limit(100)
            ->get();

        return response()->json([
            'summary' => [
                'organizations' => $subscriptions->count(),
                'trialing' => $subscriptions->where('status', 'trialing')->count(),
                'active' => $subscriptions->where('status', 'active')->count(),
                'attention' => $subscriptions->whereIn('status', ['past_due', 'grace_period', 'suspended'])->count(),
                'open_invoices' => $invoices->whereIn('status', ['open', 'overdue'])->count(),
                'collected_millimes' => $invoices->where('status', 'paid')->sum('amount_millimes'),
                'monthly_recurring_millimes' => $subscriptions->where('status', 'active')->sum(fn (OrganizationSubscription $subscription) => $subscription->billing_cycle === 'annual'
                    ? (int) round($subscription->plan->annual_price_millimes / 12)
                    : $subscription->plan->monthly_price_millimes),
            ],
            'subscriptions' => $subscriptions->map(fn (OrganizationSubscription $subscription) => $this->subscriptionPayload($subscription, true))->values(),
            'invoices' => $invoices->map(fn (OrganizationInvoice $invoice) => $this->invoicePayload($invoice))->values(),
        ]);
    }

    public function organizationBilling(Request $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        abort_unless($actor->hasRole('admin') && $actor->organization_id !== null, 403);
        $organization = Organization::query()
            ->with(['commercialSubscription.plan', 'commercialSubscription.events.actor', 'commercialInvoices.plan', 'commercialInvoices.requestedBy', 'commercialInvoices.settledBy'])
            ->withCount(['stations', 'users as employees_count' => fn ($query) => $query->whereIn('status', ['active', 'pending'])->whereHas('roles', fn ($roles) => $roles->whereIn('name', ['operator', 'technician']))])
            ->findOrFail($actor->organization_id);
        $subscription = $organization->commercialSubscription;

        return response()->json([
            'organization' => ['id' => $organization->id, 'name' => $organization->name, 'contact_email' => $organization->contact_email],
            'subscription' => $subscription ? $this->subscriptionPayload($subscription, true) : null,
            'usage' => [
                'employees' => (int) $organization->employees_count,
                'stations' => (int) $organization->stations_count,
                'limits' => $this->entitlements->limits($organization),
            ],
            'plans' => SaasPlan::query()->where('status', 'active')->orderBy('sort_order')->get()->map(fn (SaasPlan $plan) => $this->planPayload($plan))->values(),
            'invoices' => $organization->commercialInvoices->sortByDesc('created_at')->map(fn (OrganizationInvoice $invoice) => $this->invoicePayload($invoice))->values(),
        ]);
    }

    public function requestPlan(Request $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        abort_unless($actor->hasRole('admin') && $actor->organization_id !== null, 403);
        $attributes = $request->validate([
            'saas_plan_id' => ['required', 'integer', Rule::exists('saas_plans', 'id')->where('status', 'active')],
            'billing_cycle' => ['required', Rule::in(['monthly', 'annual'])],
            'payment_method' => ['required', Rule::in(['simulated_card', 'simulated_edinar', 'simulated_d17'])],
            'idempotency_key' => ['required', 'uuid'],
            'simulation_outcome' => ['sometimes', Rule::in(['success', 'declined', 'timeout', 'provider_error'])],
        ]);
        $invoice = $this->billing->requestPlan(
            $actor->organization,
            $actor,
            SaasPlan::query()->findOrFail($attributes['saas_plan_id']),
            $attributes['billing_cycle'],
            $attributes['payment_method'],
            $attributes['idempotency_key'],
            app()->environment(['local', 'testing']) ? ($attributes['simulation_outcome'] ?? 'success') : 'success',
        );

        return response()->json(['data' => $this->invoicePayload($invoice)], 201);
    }

    public function extendTrial(Request $request, OrganizationSubscription $subscription, PlatformAuditService $audit): JsonResponse
    {
        $this->authorizeSuperAdmin($request);
        $attributes = $request->validate(['days' => ['required', 'integer', 'min:1', 'max:90'], 'note' => ['nullable', 'string', 'max:1000']]);
        $subscription = $this->billing->extendTrial($subscription, $request->user(), $attributes['days'], $attributes['note'] ?? null);
        $audit->record($request->user(), 'organization_subscription.trial_extended', $subscription->organization, "Extended trial for {$subscription->organization->name}.", ['days' => $attributes['days']]);

        return response()->json(['data' => $this->subscriptionPayload($subscription, true)]);
    }

    public function suspend(Request $request, OrganizationSubscription $subscription, PlatformAuditService $audit): JsonResponse
    {
        $this->authorizeSuperAdmin($request);
        $note = $request->validate(['note' => ['nullable', 'string', 'max:1000']])['note'] ?? null;
        $subscription = $this->billing->suspend($subscription, $request->user(), $note);
        $audit->record($request->user(), 'organization_subscription.suspended', $subscription->organization, "Suspended commercial access for {$subscription->organization->name}.");

        return response()->json(['data' => $this->subscriptionPayload($subscription, true)]);
    }

    public function restore(Request $request, OrganizationSubscription $subscription, PlatformAuditService $audit): JsonResponse
    {
        $this->authorizeSuperAdmin($request);
        $note = $request->validate(['note' => ['nullable', 'string', 'max:1000']])['note'] ?? null;
        $subscription = $this->billing->restore($subscription, $request->user(), $note);
        $audit->record($request->user(), 'organization_subscription.restored', $subscription->organization, "Restored commercial access for {$subscription->organization->name}.");

        return response()->json(['data' => $this->subscriptionPayload($subscription, true)]);
    }

    public function settle(Request $request, OrganizationInvoice $invoice, PlatformAuditService $audit): JsonResponse
    {
        $this->authorizeSuperAdmin($request);
        $invoice = $this->billing->settleInvoice($invoice, $request->user());
        $audit->record($request->user(), 'organization_invoice.settled', $invoice->organization, "Settled commercial invoice {$invoice->number}.", ['invoice_id' => $invoice->id]);

        return response()->json(['data' => $this->invoicePayload($invoice)]);
    }

    public function void(Request $request, OrganizationInvoice $invoice, PlatformAuditService $audit): JsonResponse
    {
        $this->authorizeSuperAdmin($request);
        $invoice = $this->billing->voidInvoice($invoice, $request->user());
        $audit->record($request->user(), 'organization_invoice.voided', $invoice->organization, "Voided commercial invoice {$invoice->number}.", ['invoice_id' => $invoice->id]);

        return response()->json(['data' => $this->invoicePayload($invoice)]);
    }

    public function invoiceDocument(Request $request, OrganizationInvoice $invoice): Response
    {
        /** @var User $actor */
        $actor = $request->user();
        abort_unless($actor->hasRole('super_admin') || ($actor->hasRole('admin') && $actor->organization_id === $invoice->organization_id), 403);
        $invoice->load(['organization', 'plan', 'requestedBy', 'settledBy']);

        return Pdf::loadView('reports.organization-invoice', ['invoice' => $invoice, 'issuedAt' => now()->format('d M Y, H:i')])
            ->setPaper('a4')
            ->download("invoice-{$invoice->number}.pdf");
    }

    /** @return array<string, mixed> */
    private function validatedPlan(Request $request, ?SaasPlan $plan = null): array
    {
        return $request->validate([
            'name' => [$plan ? 'sometimes' : 'required', 'string', 'max:120'],
            'code' => [$plan ? 'sometimes' : 'required', 'string', 'max:40', 'alpha_dash', Rule::unique('saas_plans', 'code')->ignore($plan?->id)],
            'description' => ['nullable', 'string', 'max:1000'],
            'monthly_price_millimes' => [$plan ? 'sometimes' : 'required', 'integer', 'min:0', 'max:999999999'],
            'annual_price_millimes' => [$plan ? 'sometimes' : 'required', 'integer', 'min:0', 'max:9999999999'],
            'max_stations' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'max_employees' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'features' => ['nullable', 'array', 'max:20'],
            'features.*' => ['string', 'max:160'],
            'is_featured' => ['sometimes', 'boolean'],
            'status' => ['sometimes', Rule::in(SaasPlan::STATUSES)],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:1000'],
        ]);
    }

    /** @return array<string, mixed> */
    private function planPayload(SaasPlan $plan): array
    {
        return [
            'id' => $plan->id, 'name' => $plan->name, 'code' => $plan->code, 'description' => $plan->description,
            'monthly_price_millimes' => $plan->monthly_price_millimes, 'annual_price_millimes' => $plan->annual_price_millimes,
            'max_stations' => $plan->max_stations, 'max_employees' => $plan->max_employees, 'features' => $plan->features ?? [],
            'is_featured' => $plan->is_featured, 'status' => $plan->status, 'sort_order' => $plan->sort_order,
        ];
    }

    /** @return array<string, mixed> */
    private function publicPlanPayload(SaasPlan $plan): array
    {
        return [
            'name' => $plan->name,
            'code' => $plan->code,
            'description' => $plan->description,
            'monthly_price_millimes' => $plan->monthly_price_millimes,
            'annual_price_millimes' => $plan->annual_price_millimes,
            'max_stations' => $plan->max_stations,
            'max_employees' => $plan->max_employees,
            'features' => $plan->features ?? [],
            'is_featured' => $plan->is_featured,
        ];
    }

    /** @return array<string, mixed> */
    private function subscriptionPayload(OrganizationSubscription $subscription, bool $detailed = false): array
    {
        $subscription->loadMissing(['organization', 'plan']);
        $payload = [
            'id' => $subscription->id,
            'organization' => ['id' => $subscription->organization->id, 'name' => $subscription->organization->name, 'contact_email' => $subscription->organization->contact_email],
            'plan' => $this->planPayload($subscription->plan),
            'status' => $subscription->status,
            'billing_cycle' => $subscription->billing_cycle,
            'source' => $subscription->source,
            'auto_renew' => $subscription->auto_renew,
            'trial_started_at' => $subscription->trial_started_at?->toISOString(),
            'trial_ends_at' => $subscription->trial_ends_at?->toISOString(),
            'current_period_starts_at' => $subscription->current_period_starts_at?->toISOString(),
            'current_period_ends_at' => $subscription->current_period_ends_at?->toISOString(),
            'grace_ends_at' => $subscription->grace_ends_at?->toISOString(),
            'suspended_at' => $subscription->suspended_at?->toISOString(),
            'open_invoices_count' => (int) ($subscription->open_invoices_count ?? 0),
        ];
        if ($detailed) {
            $subscription->loadMissing('events.actor');
            $payload['events'] = $subscription->events->sortByDesc('created_at')->take(20)->map(fn ($event) => [
                'id' => $event->id, 'event' => $event->event, 'from_status' => $event->from_status, 'to_status' => $event->to_status,
                'note' => $event->note, 'actor' => $event->actor?->name ?? 'System', 'created_at' => $event->created_at?->toISOString(),
            ])->values();
        }

        return $payload;
    }

    /** @return array<string, mixed> */
    private function invoicePayload(OrganizationInvoice $invoice): array
    {
        $invoice->loadMissing(['organization', 'plan', 'requestedBy', 'settledBy']);

        return [
            'id' => $invoice->id, 'number' => $invoice->number, 'status' => $invoice->status,
            'organization' => ['id' => $invoice->organization->id, 'name' => $invoice->organization->name],
            'plan' => ['id' => $invoice->plan->id, 'name' => $invoice->plan->name, 'code' => $invoice->plan->code],
            'billing_cycle' => $invoice->billing_cycle, 'amount_millimes' => $invoice->amount_millimes, 'currency' => $invoice->currency,
            'period_starts_at' => $invoice->period_starts_at?->toISOString(), 'period_ends_at' => $invoice->period_ends_at?->toISOString(),
            'due_at' => $invoice->due_at?->toISOString(), 'paid_at' => $invoice->paid_at?->toISOString(),
            'payment_provider' => $invoice->payment_provider, 'payment_method' => $invoice->payment_method,
            'provider_reference' => $invoice->provider_reference, 'failed_at' => $invoice->failed_at?->toISOString(),
            'failure_reason' => $invoice->failure_reason,
            'requested_by' => $invoice->requestedBy?->name, 'settled_by' => $invoice->settledBy?->name,
            'created_at' => $invoice->created_at?->toISOString(),
        ];
    }

    private function authorizeCommercialViewer(Request $request): void
    {
        abort_unless($request->user()?->hasAnyRole(['super_admin', 'admin']), 403);
    }

    private function authorizeSuperAdmin(Request $request): void
    {
        abort_unless($request->user()?->hasRole('super_admin'), 403);
    }
}
