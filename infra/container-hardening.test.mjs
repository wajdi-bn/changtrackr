import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'

const dockerfiles = [
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
