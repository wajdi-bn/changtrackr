import assert from 'node:assert/strict'
import test from 'node:test'
import { canInsertVirtualCable, resolveClientTerminalState } from '../src/features/charging/clientChargingTerminal.ts'

test('offers cable insertion only while OCPP reports an available connector', () => {
  assert.equal(resolveClientTerminalState('Available'), 'ready')
  assert.equal(canInsertVirtualCable('ready'), true)
  assert.equal(canInsertVirtualCable('connected'), false)
})

test('waits for OCPP proof after queuing the virtual physical action', () => {
  assert.equal(resolveClientTerminalState('Available', 'queued'), 'waiting_ocpp')
  assert.equal(resolveClientTerminalState('Available', 'running'), 'waiting_ocpp')
  assert.equal(resolveClientTerminalState('Available', 'succeeded'), 'waiting_ocpp')
  assert.equal(resolveClientTerminalState('Preparing', 'succeeded'), 'connected')
  assert.equal(resolveClientTerminalState('Available', 'succeeded', '2026-08-12T12:00:00.000Z', Date.parse('2026-08-12T12:00:13.000Z')), 'failed')
  assert.equal(resolveClientTerminalState('Available', 'failed'), 'failed')
  assert.equal(canInsertVirtualCable('failed'), true)
  assert.equal(resolveClientTerminalState('Faulted'), 'unavailable')
})
