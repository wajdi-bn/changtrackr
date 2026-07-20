<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Payments\ProcessPaymentRequest;
use App\Http\Resources\PaymentResource;
use App\Models\ChargingSession;
use App\Models\Payment;
use App\Models\User;
use App\Services\PaymentService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PaymentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Payment::class);
        $filters = $this->validateFilters($request);

        /** @var User $user */
        $user = $request->user();
        $scope = $this->scopedQuery($user);
        $summary = clone $scope;
        $payments = $this->applyFilters($scope, $filters)
            ->with(['organization', 'chargingSession', 'user', 'latestProviderEvent'])
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'data' => PaymentResource::collection($payments),
            'summary' => [
                'total' => (clone $summary)->count(),
                'paid' => (clone $summary)->where('status', 'paid')->count(),
                'failed' => (clone $summary)->where('status', 'failed')->count(),
                'revenue_millimes' => (int) (clone $summary)->where('status', 'paid')->sum('amount_millimes'),
            ],
        ]);
    }

    public function export(Request $request): JsonResponse|StreamedResponse
    {
        Gate::authorize('export', Payment::class);
        $filters = $this->validateFilters($request);
        $format = $request->validate(['format' => ['required', Rule::in(['csv', 'json'])]])['format'];
        /** @var User $user */
        $user = $request->user();
        $rows = $this->applyFilters($this->scopedQuery($user), $filters)
            ->with(['organization', 'chargingSession', 'user'])
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Payment $payment) => [
                'reference' => $payment->reference,
                'organization' => $payment->organization?->name,
                'client' => $payment->user?->name,
                'session' => $payment->chargingSession?->reference,
                'station' => $payment->chargingSession?->station_name,
                'method' => $payment->method,
                'provider' => $payment->provider,
                'provider_transaction_id' => $payment->provider_transaction_id,
                'status' => $payment->status,
                'amount_millimes' => $payment->amount_millimes,
                'currency' => $payment->currency,
                'failure_reason' => $payment->failure_reason,
                'created_at' => $payment->created_at?->toISOString(),
            ]);

        if ($format === 'json') {
            return response()->json(['data' => $rows]);
        }

        return response()->streamDownload(function () use ($rows): void {
            $output = fopen('php://output', 'w');
            if ($output === false) {
                return;
            }
            fputcsv($output, ['Reference', 'Organization', 'Client', 'Session', 'Station', 'Method', 'Provider', 'Provider transaction', 'Status', 'Amount (millimes)', 'Currency', 'Failure reason', 'Created at']);
            foreach ($rows as $row) {
                fputcsv($output, array_values($row));
            }
            fclose($output);
        }, 'organization-payments.csv', ['Content-Type' => 'text/csv']);
    }

    public function store(
        ProcessPaymentRequest $request,
        ChargingSession $chargingSession,
        PaymentService $service,
    ): JsonResponse {
        Gate::authorize('pay', [Payment::class, $chargingSession]);
        /** @var User $user */
        $user = $request->user();
        $payment = $service->process($user, $chargingSession, $request->validated());

        return (new PaymentResource($payment))->response()->setStatusCode($payment->wasRecentlyCreated ? 201 : 200);
    }

    /** @return array<string, mixed> */
    private function validateFilters(Request $request): array
    {
        return $request->validate([
            'status' => ['nullable', Rule::in(['pending', 'paid', 'failed'])],
            'search' => ['nullable', 'string', 'max:120'],
        ]);
    }

    private function scopedQuery(User $user): Builder
    {
        return Payment::query()
            ->when(! $user->hasRole('super_admin'), function (Builder $query) use ($user): void {
                $user->hasRole('client')
                    ? $query->where('user_id', $user->id)
                    : $query->where('organization_id', $user->organization_id);
            });
    }

    /** @param array<string, mixed> $filters */
    private function applyFilters(Builder $query, array $filters): Builder
    {
        return $query
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['search'] ?? null, function (Builder $query, string $search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $query->where('reference', 'like', "%{$search}%")
                        ->orWhere('provider_transaction_id', 'like', "%{$search}%")
                        ->orWhereHas('chargingSession', fn (Builder $sessionQuery) => $sessionQuery
                            ->where('reference', 'like', "%{$search}%")
                            ->orWhere('station_name', 'like', "%{$search}%"));
                });
            });
    }
}
