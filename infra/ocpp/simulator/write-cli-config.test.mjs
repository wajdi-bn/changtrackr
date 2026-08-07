import assert from 'node:assert/strict'
import { mkdtemp, readFile, rm } from 'node:fs/promises'
import os from 'node:os'
import path from 'node:path'
import { spawnSync } from 'node:child_process'
import test from 'node:test'
import { fileURLToPath } from 'node:url'

const directory = path.dirname(fileURLToPath(import.meta.url))
const writer = path.join(directory, 'write-cli-config.mjs')
const template = path.join(directory, 'cli-config-template.json')

test('writes special characters as a JSON value without shell interpolation', async () => {
  const temporaryDirectory = await mkdtemp(path.join(os.tmpdir(), 'ocpp-cli-config-'))
  const output = path.join(temporaryDirectory, 'config.json')
  const password = 'quote" slash\\ dollar$ pipe| amp& newline\nsecond-line'

  try {
    const result = spawnSync(process.execPath, [writer, template, output], {
      encoding: 'utf8',
      env: { ...process.env, OCPP_SIMULATOR_UI_PASSWORD: password },
    })

    assert.equal(result.status, 0, result.stderr)
    const config = JSON.parse(await readFile(output, 'utf8'))
    assert.equal(config.uiServer.authentication.password, password)
    assert.doesNotMatch(result.stdout, new RegExp(password.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')))
  } finally {
    await rm(temporaryDirectory, { force: true, recursive: true })
  }
})

test('rejects configuration generation when the password is missing', () => {
  const result = spawnSync(process.execPath, [writer, template, 'unused.json'], {
    encoding: 'utf8',
    env: { ...process.env, OCPP_SIMULATOR_UI_PASSWORD: '' },
  })

  assert.notEqual(result.status, 0)
  assert.match(result.stderr, /OCPP_SIMULATOR_UI_PASSWORD is required/)
})
