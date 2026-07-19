import assert from 'node:assert/strict'
import path from 'node:path'
import test from 'node:test'
import { spawnSync } from 'node:child_process'
import { fileURLToPath } from 'node:url'

const directory = path.dirname(fileURLToPath(import.meta.url))
const reader = path.join(directory, 'read-station-state.mjs')
const payload = JSON.stringify({
  chargingStations: [
    {
      connectors: [{ connectorId: 1, connectorStatus: { status: 'Available' } }],
      stationInfo: { chargingStationId: 'CT-TUN-001', hashId: 'hash-one' },
    },
    {
      connectors: [{ connectorId: 1, connectorStatus: { status: 'Charging', transactionId: 14 } }],
      stationInfo: { chargingStationId: 'CT-HAM-031', hashId: 'hash-nine' },
    },
  ],
})

function read(identity, field) {
  return spawnSync(process.execPath, [reader, identity, field], {
    encoding: 'utf8',
    input: payload,
  })
}

test('selects the requested station instead of the first station in the response', () => {
  const result = read('CT-HAM-031', 'hashId')

  assert.equal(result.status, 0)
  assert.equal(result.stdout, 'hash-nine')
})

test('reads a connector transaction from the requested station object', () => {
  const result = read('CT-HAM-031', 'transactionId')

  assert.equal(result.status, 0)
  assert.equal(result.stdout, '14')
})
