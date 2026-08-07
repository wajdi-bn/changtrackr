<?php

namespace App\Services;

use App\Data\ResolvedTariff;

class ChargingEstimateService
{
    private const MAX_SEARCHABLE_ENERGY_KWH = 10000.0;

    private const MAX_DURATION_MINUTES = 1440;

    /**
     * @return array{
     *     energy_gross_millimes:int,
     *     discount_millimes:int,
     *     energy_net_millimes:int,
     *     time_cost_millimes:int,
     *     session_fee_millimes:int,
     *     idle_fee_millimes:int,
     *     minimum_charge_millimes:int,
     *     subtotal_millimes:int,
     *     total_millimes:int
     * }
     */
    public function breakdown(
        ResolvedTariff $tariff,
        float $energyKwh,
        int $idleMinutes,
        int $discountBasisPoints,
    ): array {
        $energyGross = (int) round(max(0, $energyKwh) * $tariff->pricePerKwhMillimes);
        $discount = (int) round($energyGross * max(0, $discountBasisPoints) / 10000);
        $energyNet = $energyGross - $discount;
        $idleFee = max(0, $idleMinutes) * $tariff->idleFeePerMinuteMillimes;
        $subtotal = $energyNet + $tariff->sessionFeeMillimes + $idleFee;

        return [
            'energy_gross_millimes' => $energyGross,
            'discount_millimes' => $discount,
            'energy_net_millimes' => $energyNet,
            'time_cost_millimes' => 0,
            'session_fee_millimes' => $tariff->sessionFeeMillimes,
            'idle_fee_millimes' => $idleFee,
            'minimum_charge_millimes' => $tariff->minimumChargeMillimes,
            'subtotal_millimes' => $subtotal,
            'total_millimes' => max($subtotal, $tariff->minimumChargeMillimes),
        ];
    }

    /**
     * Estimates are based on the connector's maximum power. Final billing always uses OCPP meter values.
     *
     * @return array{
     *     target_type:string,
     *     target_value:float,
     *     energy_kwh:float,
     *     duration_minutes:int,
     *     amount_millimes:int,
     *     connector_power_kw:float,
     *     preauthorization_amount_millimes:int,
     *     within_preauthorization:bool,
     *     maximums:array{energy_kwh:float,duration_minutes:int,amount_millimes:int}
     * }
     */
    public function estimate(
        ResolvedTariff $tariff,
        float $connectorPowerKw,
        string $targetType,
        float $targetValue,
        int $idleMinutes,
        int $discountBasisPoints,
        int $preauthorizationAmountMillimes,
    ): array {
        $powerKw = max(0.1, $connectorPowerKw);
        $preauthorization = max(1, $preauthorizationAmountMillimes);

        if ($targetType === 'duration') {
            $durationMinutes = min(self::MAX_DURATION_MINUTES, max(1, (int) round($targetValue)));
            $energyKwh = $this->energyForDuration($durationMinutes, $powerKw);
        } elseif ($targetType === 'amount') {
            $budgetMillimes = max(1, (int) round($targetValue * 1000));
            $energyKwh = $this->maximumEnergyForAmount(
                $tariff,
                min($budgetMillimes, $preauthorization),
                $idleMinutes,
                $discountBasisPoints,
            );
            $durationMinutes = $this->durationForEnergy($energyKwh, $powerKw);
        } else {
            $energyKwh = min(200, max(0.1, round($targetValue, 3)));
            $durationMinutes = $this->durationForEnergy($energyKwh, $powerKw);
        }

        $breakdown = $this->breakdown($tariff, $energyKwh, $idleMinutes, $discountBasisPoints);
        $maximumEnergyKwh = $this->maximumEnergyForAmount(
            $tariff,
            $preauthorization,
            $idleMinutes,
            $discountBasisPoints,
        );

        return [
            'target_type' => $targetType,
            'target_value' => $targetValue,
            'energy_kwh' => $energyKwh,
            'duration_minutes' => $durationMinutes,
            'amount_millimes' => $breakdown['total_millimes'],
            'connector_power_kw' => $powerKw,
            'preauthorization_amount_millimes' => $preauthorization,
            'within_preauthorization' => $breakdown['total_millimes'] <= $preauthorization,
            'maximums' => [
                'energy_kwh' => $maximumEnergyKwh,
                'duration_minutes' => min(
                    self::MAX_DURATION_MINUTES,
                    $this->durationForEnergy($maximumEnergyKwh, $powerKw),
                ),
                'amount_millimes' => $preauthorization,
            ],
        ];
    }

    private function energyForDuration(int $durationMinutes, float $powerKw): float
    {
        return round($powerKw * $durationMinutes / 60, 3);
    }

    private function durationForEnergy(float $energyKwh, float $powerKw): int
    {
        if ($energyKwh <= 0) {
            return 0;
        }

        return min(self::MAX_DURATION_MINUTES, max(1, (int) ceil($energyKwh / $powerKw * 60)));
    }

    private function maximumEnergyForAmount(
        ResolvedTariff $tariff,
        int $amountMillimes,
        int $idleMinutes,
        int $discountBasisPoints,
    ): float {
        if ($this->breakdown($tariff, 0, $idleMinutes, $discountBasisPoints)['total_millimes'] > $amountMillimes) {
            return 0.0;
        }

        $lower = 0.0;
        $upper = self::MAX_SEARCHABLE_ENERGY_KWH;
        for ($iteration = 0; $iteration < 32; $iteration++) {
            $candidate = ($lower + $upper) / 2;
            if ($this->breakdown($tariff, $candidate, $idleMinutes, $discountBasisPoints)['total_millimes'] <= $amountMillimes) {
                $lower = $candidate;
            } else {
                $upper = $candidate;
            }
        }

        $energyKwh = floor($lower * 1000) / 1000;
        while ($energyKwh > 0
            && $this->breakdown($tariff, $energyKwh, $idleMinutes, $discountBasisPoints)['total_millimes'] > $amountMillimes) {
            $energyKwh = round($energyKwh - 0.001, 3);
        }

        return max(0, $energyKwh);
    }
}
