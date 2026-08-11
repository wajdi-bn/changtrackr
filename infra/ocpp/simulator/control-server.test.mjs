import assert from 'node:assert/strict'
import { mkdtempSync, readFileSync, rmSync } from 'node:fs'
import { tmpdir } from 'node:os'
import path from 'node:path'
import test from 'node:test'
import {
  findStation,
  loadProfiles,
  publicProfiles,
  summarizeStation,
  upsertStationManifest,
  validateAction,
  validateProvisionPayload,
} from './control-server.mjs'

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

test('publishes a safe hardware profile catalog and validates provisioning input', () => {
  const profiles = loadProfiles()
  const published = publicProfiles(profiles)

  assert.equal(published.length, 3)
  assert.equal(published[0].key, 'dc_fast_single')
  assert.equal(Object.hasOwn(published[0], 'template'), false)
  assert.equal(published[1].connectors[1].type, 'CHAdeMO')
  assert.equal(validateProvisionPayload({ identity: 'CT-TUN-200', profile: 'dc_fast_dual' }, profiles).profile.key, 'dc_fast_dual')
  assert.throws(() => validateProvisionPayload({ identity: '../escape', profile: 'dc_fast_dual' }, profiles), /identity/)
  assert.throws(() => validateProvisionPayload({ identity: 'CT-TUN-200', profile: 'unknown' }, profiles), /not supported/)
})

test('persists provisioned simulator stations idempotently for restart recovery', () => {
  const directory = mkdtempSync(path.join(tmpdir(), 'chargetrackr-simulator-'))
  const manifest = path.join(directory, 'stations.json')
  const profile = loadProfiles()[0]

  try {
    upsertStationManifest('CT-TUN-900', profile, manifest)
    upsertStationManifest('CT-TUN-900', profile, manifest)

    const stations = JSON.parse(readFileSync(manifest, 'utf8'))
    assert.equal(stations.length, 1)
    assert.equal(stations[0].identity, 'CT-TUN-900')
    assert.equal(stations[0].profile, profile.key)
    assert.deepEqual(stations[0].connectorPowersKw, profile.connectors.map((connector) => connector.max_power_kw))
  } finally {
    rmSync(directory, { recursive: true, force: true })
  }
})
