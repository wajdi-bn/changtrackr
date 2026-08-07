import assert from 'node:assert/strict'
import test from 'node:test'
import { safeInternalPath } from '../src/utils/navigation.ts'

test('accepts internal application paths with query strings and fragments', () => {
  assert.equal(safeInternalPath('/alerts?status=new#latest'), '/alerts?status=new#latest')
})

test('rejects external, protocol-relative and malformed destinations', () => {
  assert.equal(safeInternalPath('https://example.com/alerts'), null)
  assert.equal(safeInternalPath('//example.com/alerts'), null)
  assert.equal(safeInternalPath('/\\example.com/alerts'), null)
  assert.equal(safeInternalPath(null), null)
})
