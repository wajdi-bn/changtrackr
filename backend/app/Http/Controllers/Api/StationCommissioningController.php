<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Stations\CommissionStationRequest;
use App\Http\Resources\StationResource;
use App\Models\Station;
use App\Models\User;
use App\Services\StationCommissioningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class StationCommissioningController extends Controller
{
    public function __construct(private readonly StationCommissioningService $commissioning) {}

    public function store(CommissionStationRequest $request): JsonResponse
    {
        Gate::authorize('create', Station::class);

        /** @var User $actor */
        $actor = $request->user();
        $result = $this->commissioning->create($actor, $request->validated());

        return response()->json([
            'data' => new StationResource($result['station']),
            'commissioning' => $result['commissioning'],
        ], 201);
    }

    public function rotateCredentials(Request $request, Station $station): JsonResponse
    {
        Gate::authorize('update', $station);
        abort_unless($request->user()->can('connectors.manage'), 403);

        if ($station->ocpp_commissioning_target === 'simulator') {
            throw ValidationException::withMessages([
                'commissioning_target' => [
                    'Simulator credentials are managed by the local fleet tooling and cannot be rotated individually.',
                ],
            ]);
        }

        /** @var User $actor */
        $actor = $request->user();
        $result = $this->commissioning->rotateExternalCredentials($actor, $station);

        return response()->json([
            'data' => new StationResource($result['station']),
            'commissioning' => $result['commissioning'],
        ]);
    }
}
