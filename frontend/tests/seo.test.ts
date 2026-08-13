import assert from 'node:assert/strict'
import test from 'node:test'
import {
  PRIVATE_ROBOTS,
  PUBLIC_ROBOTS,
  SITE_URL,
  resolveSeoConfig,
} from '../src/seo/seoConfig.ts'

test('indexes only the public landing page with its canonical URL', () => {
  const landing = resolveSeoConfig('/')

  assert.equal(landing.indexable, true)
  assert.equal(landing.canonical, `${SITE_URL}/`)
  assert.equal(landing.robots, PUBLIC_ROBOTS)
})

test('keeps authentication and application routes out of search results', () => {
  for (const path of ['/login', '/register', '/overview', '/stations/42', '/simulation-lab']) {
    const config = resolveSeoConfig(path)
    assert.equal(config.indexable, false, `${path} must remain private`)
    assert.equal(config.canonical, null)
    assert.equal(config.robots, PRIVATE_ROBOTS)
  }
})

test('normalizes a trailing slash without exposing a second public URL', () => {
  assert.equal(resolveSeoConfig('///').indexable, true)
  assert.equal(resolveSeoConfig('/login/').indexable, false)
})
