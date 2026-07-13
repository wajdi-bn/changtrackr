<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Stations\StoreConnectorRequest;
use App\Http\Resources\ConnectorResource;
use App\Models\Connector;
use App\Models\Station;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ConnectorController extends Controller
{
    public function store(StoreConnectorRequest $request, Station $station): JsonResponse
    {
        Gate::authorize('update', $station);
        abort_unless($request->user()->can('connectors.manage'), 403);

        $attributes = $request->validated();
        $request->validate([
            'external_id' => ['unique:connectors,external_id,NULL,id,station_id,'.$station->id],
        ]);

        $connector = $station->connectors()->create([
            ...$attributes,
            'last_status_at' => now(),
        ]);

        return (new ConnectorResource($connector))->response()->setStatusCode(201);
    }

    public function update(StoreConnectorRequest $request, Station $station, Connector $connector): ConnectorResource
    {
        Gate::authorize('update', $station);
        abort_unless($request->user()->can('connectors.manage'), 403);
        abort_unless($connector->station_id === $station->id, 404);

        $request->validate([
            'external_id' => ['unique:connectors,external_id,'.$connector->id.',id,station_id,'.$station->id],
        ]);

        $connector->update([
            ...$request->validated(),
            'last_status_at' => now(),
        ]);

        return new ConnectorResource($connector->fresh());
    }

    public function destroy(Request $request, Station $station, Connector $connector): JsonResponse
    {
        Gate::authorize('update', $station);
        abort_unless($request->user()->can('connectors.manage'), 403);
        abort_unless($connector->station_id === $station->id, 404);
        $connector->delete();

        return response()->json(status: 204);
    }
}
