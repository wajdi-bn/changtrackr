import assert from 'node:assert/strict'
import test from 'node:test'
import {
  isChargingSessionActive,
  resolveTrackedSession,
  shouldPresentCompletion,
} from '../src/features/charging/sessionLifecycle.ts'
import type { ChargingSession } from '../src/types/charging.ts'

const session = (id: number, status: ChargingSession['status']): ChargingSession => ({ id, status }) as ChargingSession

test('keeps the tracked session visible while it moves from charging to completed', () => {
  const active = session(42, 'charging')
  const completed = session(42, 'completed')

  assert.equal(isChargingSessionActive(active), true)
  assert.equal(resolveTrackedSession(active, active, 42), active)
  assert.equal(resolveTrackedSession(null, completed, 42), completed)
})

test('opens completion once and ignores unrelated historical sessions', () => {
  const completed = session(42, 'completed')

  assert.equal(shouldPresentCompletion(completed, 42, null), true)
  assert.equal(shouldPresentCompletion(completed, 42, 42), false)
  assert.equal(shouldPresentCompletion(completed, 99, null), false)
})
