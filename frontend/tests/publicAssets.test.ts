import assert from 'node:assert/strict'
import { existsSync, statSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import test from 'node:test'
import { fileURLToPath } from 'node:url'

const publicRoot = resolve(dirname(fileURLToPath(import.meta.url)), '../public')

const optimizedImages = [
  'assets/branding/charge-trackr-logo.webp',
  'assets/charging/active-session.webp',
  'assets/landing/charging-network-hero.webp',
  'assets/landing/ev-charging-hub.webp',
  'assets/landing/ev-operations-desk.webp',
  'assets/landing/ev-route-corridor.webp',
  'assets/landing/ev-technician.webp',
  'assets/stations/models/delta-ufc-100.webp',
  'assets/stations/models/enext-park-dc.webp',
  'assets/stations/models/evbox-troniq.webp',
  'assets/stations/models/powerdot-dc-120.webp',
  'assets/stations/models/raption-100.webp',
  'assets/stations/models/sicharge-d.webp',
  'assets/stations/models/terra-hp-150.webp',
  'assets/stations/models/tritium-rtm50.webp',
]

test('ships every optimized public image within its performance budget', () => {
  for (const relativePath of optimizedImages) {
    const path = resolve(publicRoot, relativePath)
    assert.equal(existsSync(path), true, `${relativePath} should exist`)
    assert.ok(statSync(path).size < 250_000, `${relativePath} should remain below 250 KB`)
  }
})

test('ships the local Inter font and removes obsolete root media', () => {
  assert.equal(existsSync(resolve(publicRoot, 'assets/fonts/inter/inter-variable.woff2')), true)

  for (const relativePath of ['assets/Logo.png', 'assets/charge-hero.png', 'assets/charger-terra-hp-150.png']) {
    assert.equal(existsSync(resolve(publicRoot, relativePath)), false, `${relativePath} should not be shipped`)
  }
})
