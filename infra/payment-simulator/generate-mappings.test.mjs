import assert from 'node:assert/strict'
import { mkdtemp, mkdir, readFile, rm, writeFile } from 'node:fs/promises'
import os from 'node:os'
import path from 'node:path'
import test from 'node:test'
import { fileURLToPath } from 'node:url'
import {
  generateMappings,
  readEnvValue,
  upsertEnvValue,
} from './generate-mappings.mjs'

const directory = path.dirname(fileURLToPath(import.meta.url))
const repositoryRoot = path.resolve(directory, '..', '..')

test('generates WireMock mappings without modifying the templates', async () => {
  const temporaryDirectory = await mkdtemp(path.join(os.tmpdir(), 'payment-mappings-'))
  const targetDirectory = path.join(temporaryDirectory, 'generated')
  const apiKey = 'quote"slash\\dollar$pipe|amp&'.padEnd(40, 'x')

  try {
    const count = await generateMappings(path.join(directory, 'mappings'), targetDirectory, apiKey)
    const generated = JSON.parse(await readFile(path.join(targetDirectory, 'payment-operation-success.json'), 'utf8'))
    const template = await readFile(path.join(directory, 'mappings', 'payment-operation-success.json'), 'utf8')

    assert.equal(count, 4)
    assert.equal(generated.request.headers['X-Simulator-Api-Key'].equalTo, apiKey)
    assert.match(template, /__PAYMENT_SIMULATOR_API_KEY__/)
    assert.doesNotMatch(template, new RegExp(apiKey.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')))
  } finally {
    await rm(temporaryDirectory, { force: true, recursive: true })
  }
})

test('rejects weak simulator keys', async () => {
  const temporaryDirectory = await mkdtemp(path.join(os.tmpdir(), 'payment-mappings-'))

  try {
    await assert.rejects(
      generateMappings(path.join(directory, 'mappings'), path.join(temporaryDirectory, 'generated'), 'weak-local-key'),
      /at least 32 characters/,
    )
  } finally {
    await rm(temporaryDirectory, { force: true, recursive: true })
  }
})

test('keeps committed configuration free of a payment API key default', async () => {
  const envExample = await readFile(path.join(repositoryRoot, 'backend', '.env.example'), 'utf8')
  const paymentConfig = await readFile(path.join(repositoryRoot, 'backend', 'config', 'payments.php'), 'utf8')

  assert.equal(readEnvValue(envExample, 'PAYMENT_SIMULATOR_API_KEY'), '')
  assert.match(paymentConfig, /env\('PAYMENT_SIMULATOR_API_KEY'\)/)
})

test('updates an existing env value without duplicating it', async () => {
  const content = 'APP_ENV=local\nPAYMENT_SIMULATOR_API_KEY=old\n'
  const updated = upsertEnvValue(content, 'PAYMENT_SIMULATOR_API_KEY', 'new-value')

  assert.equal(readEnvValue(updated, 'PAYMENT_SIMULATOR_API_KEY'), 'new-value')
  assert.equal(updated.match(/PAYMENT_SIMULATOR_API_KEY=/g)?.length, 1)
})
