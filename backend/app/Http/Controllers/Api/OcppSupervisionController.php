<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Stations\SetMaintenanceModeRequest;
use App\Http\Resources\OcppCommandResource;
use App\Http\Resources\StationResource;
use App\Models\Connector;
use App\Models\Station;
use App\Models\User;
use App\Services\Availability\AvailabilityProjectionService;
use App\Services\Ocpp\OcppCommandService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class OcppSupervisionController extends Controller
{
    public function __construct(
        private readonly OcppCommandService $commands,
        private readonly AvailabilityProjectionService $availabilityProjector,
    ) {}

    public function index(Request $request, Station $station): JsonResponse
    {
        Gate::authorize('viewCommands', $station);

        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['queued', 'sent', 'accepted', 'rejected', 'failed', 'timed_out'])],
            'action' => ['nullable', Rule::in(['Reset', 'UnlockConnector', 'ChangeAvailability'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $commands = $station->ocppCommands()
            ->with(['connector', 'user'])
            ->whereIn('action', ['Reset', 'UnlockConnector', 'ChangeAvailability'])
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when($filters['action'] ?? null, fn ($query, string $action) => $query->where('action', $action))
            ->latest('queued_at')
            ->paginate($filters['per_page'] ?? 20)
            ->withQueryString();

        return OcppCommandResource::collection($commands)->response();
    }

    public function reset(Request $request, Station $station): JsonResponse
    {
        Gate::authorize('executeCommands', $station);

        /** @var User $user */
        $user = $request->user();
        $command = $this->commands->queueReset($station, $user);

        return (new OcppCommandResource($command->load(['connector', 'user'])))
            ->response()
            ->setStatusCode(202);
    }

    public function unlock(Request $request, Station $station, Connector $connector): JsonResponse
    {
        Gate::authorize('executeCommands', $station);
        abort_unless($connector->station_id === $station->id, 404);

        /** @var User $user */
        $user = $request->user();
        $command = $this->commands->queueUnlock($station, $connector, $user);

        return (new OcppCommandResource($command->load(['connector', 'user'])))
            ->response()
            ->setStatusCode(202);
    }

    public function maintenance(SetMaintenanceModeRequest $request, Station $station): JsonResponse
    {
        Gate::authorize('executeCommands', $station);

        /** @var User $user */
        $user = $request->user();
        $enabled = $request->boolean('enabled');
        $command = $this->commands->setMaintenanceMode($station, $user, $enabled);
        $projected = $this->availabilityProjector->project($station->id)['station'];

        return response()->json([
            'station' => new StationResource($projected->load(['organization', 'connectors'])->loadCount('connectors')->loadTodayMetrics()),
            'command' => $command === null
                ? null
                : new OcppCommandResource($command->load(['connector', 'user'])),
            'ocpp_sync' => $command === null ? 'not_connected' : 'queued',
        ], 202);
    }
}
