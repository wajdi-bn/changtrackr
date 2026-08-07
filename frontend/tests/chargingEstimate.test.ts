import assert from 'node:assert/strict'
import test from 'node:test'
import { buildChargingLimitPayload, linkedTargetValues } from '../src/features/charging/chargingEstimate.ts'

test('maps the authoritative charging target to exactly one backend limit', () => {
  assert.deepEqual(buildChargingLimitPayload('energy', 12.3456), { limit_energy_kwh: 12.346 })
  assert.deepEqual(buildChargingLimitPayload('duration', 19.6), { limit_duration_minutes: 20 })
  assert.deepEqual(buildChargingLimitPayload('amount', 14.9996), { limit_amount_tnd: 15 })
})

test('keeps the active value while using server-derived linked estimates', () => {
  const estimate = {
    target_type: 'energy' as const,
    target_value: 10,
    energy_kwh: 10,
    duration_minutes: 5,
    amount_millimes: 10500,
    connector_power_kw: 120,
    preauthorization_amount_millimes: 30000,
    within_preauthorization: true,
    maximums: { energy_kwh: 29.5, duration_minutes: 15, amount_millimes: 30000 },
  }

  assert.deepEqual(linkedTargetValues(estimate, 'duration', 8), {
    energy: 10,
    duration: 8,
    amount: 10.5,
  })
})
