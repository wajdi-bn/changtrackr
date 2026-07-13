<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tariffs\AssignTariffRequest;
use App\Http\Resources\TariffResource;
use App\Models\Connector;
use App\Models\Station;
use App\Models\Tariff;
use App\Models\TariffAssignment;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class TariffAssignmentController extends Controller
{
    public function store(AssignTariffRequest $request, Tariff $tariff): JsonResponse
    {
        Gate::authorize('assign', $tariff);
        $attributes = $request->validated();

        if (isset($attributes['station_id'])) {
            $station = Station::query()->findOrFail($attributes['station_id']);
            $this->assertOrganization($tariff, $station->organization_id);
            TariffAssignment::query()->updateOrCreate(
                ['station_id' => $station->id],
                ['tariff_id' => $tariff->id, 'connector_id' => null],
            );
        } else {
            $connector = Connector::query()->with('station')->findOrFail($attributes['connector_id']);
            $this->assertOrganization($tariff, $connector->station->organization_id);
            TariffAssignment::query()->updateOrCreate(
                ['connector_id' => $connector->id],
                ['tariff_id' => $tariff->id, 'station_id' => null],
            );
        }

        return (new TariffResource($tariff->fresh()->load(['assignments.station', 'assignments.connector.station'])))
            ->response()
            ->setStatusCode(201);
    }

    public function destroy(TariffAssignment $tariffAssignment): JsonResponse
    {
        $tariff = $tariffAssignment->tariff;
        Gate::authorize('assign', $tariff);
        $tariffAssignment->delete();

        return response()->json(status: 204);
    }

    private function assertOrganization(Tariff $tariff, int $organizationId): void
    {
        if ($tariff->organization_id !== $organizationId) {
            throw ValidationException::withMessages(['assignment' => ['The target does not belong to the tariff organization.']]);
        }
    }
}
