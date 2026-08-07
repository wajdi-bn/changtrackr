import assert from 'node:assert/strict'
import test from 'node:test'
import { hasAnyRole, resolvePrimaryRole } from '../src/features/auth/roleResolution.ts'

test('resolves a deterministic primary role independently of API order', () => {
  assert.equal(resolvePrimaryRole(['client', 'admin']), 'admin')
  assert.equal(resolvePrimaryRole(['technician', 'super_admin', 'operator']), 'super_admin')
})

test('returns no primary role for missing assignments', () => {
  assert.equal(resolvePrimaryRole([]), null)
  assert.equal(resolvePrimaryRole(null), null)
})

test('authorizes against every assigned role instead of only the primary role', () => {
  assert.equal(hasAnyRole(['admin', 'technician'], ['technician']), true)
  assert.equal(hasAnyRole(['admin', 'technician'], ['client']), false)
})
