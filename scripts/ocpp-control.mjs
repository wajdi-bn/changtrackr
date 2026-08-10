import fs from 'node:fs'
import path from 'node:path'
import { spawnSync } from 'node:child_process'
import { fileURLToPath } from 'node:url'

const scriptDirectory = path.dirname(fileURLToPath(import.meta.url))
const repoRoot = path.dirname(scriptDirectory)
const manifestPath = path.join(repoRoot, 'infra', 'ocpp', 'simulator', 'stations.json')
const stations = JSON.parse(fs.readFileSync(manifestPath, 'utf8'))
const stationMap = new Map(stations.map((station) => [station.identity, station]))
const cliArguments = process.argv.slice(2).filter((argument) => argument !== '--')
const [action, requestedStation = 'CT-TUN-001', requestedConnector = '1'] = cliArguments

function run(command, args, cwd = repoRoot) {
  const result = spawnSync(command, args, { cwd, stdio: 'inherit', shell: false })
  if (result.error) throw result.error
  process.exitCode = result.status ?? 1
  return process.exitCode === 0
}

function requireStation(identity) {
  const station = stationMap.get(identity)
  if (!station) {
    throw new Error(`Unknown simulator station ${identity}. Valid identities: ${[...stationMap.keys()].join(', ')}`)
  }
  return station
}

function requireConnector(station, value) {
  const connectorId = Number.parseInt(value, 10)
  if (!Number.isInteger(connectorId) || connectorId < 1 || connectorId > station.connectorPowersKw.length) {
    throw new Error(`Station ${station.identity} has connectors 1-${station.connectorPowersKw.length}.`)
  }
  return connectorId
}

const legacyCompose = [
  'compose',
  '--env-file', 'infra/ocpp/.env',
  '-f', 'infra/ocpp/compose.yaml',
  '--profile', 'tools',
  'run', '--rm',
]

const stackCompose = [
  'compose',
  '--env-file', 'infra/.env',
  '-f', 'infra/docker-compose.yml',
  '--profile', 'simulators',
  '--profile', 'tools',
]

function isUnifiedStackRunning() {
  if (!fs.existsSync(path.join(repoRoot, 'infra', '.env'))) return false

  const result = spawnSync('docker', [...stackCompose, 'ps', '--status', 'running', '-q', 'ocpp-simulator'], {
    cwd: repoRoot,
    encoding: 'utf8',
    shell: false,
  })

  return result.status === 0 && result.stdout.trim().length > 0
}

const unifiedStack = isUnifiedStackRunning()
const compose = unifiedStack ? [...stackCompose, 'run', '--rm'] : legacyCompose

function runArtisan(args) {
  if (unifiedStack) {
    return run('docker', [...stackCompose, 'exec', '-T', 'backend-php', 'php', 'artisan', ...args])
  }

  return run('C:\\php\\php.exe', ['artisan', ...args], path.join(repoRoot, 'backend'))
}

function runSimulatorTool(entrypoint, environment) {
  return run('docker', [
    ...compose,
    '--entrypoint', entrypoint,
    ...environment.flatMap((value) => ['-e', value]),
    'ocpp-cli',
  ])
}

if (action === 'fleet-status') {
  for (const station of stations) {
    if (!runArtisan(['ocpp:status', station.identity])) break
  }
} else if (action === 'status') {
  requireStation(requestedStation)
  runArtisan(['ocpp:status', requestedStation])
} else if (action === 'plug' || action === 'unplug') {
  const station = requireStation(requestedStation)
  const connector = requireConnector(station, requestedConnector)
  const status = action === 'plug' ? 'Preparing' : 'Available'
  const environment = [
    `OCPP_SIMULATOR_STATION_IDENTITY=${station.identity}`,
    `OCPP_SIMULATOR_CONNECTOR_ID=${connector}`,
    `OCPP_SIMULATOR_CONNECTOR_STATUS=${status}`,
  ]
  if (unifiedStack) {
    runSimulatorTool('run-ocpp-connector-status', environment)
  } else {
    run('docker', [...compose, ...environment.flatMap((value) => ['-e', value]), 'ocpp-plug'])
  }
} else if (action === 'scenario' || action === 'transaction-scenario') {
  const station = requireStation(requestedStation)
  const service = action === 'scenario' ? 'ocpp-scenario' : 'ocpp-transaction-scenario'
  const environment = [`OCPP_SIMULATOR_STATION_IDENTITY=${station.identity}`]
  const entrypoint = action === 'scenario' ? 'run-ocpp-scenario' : 'run-ocpp-transaction-scenario'
  if (unifiedStack) {
    runSimulatorTool(entrypoint, environment)
  } else {
    run('docker', [...compose, ...environment.flatMap((value) => ['-e', value]), service])
  }
} else if (action === 'stop-transaction') {
  const station = requireStation(requestedStation)
  const environment = [`OCPP_SIMULATOR_STATION_IDENTITY=${station.identity}`]
  if (unifiedStack) {
    runSimulatorTool('run-ocpp-stop-transaction', environment)
  } else {
    run('docker', [...compose, ...environment.flatMap((value) => ['-e', value]), 'ocpp-stop-transaction'])
  }
} else if (action === 'connect' || action === 'disconnect') {
  const station = requireStation(requestedStation)
  const environment = [
    `OCPP_SIMULATOR_STATION_IDENTITY=${station.identity}`,
    `OCPP_SIMULATOR_CONNECTION_ACTION=${action === 'connect' ? 'open' : 'close'}`,
  ]
  if (unifiedStack) {
    runSimulatorTool('run-ocpp-connection-state', environment)
  } else {
    run('docker', [...compose, ...environment.flatMap((value) => ['-e', value]), 'ocpp-connection'])
  }
} else {
  throw new Error('Usage: ocpp-control <status|fleet-status|plug|unplug|scenario|transaction-scenario|stop-transaction|connect|disconnect> [station] [connector]')
}
