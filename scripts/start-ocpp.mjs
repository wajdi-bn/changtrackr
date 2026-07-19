import { spawnSync } from 'node:child_process'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const repoRoot = path.dirname(path.dirname(fileURLToPath(import.meta.url)))
const compose = [
  'compose',
  '--env-file', 'infra/ocpp/.env',
  '-f', 'infra/ocpp/compose.yaml',
  '--profile', 'tools',
]

function run(args) {
  const result = spawnSync('docker', [...compose, ...args], {
    cwd: repoRoot,
    stdio: 'inherit',
    shell: false,
  })

  if (result.error) throw result.error
  if (result.status !== 0) process.exit(result.status ?? 1)
}

run(['build', 'ocpp-gateway', 'ocpp-simulator', 'ocpp-plug'])
run(['up', '-d', 'ocpp-gateway', 'ocpp-simulator'])
