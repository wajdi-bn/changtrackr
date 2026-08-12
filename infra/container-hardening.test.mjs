import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'

const dockerfiles = [
  new URL('../backend/Dockerfile', import.meta.url),
  new URL('../frontend/Dockerfile', import.meta.url),
  new URL('../ocpp-gateway/Dockerfile', import.meta.url),
  new URL('./ocpp/simulator/Dockerfile', import.meta.url),
]

test('external Docker base images are pinned to immutable digests', async () => {
  for (const dockerfile of dockerfiles) {
    const content = await readFile(dockerfile, 'utf8')
    const externalImages = [...content.matchAll(/^FROM\s+(\S+)/gim)]
      .map((match) => match[1])
      .filter((image) => image.includes(':') || image.includes('/'))

    assert.ok(externalImages.length > 0, `${dockerfile.pathname} must declare an external base image`)
    for (const image of externalImages) {
      assert.match(image, /^[^@\s]+@sha256:[a-f0-9]{64}$/)
    }
  }
})

test('frontend image requires the public Reverb application key without embedding a secret', async () => {
  const frontendDockerfile = await readFile(new URL('../frontend/Dockerfile', import.meta.url), 'utf8')

  assert.match(frontendDockerfile, /ARG VITE_REVERB_APP_KEY\r?\n/)
  assert.doesNotMatch(frontendDockerfile, /VITE_REVERB_APP_KEY=local-key/)
  assert.match(frontendDockerfile, /test -n "\$VITE_REVERB_APP_KEY"/)
  assert.doesNotMatch(frontendDockerfile, /REVERB_APP_SECRET/)
})

test('frontend image accepts every production public endpoint at build time', async () => {
  const frontendDockerfile = await readFile(new URL('../frontend/Dockerfile', import.meta.url), 'utf8')

  for (const variable of [
    'VITE_API_URL',
    'VITE_BACKEND_URL',
    'VITE_REVERB_HOST',
    'VITE_REVERB_PORT',
    'VITE_REVERB_SCHEME',
    'VITE_QR_APP_URL',
  ]) {
    assert.match(frontendDockerfile, new RegExp(`ARG ${variable}`))
    assert.match(frontendDockerfile, new RegExp(`${variable}=\\$${variable}`))
  }
})
