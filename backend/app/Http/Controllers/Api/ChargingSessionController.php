<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sessions\StartChargingSessionRequest;
use App\Http\Resources\ChargingSessionResource;
use App\Models\ChargingSession;
use App\Models\User;
use App\Services\ChargingSessionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ChargingSessionController extends Controller
{
    private const RELATIONS = ['organization', 'station', 'connector', 'client', 'payment'];

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', ChargingSession::class);
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(['charging', 'completed', 'cancelled'])],
            'payment_status' => ['nullable', Rule::in(['unpaid', 'paid', 'failed'])],
        ]);

        /** @var User $user */
        $user = $request->user();
        $scope = ChargingSession::query()
            ->when(! $user->hasRole('super_admin'), function (Builder $query) use ($user): void {
                $user->hasRole('client')
                    ? $query->where('client_id', $user->id)
                    : $query->where('organization_id', $user->organization_id);
            });
        $summary = clone $scope;

        $sessions = $scope
            ->with(self::RELATIONS)
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['payment_status'] ?? null, fn (Builder $query, string $status) => $query->where('payment_status', $status))
            ->when($filters['search'] ?? null, function (Builder $query, string $search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $query->where('reference', 'like', "%{$search}%")
                        ->orWhere('client_name', 'like', "%{$search}%")
                        ->orWhere('station_name', 'like', "%{$search}%")
                        ->orWhere('connector_external_id', 'like', "%{$search}%");
                });
            })
            ->orderByDesc('started_at')
            ->get();

        return response()->json([
            'data' => ChargingSessionResource::collection($sessions),
            'summary' => [
                'total' => (clone $summary)->count(),
                'active' => (clone $summary)->where('status', 'charging')->count(),
                'completed' => (clone $summary)->where('status', 'completed')->count(),
                'energy_kwh' => round((float) ((clone $summary)->sum('energy_kwh')), 3),
                'revenue_millimes' => (int) (clone $summary)->where('payment_status', 'paid')->sum('total_millimes'),
            ],
        ]);
    }

    public function store(StartChargingSessionRequest $request, ChargingSessionService $service): JsonResponse
    {
        Gate::authorize('create', ChargingSession::class);
        /** @var User $user */
        $user = $request->user();
        $session = $service->start($user, $request->validated());

        return (new ChargingSessionResource($session))->response()->setStatusCode(201);
    }

    public function show(ChargingSession $chargingSession): ChargingSessionResource
    {
        Gate::authorize('view', $chargingSession);

        return new ChargingSessionResource($chargingSession->load(self::RELATIONS));
    }

    public function stop(ChargingSession $chargingSession, ChargingSessionService $service): ChargingSessionResource
    {
        Gate::authorize('stop', $chargingSession);

        return new ChargingSessionResource($service->stop($chargingSession));
    }
}
