<?php

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Controller;
use App\Http\Requests\Ocpp\AuthenticateStationRequest;
use App\Http\Requests\Ocpp\ClaimOcppCommandRequest;
use App\Http\Requests\Ocpp\CompleteOcppCommandRequest;
use App\Http\Requests\Ocpp\IngestOcppEventRequest;
use App\Models\OcppCommand;
use App\Services\Ocpp\OcppCommandService;
use App\Services\Ocpp\OcppEventIngestionService;
use App\Services\Ocpp\OcppStationAuthenticationService;
use Illuminate\Http\JsonResponse;

class OcppGatewayController extends Controller
{
    public function authenticate(
        AuthenticateStationRequest $request,
        OcppStationAuthenticationService $authentication,
    ): JsonResponse {
        $attributes = $request->validated();
        $station = $authentication->authenticate(
            $attributes['station_identity'],
            $attributes['username'],
            $attributes['password'],
        );

        if ($station === null) {
            return response()->json(['message' => 'Station authentication failed.'], 401);
        }

        return response()->json([
            'authenticated' => true,
            'station' => [
                'id' => $station->id,
                'identity' => $station->ocpp_identity,
                'protocol_version' => '1.6',
            ],
        ]);
    }

    public function ingest(
        IngestOcppEventRequest $request,
        OcppEventIngestionService $ingestion,
    ): JsonResponse {
        $attributes = $request->validated();
        $attributes['payload'] = $request->input('payload', []);
        $result = $ingestion->ingest($attributes);

        return response()->json([
            'accepted' => true,
            'duplicate' => $result['duplicate'],
            'event_id' => $result['event']->event_id,
            'processing_status' => $result['event']->processing_status,
            'ocpp_response' => $result['event']->response_payload ?? [],
        ], $result['duplicate'] ? 200 : 201);
    }

    public function claimCommand(ClaimOcppCommandRequest $request, OcppCommandService $commands): JsonResponse
    {
        $attributes = $request->validated();
        $command = $commands->claim($attributes['station_identity'], $attributes['connection_id']);

        if ($command === null) {
            return response()->json(['command' => null]);
        }

        return response()->json(['command' => [
            'uuid' => $command->uuid,
            'action' => $command->action,
            'payload' => $command->encrypted_payload,
        ]]);
    }

    public function completeCommand(
        CompleteOcppCommandRequest $request,
        OcppCommand $ocppCommand,
        OcppCommandService $commands,
    ): JsonResponse {
        $attributes = $request->validated();
        $command = $commands->complete(
            $ocppCommand,
            $attributes['connection_id'],
            $attributes['status'],
            $attributes['result'] ?? [],
            $attributes['message'] ?? null,
        );

        return response()->json(['command' => ['uuid' => $command->uuid, 'status' => $command->status]]);
    }
}
