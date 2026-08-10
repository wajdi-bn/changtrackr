import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'

const workflow = await readFile(new URL('../.github/workflows/ci.yml', import.meta.url), 'utf8')

test('CI uses read-only repository permissions and avoids privileged pull request triggers', () => {
  assert.match(workflow, /permissions:\s*\r?\n\s+contents: read/)
  assert.doesNotMatch(workflow, /pull_request_target:/)
})

test('all CI actions are pinned to immutable commit hashes', () => {
  const actions = [...workflow.matchAll(/uses:\s+([^\s#]+)/g)].map((match) => match[1])

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
  assert.match(workflow, /composer audit --locked/)
  assert.match(workflow, /pnpm audit --audit-level high/)
  assert.match(workflow, /docker compose[\s\S]+config --quiet/)
  assert.match(workflow, /docker\/build-push-action@[a-f0-9]{40}/)
})
