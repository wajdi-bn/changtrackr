<?php

namespace App\Services;

use App\Data\ResolvedTariff;
use App\Models\Connector;
use App\Models\Station;
use App\Models\Tariff;
use Illuminate\Database\Eloquent\Builder;

class TariffResolver
{
    public function resolve(Station $station, ?Connector $connector = null): ResolvedTariff
    {
        $tariff = $connector ? $this->assignedTariff('connector_id', $connector->id) : null;
        $source = $tariff ? 'connector' : null;

        if (! $tariff) {
            $tariff = $this->assignedTariff('station_id', $station->id);
            $source = $tariff ? 'station' : null;
        }

        if (! $tariff) {
            $tariff = $this->activeTariffs()
                ->where('organization_id', $station->organization_id)
                ->where('is_default', true)
                ->orderByDesc('id')
                ->first();
            $source = $tariff ? 'organization_default' : null;
        }

        return $tariff
            ? new ResolvedTariff(
                id: $tariff->id,
                name: $tariff->name,
                source: $source ?? 'organization_default',
                currency: $tariff->currency,
                pricePerKwhMillimes: $tariff->price_per_kwh_millimes,
                sessionFeeMillimes: $tariff->session_fee_millimes,
                idleFeePerMinuteMillimes: $tariff->idle_fee_per_minute_millimes,
                minimumChargeMillimes: $tariff->minimum_charge_millimes,
            )
            : new ResolvedTariff(
                id: null,
                name: 'Fallback tariff',
                source: 'configuration_fallback',
                currency: 'TND',
                pricePerKwhMillimes: config('charging.price_per_kwh_millimes'),
                sessionFeeMillimes: config('charging.session_fee_millimes'),
                idleFeePerMinuteMillimes: 0,
                minimumChargeMillimes: 0,
            );
    }

    private function assignedTariff(string $column, int $id): ?Tariff
    {
        return $this->activeTariffs()
            ->whereHas('assignments', fn (Builder $query) => $query->where($column, $id))
            ->orderByDesc('id')
            ->first();
    }

    private function activeTariffs(): Builder
    {
        return Tariff::query()
            ->where('status', 'active')
            ->where(fn (Builder $query) => $query->whereNull('valid_from')->orWhere('valid_from', '<=', now()))
            ->where(fn (Builder $query) => $query->whereNull('valid_until')->orWhere('valid_until', '>=', now()));
    }
}
