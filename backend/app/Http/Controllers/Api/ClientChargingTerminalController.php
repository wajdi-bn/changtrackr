<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sessions\ExecuteClientChargingTerminalActionRequest;
use App\Http\Resources\OcppSimulatorActionResource;
use App\Models\ChargingSession;
use App\Models\Connector;
use App\Models\OcppSimulatorAction;
use App\Models\Station;
use App\Models\User;
use App\Services\Ocpp\ClientChargingTerminalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ClientChargingTerminalController extends Controller
{
    public function store(
        ExecuteClientChargingTerminalActionRequest $request,
        Station $station,
        Connector $connector,
        ClientChargingTerminalService $service,
    ): JsonResponse {
        Gate::authorize('create', ChargingSession::class);
        Gate::authorize('view', $station);

        /** @var User $client */
        $client = $request->user();
        $action = $service->execute($client, $station, $connector, $request->validated());

        return (new OcppSimulatorActionResource($action->load(['connector', 'requestedBy'])))
            ->response()
            ->setStatusCode(202);
    }

    public function show(
        Request $request,
        Station $station,
        Connector $connector,
        string $action,
    ): JsonResponse {
        Gate::authorize('create', ChargingSession::class);
        Gate::authorize('view', $station);

        abort_unless($connector->station_id === $station->id, 404);

        $terminalAction = OcppSimulatorAction::query()
            ->where('uuid', $action)
            ->where('station_id', $station->id)
            ->where('connector_id', $connector->id)
            ->where('requested_by_id', $request->user()->id)
            ->where('origin', 'client_terminal')
            ->with(['connector', 'requestedBy'])
            ->firstOrFail();

        return OcppSimulatorActionResource::make($terminalAction)->response();
    }
}
