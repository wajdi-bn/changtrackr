import assert from 'node:assert/strict'
import test from 'node:test'
import { connectorEvents, signalLabel, signalTone } from '../src/features/stations/simulationLab.ts'
import type { OcppSimulatorSignalEvent } from '../src/types/station.ts'

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
