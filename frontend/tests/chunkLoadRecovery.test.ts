import assert from 'node:assert/strict'
import test from 'node:test'
import { shouldReloadAfterChunkFailure } from '../src/app/chunkLoadRecovery.ts'

test('reloads when no previous chunk recovery was recorded', () => {
  assert.equal(shouldReloadAfterChunkFailure(null, 100_000), true)
})

test('prevents a reload loop inside the recovery cooldown', () => {
  assert.equal(shouldReloadAfterChunkFailure('90000', 100_000), false)
})

test('allows recovery again after a later deployment', () => {
  assert.equal(shouldReloadAfterChunkFailure('60000', 100_001), true)
})

test('recovers when the stored timestamp is invalid', () => {
  assert.equal(shouldReloadAfterChunkFailure('invalid', 100_000), true)
})
