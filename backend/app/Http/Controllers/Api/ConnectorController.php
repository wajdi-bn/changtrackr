<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Stations\StoreConnectorRequest;
use App\Http\Resources\ConnectorResource;
use App\Models\Connector;
use App\Models\Station;
use App\Services\Availability\AvailabilityProjectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ConnectorController extends Controller
{
    public function __construct(private readonly AvailabilityProjectionService $availabilityProjector) {}

    public function store(StoreConnectorRequest $request, Station $station): JsonResponse
    {
        Gate::authorize('update', $station);
        abort_unless($request->user()->can('connectors.manage'), 403);

        $attributes = $request->validated();
        $attributes['ocpp_connector_id'] ??= ((int) $station->connectors()->max('ocpp_connector_id')) + 1;
        $request->validate([
            'external_id' => ['unique:connectors,external_id,NULL,id,station_id,'.$station->id],
        ]);

        $connector = $station->connectors()->create([
            ...$attributes,
            'status' => $station->isOcppManaged() ? 'unavailable' : $attributes['status'],
            'error_code' => $station->isOcppManaged() ? null : ($attributes['error_code'] ?? null),
            'last_status_at' => now(),
        ]);

        if ($station->isOcppManaged()) {
            $this->availabilityProjector->project($station->id);
        }

        return (new ConnectorResource($connector->fresh()))->response()->setStatusCode(201);
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

        if ($station->isOcppManaged()) {
            $this->availabilityProjector->project($station->id);
        }

        return new ConnectorResource($connector->fresh());
    }

    public function destroy(Request $request, Station $station, Connector $connector): JsonResponse
    {
        Gate::authorize('update', $station);
        abort_unless($request->user()->can('connectors.manage'), 403);
        abort_unless($connector->station_id === $station->id, 404);
        $connector->delete();

        if ($station->isOcppManaged()) {
            $this->availabilityProjector->resolveDeletedConnectorAlert($station, $connector);
            $this->availabilityProjector->project($station->id);
        }

        return response()->json(status: 204);
    }
}
