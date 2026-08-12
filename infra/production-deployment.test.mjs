import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'

const compose = await readFile(new URL('../deployment/production/compose.yml', import.meta.url), 'utf8')
const example = await readFile(new URL('../deployment/production/.env.example', import.meta.url), 'utf8')
const caddy = await readFile(new URL('../deployment/production/Caddyfile', import.meta.url), 'utf8')
const workflow = await readFile(new URL('../.github/workflows/deploy-production.yml', import.meta.url), 'utf8')
const provision = await readFile(new URL('../deployment/azure/provision.ps1', import.meta.url), 'utf8')
const init = await readFile(new URL('../deployment/azure/initialize-server.ps1', import.meta.url), 'utf8')
const cloudInit = await readFile(new URL('../deployment/azure/cloud-init.yml', import.meta.url), 'utf8')

test('production publishes only the TLS edge and keeps stateful services private', () => {
  assert.match(compose, /- "80:80"/)
  assert.match(compose, /- "443:443"/)
  assert.doesNotMatch(compose, /5432:5432|6379:6379|8081:8081|9000:9000|9090:8080/)
  assert.match(compose, /^  postgres:/m)
  assert.match(compose, /^  redis:/m)
  assert.doesNotMatch(compose, /^  mailpit:/m)
})

test('production routes every public endpoint through Caddy-managed TLS', () => {
  for (const domain of ['APP_DOMAIN', 'API_DOMAIN', 'REALTIME_DOMAIN', 'OCPP_DOMAIN']) {
    assert.match(caddy, new RegExp(`\\{\\$${domain}\\}`))
  }
  assert.match(caddy, /reverse_proxy frontend:80/)
  assert.match(caddy, /reverse_proxy backend:80/)
  assert.match(caddy, /reverse_proxy reverb:8080/)
  assert.match(caddy, /reverse_proxy ocpp-gateway:9000/)
  assert.match(caddy, /Strict-Transport-Security/)
})

test('production environment contains no committed secrets and requires secure transports', () => {
  for (const key of [
    'APP_KEY',
    'DB_PASSWORD',
    'REDIS_PASSWORD',
    'RESEND_API_KEY',
    'GOOGLE_CLIENT_SECRET',
    'REVERB_APP_SECRET',
    'PAYMENT_SIMULATOR_API_KEY',
    'OCPP_GATEWAY_SHARED_SECRET',
    'OCPP_SIMULATOR_CONTROL_TOKEN',
  ]) {
    assert.match(example, new RegExp(`^${key}=\\s*$`, 'm'))
  }
  assert.match(example, /^APP_ENV=production$/m)
  assert.match(example, /^APP_DEBUG=false$/m)
  assert.match(example, /^SESSION_SECURE_COOKIE=true$/m)
  assert.match(example, /^OCPP_GATEWAY_TLS_MODE=proxy$/m)
  assert.match(example, /^OCPP_GATEWAY_PUBLIC_URL=wss:\/\//m)
})

test('production compose uses GHCR release tags and immutable third-party images', () => {
  for (const service of [
    'backend',
    'backend-web',
    'frontend',
    'payment-simulator',
    'ocpp-gateway',
    'ocpp-simulator',
    'ocpp-simulator-control',
  ]) {
    assert.match(compose, new RegExp(`ghcr\\.io/\\$\\{GHCR_OWNER\\}/chargetrackr-${service}:\\$\\{IMAGE_TAG\\}`))
  }
  for (const image of ['caddy:2.10.2-alpine', 'postgres:18', 'redis:7-alpine']) {
    assert.match(compose, new RegExp(`${image.replace(/[.*+?^${}()|[\\]\\\\]/g, '\\$&')}@sha256:[a-f0-9]{64}`))
  }
})

test('deployment workflow publishes images and authenticates to Azure with OIDC', () => {
  assert.match(workflow, /permissions:[\s\S]*packages: write[\s\S]*id-token: write/)
  assert.match(workflow, /azure\/login@[a-f0-9]{40}/)
  assert.match(workflow, /docker\/login-action@[a-f0-9]{40}/)
  assert.match(workflow, /az vm run-command invoke/)
  assert.match(workflow, /publish_only:/)
  assert.match(workflow, /inputs\.publish_only != true/)
  assert.doesNotMatch(workflow, /GOOGLE_CLIENT_SECRET|RESEND_API_KEY|OCPP_GATEWAY_SHARED_SECRET/)
  for (const action of [...workflow.matchAll(/uses:\s+([^\s#]+)/g)].map((match) => match[1])) {
    assert.match(action, /^[\w.-]+\/[\w.-]+@[a-f0-9]{40}$/)
  }
})

test('Azure provisioning limits ingress and creates private backup storage', () => {
  assert.match(provision, /AllowSshFromMaintainer/)
  assert.match(provision, /AllowedSshCidr/)
  assert.match(provision, /--assign-identity/)
  assert.match(provision, /--allow-blob-public-access','false'/)
  assert.match(provision, /--allow-shared-key-access','false'/)
  assert.match(provision, /storage','container-rm','create'/)
  assert.doesNotMatch(provision, /storage account keys list|--account-key/)
  assert.match(provision, /Storage Blob Data Contributor/)
  assert.match(provision, /Canonical:ubuntu-24_04-lts:server:latest/)
  assert.match(provision, /Invoke-Az -Arguments @\('group','create'/)
  assert.doesNotMatch(provision, /Invoke-Az @\(/)
  assert.doesNotMatch(provision, /--admin-password/)
  assert.doesNotMatch(cloudInit, /curl[^\n]+\|\s*(?:sudo\s+)?bash/)
  assert.match(init, /Read-Host 'GitHub classic PAT with read:packages only' -AsSecureString/)
  assert.doesNotMatch(init, /github_pat_|ghp_/)
})
