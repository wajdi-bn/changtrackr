import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import path from 'node:path'
import test from 'node:test'
import { fileURLToPath } from 'node:url'

const directory = path.dirname(fileURLToPath(import.meta.url))

test('uses responsive local telemetry without changing heartbeat supervision', async () => {
  const template = JSON.parse(await readFile(path.join(directory, 'chargetrackr.station-template.json'), 'utf8'))
  const settings = Object.fromEntries(
    template.Configuration.configurationKey.map((entry) => [entry.key, entry.value]),
  )

  assert.equal(settings.MeterValueSampleInterval, '2')
  assert.equal(settings.HeartbeatInterval, '30')
})
