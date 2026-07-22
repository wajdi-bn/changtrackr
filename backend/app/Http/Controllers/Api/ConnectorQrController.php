<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Connector;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ConnectorQrController extends Controller
{
    public function show(Request $request, string $token): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->hasRole('client'), 403);

        $connector = Connector::query()
            ->where('qr_token', $token)
            ->with('station')
            ->firstOrFail();

        Gate::authorize('view', $connector->station);

        return response()->json([
            'data' => [
                'station_id' => $connector->station_id,
                'connector_id' => $connector->id,
                'station_name' => $connector->station->name,
                'connector_external_id' => $connector->external_id,
            ],
        ]);
    }
}
