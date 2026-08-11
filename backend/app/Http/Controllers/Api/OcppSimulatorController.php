<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Stations\ExecuteSimulatorActionRequest;
use App\Http\Resources\OcppSimulatorActionResource;
use App\Models\Station;
use App\Models\User;
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

    public function show(Request $request, Station $station): JsonResponse
    {
        Gate::authorize('viewCommands', $station);
        $this->actions->ensureSimulatorStation($station);

        $filters = $request->validate(['per_page' => ['nullable', 'integer', 'min:1', 'max:30']]);
        $history = $station->simulatorActions()
            ->with(['connector', 'requestedBy'])
            ->latest('queued_at')
            ->paginate($filters['per_page'] ?? 15)
            ->withQueryString();

        try {
            $state = $this->client->state((string) $station->ocpp_identity);
            $adapter = ['available' => true, 'message' => null];
        } catch (Throwable) {
            $state = null;
            $adapter = ['available' => false, 'message' => 'The local simulator control service is unavailable.'];
        }

        return response()->json([
            'state' => $state,
            'adapter' => $adapter,
            'history' => OcppSimulatorActionResource::collection($history)->response()->getData(true),
        ]);
    }

    public function store(ExecuteSimulatorActionRequest $request, Station $station): JsonResponse
    {
        Gate::authorize('executeCommands', $station);
        $this->actions->ensureSimulatorStation($station);

        /** @var User $user */
        $user = $request->user();
        $action = $this->actions->queue($station, $user, $request->validated());

        return (new OcppSimulatorActionResource($action->load(['connector', 'requestedBy'])))
            ->response()
            ->setStatusCode(202);
    }
}
