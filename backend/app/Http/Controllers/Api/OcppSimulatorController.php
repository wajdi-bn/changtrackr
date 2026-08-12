<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Stations\ExecuteSimulatorActionRequest;
use App\Http\Resources\OcppSimulatorActionResource;
use App\Http\Resources\StationResource;
use App\Models\OcppEvent;
use App\Models\Station;
use App\Models\User;
use App\Services\Ocpp\OcppSimulatorActionCatalog;
use App\Services\Ocpp\OcppSimulatorActionService;
use App\Services\Ocpp\OcppSimulatorControlClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Throwable;

class OcppSimulatorController extends Controller
{
    public function __construct(
        private readonly OcppSimulatorActionService $actions,
        private readonly OcppSimulatorControlClient $client,
    ) {}

    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->can('ocpp_simulation.view'), 403);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $stations = Station::query()
            ->where('ocpp_commissioning_target', 'simulator')
            ->when(! $user->hasRole('super_admin'), fn ($query) => $query->where('organization_id', $user->organization_id))
            ->when($filters['search'] ?? null, function ($query, string $search): void {
                $query->where(function ($nested) use ($search): void {
                    $nested->where('name', 'ilike', "%{$search}%")
                        ->orWhere('reference', 'ilike', "%{$search}%")
                        ->orWhere('ocpp_identity', 'ilike', "%{$search}%")
                        ->orWhere('city', 'ilike', "%{$search}%");
                });
            })
            ->with(['organization', 'connectors'])
            ->withCount('connectors')
            ->orderBy('name')
            ->paginate($filters['per_page'] ?? 30)
            ->withQueryString();

        return StationResource::collection($stations)->response();
    }

    public function show(Request $request, Station $station): JsonResponse
    {
        Gate::authorize('viewSimulation', $station);
        $this->actions->ensureSimulatorStation($station);
        $station->loadMissing(['organization', 'connectors'])->loadCount('connectors');

        $filters = $request->validate(['per_page' => ['nullable', 'integer', 'min:1', 'max:30']]);
        $history = $station->simulatorActions()
            ->with(['connector', 'requestedBy'])
            ->latest('queued_at')
            ->paginate($filters['per_page'] ?? 15)
            ->withQueryString();

        try {
            $state = $this->sanitizeState($this->client->state((string) $station->ocpp_identity));
            $adapter = ['available' => true, 'message' => null];
        } catch (Throwable) {
            $state = null;
            $adapter = ['available' => false, 'message' => 'The local simulator control service is unavailable.'];
        }

        $canDiagnose = Gate::allows('diagnoseSimulation', $station);
        $canControl = Gate::allows('controlSimulation', $station);

        return response()->json([
            'station' => StationResource::make($station),
            'state' => $state,
            'adapter' => $adapter,
            'capabilities' => [
                'view' => true,
                'diagnose' => $canDiagnose,
                'control' => $canControl,
                'central_commands' => Gate::allows('executeCommands', $station),
                'allowed_actions' => [
                    ...($canControl ? OcppSimulatorActionCatalog::CONTROL_ACTIONS : []),
                    ...($canDiagnose || $canControl ? OcppSimulatorActionCatalog::DIAGNOSTIC_ACTIONS : []),
                ],
            ],
            'signals' => $this->signals($station),
            'history' => OcppSimulatorActionResource::collection($history)->response()->getData(true),
        ]);
    }

    public function store(ExecuteSimulatorActionRequest $request, Station $station): JsonResponse
    {
        $payload = $request->validated();
        $ability = OcppSimulatorActionCatalog::requiresControl($payload['action'])
            ? 'controlSimulation'
            : 'diagnoseSimulation';

        Gate::authorize($ability, $station);
        $this->actions->ensureSimulatorStation($station);

        /** @var User $user */
        $user = $request->user();
        $action = $this->actions->queue($station, $user, $payload);

        return (new OcppSimulatorActionResource($action->load(['connector', 'requestedBy'])))
            ->response()
            ->setStatusCode(202);
    }

    /** @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function sanitizeState(array $state): array
    {
        $connectors = collect(is_array($state['connectors'] ?? null) ? $state['connectors'] : [])
            ->map(function (mixed $connector): array {
                $connector = is_array($connector) ? $connector : [];

                return [
                    'connector_id' => (int) ($connector['connector_id'] ?? 0),
                    'status' => $this->safeString($connector['status'] ?? null, 'Unknown'),
                    'error_code' => $this->safeString($connector['error_code'] ?? null, 'NoError'),
                    'availability' => $this->safeString($connector['availability'] ?? null, 'Unknown'),
                    'transaction_started' => (bool) ($connector['transaction_started'] ?? false),
                ];
            })
            ->filter(fn (array $connector): bool => $connector['connector_id'] > 0)
            ->values()
            ->all();

        return [
            'identity' => $this->safeString($state['identity'] ?? null),
            'started' => (bool) ($state['started'] ?? false),
            'connected' => (bool) ($state['connected'] ?? false),
            'ws_state' => is_numeric($state['ws_state'] ?? null) ? (int) $state['ws_state'] : null,
            'connectors' => $connectors,
        ];
    }

    /** @return array<string, mixed> */
    private function signals(Station $station): array
    {
        $events = $station->ocppEvents()
            ->latest('occurred_at')
            ->limit(40)
            ->get()
            ->reverse()
            ->values();

        return [
            'last_event_at' => $station->ocpp_last_message_at?->toISOString(),
            'last_heartbeat_at' => $station->last_heartbeat_at?->toISOString(),
            'recent_count' => $events->filter(fn (OcppEvent $event): bool => $event->occurred_at?->gte(now()->subMinute()) ?? false)->count(),
            'events' => $events->map(fn (OcppEvent $event): array => $this->sanitizeEvent($event))->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function sanitizeEvent(OcppEvent $event): array
    {
        $payload = is_array($event->payload) ? $event->payload : [];
        $connectorId = is_numeric($payload['connectorId'] ?? null) ? (int) $payload['connectorId'] : null;

        return [
            'id' => (string) $event->event_id,
            'action' => $event->action,
            'category' => match ($event->action) {
                'ConnectionOpened', 'ConnectionClosed', 'BootNotification' => 'connection',
                'Heartbeat' => 'heartbeat',
                'StatusNotification' => 'status',
                'StartTransaction', 'StopTransaction' => 'transaction',
                'MeterValues' => 'meter',
                default => 'protocol',
            },
            'connector_id' => $connectorId,
            'status' => $this->safeString($payload['status'] ?? null, null),
            'error_code' => $this->safeString($payload['errorCode'] ?? null, null),
            'processing_status' => $event->processing_status,
            'occurred_at' => $event->occurred_at?->toISOString(),
            'received_at' => $event->received_at?->toISOString(),
        ];
    }

    private function safeString(mixed $value, ?string $fallback = ''): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return $fallback;
        }

        return mb_substr((string) $value, 0, 100);
    }
}
