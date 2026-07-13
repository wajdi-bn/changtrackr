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

class PaymentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Payment::class);
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['pending', 'paid', 'failed'])],
            'search' => ['nullable', 'string', 'max:120'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $scope = Payment::query()
            ->when(! $user->hasRole('super_admin'), function (Builder $query) use ($user): void {
                $user->hasRole('client')
                    ? $query->where('user_id', $user->id)
                    : $query->where('organization_id', $user->organization_id);
            });
        $summary = clone $scope;
        $payments = $scope
            ->with(['organization', 'chargingSession', 'user'])
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['search'] ?? null, function (Builder $query, string $search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $query->where('reference', 'like', "%{$search}%")
                        ->orWhere('provider_transaction_id', 'like', "%{$search}%")
                        ->orWhereHas('chargingSession', fn (Builder $sessionQuery) => $sessionQuery
                            ->where('reference', 'like', "%{$search}%")
                            ->orWhere('station_name', 'like', "%{$search}%"));
                });
            })
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
}
