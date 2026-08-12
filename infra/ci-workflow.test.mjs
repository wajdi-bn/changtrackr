import assert from 'node:assert/strict'
import { spawnSync } from 'node:child_process'
import { readFile } from 'node:fs/promises'
import test from 'node:test'
import { fileURLToPath } from 'node:url'

const workflow = await readFile(new URL('../.github/workflows/ci.yml', import.meta.url), 'utf8')
const deploymentWorkflow = await readFile(new URL('../.github/workflows/deploy-production.yml', import.meta.url), 'utf8')
const repositoryRoot = fileURLToPath(new URL('..', import.meta.url))

test('CI uses read-only repository permissions and avoids privileged pull request triggers', () => {
  assert.match(workflow, /permissions:\s*\r?\n\s+contents: read/)
  assert.doesNotMatch(workflow, /pull_request_target:/)
})

test('all CI actions are pinned to immutable commit hashes', () => {
  const actions = [...`${workflow}\n${deploymentWorkflow}`.matchAll(/uses:\s+([^\s#]+)/g)].map((match) => match[1])

  assert.ok(actions.length >= 10)
  for (const action of actions) {
    assert.match(action, /^[\w.-]+\/[\w.-]+@[a-f0-9]{40}$/)
  }
})

test('CI covers application, infrastructure, security and Docker validation', () => {
  for (const job of ['backend:', 'frontend:', 'infrastructure:', 'ocpp-gateway:', 'docker-images:']) {
    assert.match(workflow, new RegExp(`^  ${job}`, 'm'))
  }

  assert.match(workflow, /Run complete backend test suite/)
  assert.match(workflow, /Run critical compatibility tests on PostgreSQL/)
  assert.match(workflow, /ForeignKeyIndexCoverageTest\.php/)
  assert.match(workflow, /ChargingSessionPaymentApiTest\.php/)
  assert.match(workflow, /pnpm test:frontend/)
  assert.match(workflow, /npm run test:infra-config/)
  assert.match(workflow, /OCPP_SIMULATOR_CONTROL_TOKEN: ci-only-simulator-control-token/)
  assert.match(workflow, /composer audit --locked/)
  assert.match(workflow, /pnpm audit --audit-level high/)
  assert.match(workflow, /docker compose[\s\S]+config --quiet/)
  assert.match(workflow, /docker\/build-push-action@[a-f0-9]{40}/)
})

test('tracked secret policy does not flag its own scanner', () => {
  const secretPattern = [
    ['GOCSP', 'X-'].join(''),
    ['sk_', '(live|test)_[A-Za-z0-9]+'].join(''),
    ['re_', '[A-Za-z0-9]{20,}'].join(''),
    ['BEGIN ', '[A-Z ]*PRIVATE KEY'].join(''),
  ].join('|')
  const result = spawnSync(
    'git',
    ['grep', '-nE', secretPattern, '--', ':!pnpm-lock.yaml'],
    { cwd: repositoryRoot, encoding: 'utf8' },
  )

  assert.equal(result.status, 1, result.stdout || result.stderr)
})
