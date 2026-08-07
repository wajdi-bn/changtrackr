import type { ChargingAttemptPayload } from '../../types/charging'
import type { ChargingTargetType, PricingSimulation } from '../../types/tariff'

export type ChargingLimitPayload = Pick<
  ChargingAttemptPayload,
  'limit_energy_kwh' | 'limit_amount_tnd' | 'limit_duration_minutes'
>

export function buildChargingLimitPayload(type: ChargingTargetType, value: number): ChargingLimitPayload {
  if (type === 'duration') {
    return { limit_duration_minutes: Math.max(1, Math.round(value)) }
  }
  if (type === 'amount') {
    return { limit_amount_tnd: Math.max(1, round(value, 3)) }
  }

  return { limit_energy_kwh: Math.max(0.1, round(value, 3)) }
}

export function linkedTargetValues(
  estimate: PricingSimulation['estimate'] | undefined | null,
  activeType: ChargingTargetType,
  activeValue: number,
): Record<ChargingTargetType, number> {
  return {
    energy: activeType === 'energy' ? activeValue : estimate?.energy_kwh ?? 0,
    duration: activeType === 'duration' ? activeValue : estimate?.duration_minutes ?? 0,
    amount: activeType === 'amount' ? activeValue : (estimate?.amount_millimes ?? 0) / 1000,
  }
}

function round(value: number, precision: number): number {
  const factor = 10 ** precision
  return Math.round(value * factor) / factor
}
