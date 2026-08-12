import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'

const compose = await readFile(new URL('./docker-compose.yml', import.meta.url), 'utf8')
const example = await readFile(new URL('./.env.example', import.meta.url), 'utf8')
const backendDockerignore = await readFile(new URL('../backend/.dockerignore', import.meta.url), 'utf8')
const backendDockerfile = await readFile(new URL('../backend/Dockerfile', import.meta.url), 'utf8')
const frontendDockerfile = await readFile(new URL('../frontend/Dockerfile', import.meta.url), 'utf8')
const frontendNginx = await readFile(new URL('../frontend/docker/nginx.conf', import.meta.url), 'utf8')
const pdfPreview = await readFile(new URL('../frontend/src/features/documents/PdfCanvasPreview.tsx', import.meta.url), 'utf8')
const backendNginx = await readFile(new URL('../backend/docker/nginx.conf', import.meta.url), 'utf8')

test('managed third-party services are pinned to immutable image digests', () => {
  for (const image of ['postgres:18', 'redis:7-alpine', 'axllent/mailpit:latest', 'wiremock/wiremock:3.13.1']) {
    const escaped = image.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
    assert.match(compose, new RegExp(`image: ${escaped}@sha256:[a-f0-9]{64}`))
  }
})

test('database services are published only on loopback', () => {
  assert.match(compose, /127\.0\.0\.1:\$\{POSTGRES_FORWARD_PORT:-5433\}:5432/)
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

test('the complete application stack declares each long-running process separately', () => {
  for (const service of ['backend-php', 'backend', 'queue-worker', 'scheduler', 'reverb', 'frontend']) {
    assert.match(compose, new RegExp(`^  ${service}:`, 'm'))
  }

  assert.match(compose, /queue:work/)
  assert.match(compose, /schedule:work/)
  assert.match(compose, /reverb:start/)
  assert.match(compose, /condition: service_completed_successfully/)
})

test('browser-facing services use an explicit configurable bind address', () => {
  assert.match(compose, /\$\{APP_BIND_ADDRESS:-127\.0\.0\.1\}:\$\{FRONTEND_FORWARD_PORT:-5173\}:80/)
  assert.match(compose, /\$\{APP_BIND_ADDRESS:-127\.0\.0\.1\}:\$\{BACKEND_FORWARD_PORT:-8000\}:80/)
  assert.match(compose, /\$\{APP_BIND_ADDRESS:-127\.0\.0\.1\}:\$\{REVERB_FORWARD_PORT:-8080\}:8080/)
  assert.match(example, /^APP_BIND_ADDRESS=127\.0\.0\.1$/m)
})

test('frontend and backend receive the same configured Reverb application key', () => {
  assert.match(compose, /REVERB_APP_KEY: \$\{REVERB_APP_KEY:\?[^}]+\}/)
  assert.match(compose, /VITE_REVERB_APP_KEY: \$\{REVERB_APP_KEY:\?[^}]+\}/)
  assert.match(example, /^REVERB_APP_SECRET=\s*$/m)
  assert.doesNotMatch(compose, /REVERB_APP_SECRET:\s*local-secret/)
})

test('simulators are optional profiles and use internal service URLs', () => {
  assert.match(compose, /payment-simulator:\n\s+profiles: \[simulators\]/)
  assert.match(compose, /ocpp-gateway:\n\s+profiles: \[simulators\]/)
  assert.match(compose, /PAYMENT_SIMULATOR_BASE_URL: http:\/\/payment-simulator:8080/)
  assert.match(compose, /OCPP_LARAVEL_BASE_URL: http:\/\/backend\/api\/internal\/ocpp/)
})

test('application images do not bake environment secrets', () => {
  assert.match(backendDockerignore, /^\.env$/m)
  assert.match(backendDockerignore, /^\.env\.\*$/m)
  assert.doesNotMatch(backendDockerfile, /COPY\s+\.env/)
  assert.doesNotMatch(frontendDockerfile, /VITE_API_URL=.*(?:secret|token|password)/i)
})

test('application images provide production-style web servers and healthchecks', () => {
  assert.match(backendDockerfile, /FROM php:8\.5-fpm-bookworm@sha256:[a-f0-9]{64} AS runtime/)
  assert.match(backendDockerfile, /FROM nginx:1\.28-alpine@sha256:[a-f0-9]{64} AS web/)
  assert.match(frontendDockerfile, /pnpm --dir frontend build/)
  assert.match(frontendDockerfile, /FROM nginx:1\.28-alpine@sha256:[a-f0-9]{64} AS runtime/)
  assert.match(compose, /^\s+healthcheck:/m)
})

test('PDF previews use a bundled worker served with a JavaScript MIME type', () => {
  assert.match(pdfPreview, /pdf\.worker\.min\.mjs\?worker/)
  assert.match(pdfPreview, /GlobalWorkerOptions\.workerPort/)
  assert.doesNotMatch(pdfPreview, /pdf\.worker\.min\.mjs\?url/)
  assert.match(frontendNginx, /location ~\* \\.mjs\$/)
  assert.match(frontendNginx, /application\/javascript mjs/)
})

test('Laravel receives the original HTTPS scheme through the edge proxy', () => {
  assert.match(backendNginx, /map \$http_x_forwarded_proto \$original_scheme/)
  assert.match(backendNginx, /fastcgi_param HTTP_X_FORWARDED_PROTO \$original_scheme;/)
  assert.doesNotMatch(backendNginx, /fastcgi_param HTTP_X_FORWARDED_PROTO \$scheme;/)
})
