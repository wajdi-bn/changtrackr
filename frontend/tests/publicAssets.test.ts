import assert from 'node:assert/strict'
import { existsSync, readFileSync, statSync } from 'node:fs'
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
  'assets/seo/charge-trackr-social.webp',
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

test('ships crawl directives, a single-page sitemap and social metadata', () => {
  const index = readFileSync(resolve(publicRoot, '../index.html'), 'utf8')
  const landing = readFileSync(resolve(publicRoot, '../src/pages/LandingPage.tsx'), 'utf8')
  const robots = readFileSync(resolve(publicRoot, 'robots.txt'), 'utf8')
  const sitemap = readFileSync(resolve(publicRoot, 'sitemap.xml'), 'utf8')
  const manifest = JSON.parse(readFileSync(resolve(publicRoot, 'site.webmanifest'), 'utf8')) as { start_url?: string }
  const nginx = readFileSync(resolve(publicRoot, '../docker/nginx.conf'), 'utf8')

  assert.match(index, /<link rel="canonical" href="https:\/\/chargetrackr\.me\/"/)
  assert.match(index, /<meta name="robots" content="index, follow/)
  assert.match(index, /property="og:image" content="https:\/\/chargetrackr\.me\/assets\/seo\/charge-trackr-social\.webp"/)
  assert.match(index, /type="application\/ld\+json"/)
  assert.match(landing, /<h1>EV Charging\.<br \/>Managed\.<br \/>Better\.<\/h1>/)
  assert.match(robots, /Sitemap: https:\/\/chargetrackr\.me\/sitemap\.xml/)
  assert.match(sitemap, /<loc>https:\/\/chargetrackr\.me\/<\/loc>/)
  assert.equal((sitemap.match(/<url>/g) ?? []).length, 1)
  assert.equal(manifest.start_url, '/')
  assert.match(nginx, /map \$request_uri \$chargetrackr_robots/)
  assert.match(nginx, /default "noindex, nofollow, noarchive"/)
  assert.match(nginx, /add_header X-Robots-Tag \$chargetrackr_robots always/)
})

test('ships the local Inter font and removes obsolete root media', () => {
  assert.equal(existsSync(resolve(publicRoot, 'assets/fonts/inter/inter-variable.woff2')), true)

  for (const relativePath of ['assets/Logo.png', 'assets/charge-hero.png', 'assets/charger-terra-hp-150.png']) {
    assert.equal(existsSync(resolve(publicRoot, relativePath)), false, `${relativePath} should not be shipped`)
  }
})
