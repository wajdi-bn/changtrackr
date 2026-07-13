<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Connector;
use App\Models\Station;
use App\Services\TariffResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class PricingController extends Controller
{
    public function effective(Request $request, Station $station, TariffResolver $resolver): JsonResponse
    {
        Gate::authorize('view', $station);
        $attributes = $request->validate(['connector_id' => ['nullable', 'integer', 'exists:connectors,id']]);
        $connector = isset($attributes['connector_id']) ? Connector::query()->findOrFail($attributes['connector_id']) : null;

        if ($connector && $connector->station_id !== $station->id) {
            throw ValidationException::withMessages(['connector_id' => ['The connector does not belong to this station.']]);
        }

        $tariff = $resolver->resolve($station, $connector);

        return response()->json(['data' => [
            'id' => $tariff->id,
            'name' => $tariff->name,
            'source' => $tariff->source,
            'currency' => $tariff->currency,
            'price_per_kwh_millimes' => $tariff->pricePerKwhMillimes,
            'session_fee_millimes' => $tariff->sessionFeeMillimes,
            'idle_fee_per_minute_millimes' => $tariff->idleFeePerMinuteMillimes,
            'minimum_charge_millimes' => $tariff->minimumChargeMillimes,
        ]]);
    }
}
