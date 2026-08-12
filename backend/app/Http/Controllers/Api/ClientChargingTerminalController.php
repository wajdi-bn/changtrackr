<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sessions\ExecuteClientChargingTerminalActionRequest;
use App\Http\Resources\OcppSimulatorActionResource;
use App\Models\ChargingSession;
use App\Models\Connector;
use App\Models\Station;
use App\Models\User;
use App\Services\Ocpp\ClientChargingTerminalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class ClientChargingTerminalController extends Controller
{
    public function __invoke(
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
}
