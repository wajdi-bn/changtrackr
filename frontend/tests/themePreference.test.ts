import assert from 'node:assert/strict'
import test from 'node:test'
import { normalizeThemeMode, resolveThemeMode } from '../src/features/theme/themePreference.ts'

test('normalizes persisted theme values safely', () => {
  assert.equal(normalizeThemeMode('light'), 'light')
  assert.equal(normalizeThemeMode('dark'), 'dark')
  assert.equal(normalizeThemeMode('system'), 'system')
  assert.equal(normalizeThemeMode('unknown'), 'system')
  assert.equal(normalizeThemeMode(null), 'system')
})

test('resolves explicit and system theme preferences', () => {
  assert.equal(resolveThemeMode('light', true), 'light')
  assert.equal(resolveThemeMode('dark', false), 'dark')
  assert.equal(resolveThemeMode('system', true), 'dark')
  assert.equal(resolveThemeMode('system', false), 'light')
})
