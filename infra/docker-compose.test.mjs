import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'

const compose = await readFile(new URL('./docker-compose.yml', import.meta.url), 'utf8')
const example = await readFile(new URL('./.env.example', import.meta.url), 'utf8')

test('database services are published only on loopback', () => {
  assert.match(compose, /127\.0\.0\.1:\$\{POSTGRES_FORWARD_PORT:-5432\}:5432/)
  assert.match(compose, /127\.0\.0\.1:\$\{REDIS_FORWARD_PORT:-6379\}:6379/)
  assert.doesNotMatch(compose, /^\s*-\s*["']?5432:5432["']?\s*$/m)
  assert.doesNotMatch(compose, /^\s*-\s*["']?6379:6379["']?\s*$/m)
})

test('database secrets are required and have no committed defaults', () => {
  assert.match(compose, /POSTGRES_PASSWORD:\s*\$\{POSTGRES_PASSWORD:\?[^}]+\}/)
  assert.match(compose, /REDIS_PASSWORD:\s*\$\{REDIS_PASSWORD:\?[^}]+\}/)
  assert.doesNotMatch(compose, /POSTGRES_PASSWORD:\s*chargetrackr/)
  assert.doesNotMatch(compose, /(?:POSTGRES|REDIS)_PASSWORD:\s*\$\{[^}:]+:-[^}]+\}/)
  assert.match(example, /^POSTGRES_PASSWORD=\s*$/m)
  assert.match(example, /^REDIS_PASSWORD=\s*$/m)
})

test('Redis requires authentication and persists its data', () => {
  assert.match(compose, /redis-server[^\n]+--requirepass/)
  assert.match(compose, /redis_data:\/data/)
  assert.match(compose, /redis-cli[^\n]+REDIS_PASSWORD[^\n]+ping/)
})

test('local infrastructure UIs are not exposed to the LAN', () => {
  assert.match(compose, /127\.0\.0\.1:\$\{MAILPIT_SMTP_FORWARD_PORT:-1025\}:1025/)
  assert.match(compose, /127\.0\.0\.1:\$\{MAILPIT_UI_FORWARD_PORT:-8025\}:8025/)
})
