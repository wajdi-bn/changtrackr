import assert from 'node:assert/strict'
import test from 'node:test'
import { createIdempotencyKey } from '../src/lib/idempotency.ts'

test('creates RFC 4122 version 4 idempotency keys', () => {
  const key = createIdempotencyKey()

  assert.match(key, /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/)
})

test('creates a fresh key for each operation', () => {
  const keys = new Set(Array.from({ length: 50 }, () => createIdempotencyKey()))

  assert.equal(keys.size, 50)
})
