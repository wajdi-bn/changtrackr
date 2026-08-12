import assert from 'node:assert/strict'
import test from 'node:test'
import { canRunSimulatorAction, connectorEvents, signalLabel, signalTone, simulationAccessLabel } from '../src/features/stations/simulationLab.ts'
import type { OcppSimulatorConsoleResponse, OcppSimulatorSignalEvent } from '../src/types/station.ts'

const event = (connectorId: number | null, action = 'StatusNotification', status: string | null = 'Available'): OcppSimulatorSignalEvent => ({
  id: `${connectorId}-${action}`,
  action,
  category: action === 'Heartbeat' ? 'heartbeat' : 'status',
  connector_id: connectorId,
  status,
  error_code: 'NoError',
  processing_status: 'processed',
  occurred_at: '2026-08-12T10:00:00Z',
  received_at: '2026-08-12T10:00:00Z',
})

test('keeps connector pulse streams isolated', () => {
  const events = [event(1), event(2), event(null, 'Heartbeat', null)]
  assert.deepEqual(connectorEvents(events, 1).map((item) => item.connector_id), [1])
})

test('maps protocol events to concise labels and operational tones', () => {
  assert.equal(signalLabel(event(1)), 'Connector status: Available')
  assert.equal(signalTone('status', 'Faulted', 'ConnectorLockFailure'), 'danger')
  assert.equal(signalTone('heartbeat'), 'success')
})

test('keeps technician diagnostics separate from station lifecycle control', () => {
  const capabilities: OcppSimulatorConsoleResponse['capabilities'] = {
    view: true,
    diagnose: true,
    control: false,
    central_commands: false,
    allowed_actions: ['heartbeat', 'plug', 'unplug', 'inject_fault', 'recover', 'normal_cycle', 'fault_recovery'],
  }

  assert.equal(simulationAccessLabel(capabilities), 'Diagnostic access')
  assert.equal(canRunSimulatorAction(capabilities, 'plug'), true)
  assert.equal(canRunSimulatorAction(capabilities, 'disconnect'), false)
})
