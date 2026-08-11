import assert from 'node:assert/strict'
import test from 'node:test'
import { findStation, summarizeStation, validateAction } from './control-server.mjs'

const payload = {
  chargingStations: [{
    started: true,
    wsState: 1,
    supervisionUrl: 'ws://gateway/ocpp/CT-TUN-001',
    stationInfo: { chargingStationId: 'CT-TUN-001', hashId: 'secret-hash' },
    connectors: [
      { connectorId: 0, connectorStatus: { availability: 'Operative' } },
      { connectorId: 1, connectorStatus: { status: 'Available', availability: 'Operative', transactionStarted: false } },
    ],
  }],
}

test('finds and summarizes a station without returning its internal hash', () => {
  const summary = summarizeStation(findStation(payload, 'CT-TUN-001'))
  assert.deepEqual(summary, {
    identity: 'CT-TUN-001',
    started: true,
    connected: true,
    ws_state: 1,
    supervision_url: 'ws://gateway/ocpp/CT-TUN-001',
    connectors: [{
      connector_id: 1,
      status: 'Available',
      error_code: 'NoError',
      availability: 'Operative',
      transaction_started: false,
    }],
  })
  assert.equal(JSON.stringify(summary).includes('secret-hash'), false)
})

test('validates the closed simulator action catalog', () => {
  assert.doesNotThrow(() => validateAction('connect', null))
  assert.doesNotThrow(() => validateAction('plug', 1))
  assert.throws(() => validateAction('raw_json', 1), /Unsupported/)
  assert.throws(() => validateAction('plug', null), /connector_id/)
})
