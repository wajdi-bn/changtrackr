import fs from 'node:fs'
import path from 'node:path'
import { spawnSync } from 'node:child_process'
import { fileURLToPath } from 'node:url'

const scriptDirectory = path.dirname(fileURLToPath(import.meta.url))
const repoRoot = path.dirname(scriptDirectory)
const manifestPath = path.join(repoRoot, 'infra', 'ocpp', 'simulator', 'stations.json')
const stations = JSON.parse(fs.readFileSync(manifestPath, 'utf8'))
const stationMap = new Map(stations.map((station) => [station.identity, station]))
const [action, requestedStation = 'CT-TUN-001', requestedConnector = '1'] = process.argv.slice(2)

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

const compose = [
  'compose',
  '--env-file', 'infra/ocpp/.env',
  '-f', 'infra/ocpp/compose.yaml',
  '--profile', 'tools',
  'run', '--rm',
]

if (action === 'fleet-status') {
  for (const station of stations) {
    if (!run('C:\\php\\php.exe', ['artisan', 'ocpp:status', station.identity], path.join(repoRoot, 'backend'))) break
  }
} else if (action === 'status') {
  requireStation(requestedStation)
  run('C:\\php\\php.exe', ['artisan', 'ocpp:status', requestedStation], path.join(repoRoot, 'backend'))
} else if (action === 'plug' || action === 'unplug') {
  const station = requireStation(requestedStation)
  const connector = requireConnector(station, requestedConnector)
  const status = action === 'plug' ? 'Preparing' : 'Available'
  run('docker', [
    ...compose,
    '-e', `OCPP_SIMULATOR_STATION_IDENTITY=${station.identity}`,
    '-e', `OCPP_SIMULATOR_CONNECTOR_ID=${connector}`,
    '-e', `OCPP_SIMULATOR_CONNECTOR_STATUS=${status}`,
    'ocpp-plug',
  ])
} else if (action === 'scenario' || action === 'transaction-scenario') {
  const station = requireStation(requestedStation)
  const service = action === 'scenario' ? 'ocpp-scenario' : 'ocpp-transaction-scenario'
  run('docker', [
    ...compose,
    '-e', `OCPP_SIMULATOR_STATION_IDENTITY=${station.identity}`,
    service,
  ])
} else if (action === 'stop-transaction') {
  const station = requireStation(requestedStation)
  run('docker', [
    ...compose,
    '-e', `OCPP_SIMULATOR_STATION_IDENTITY=${station.identity}`,
    'ocpp-stop-transaction',
  ])
} else if (action === 'connect' || action === 'disconnect') {
  const station = requireStation(requestedStation)
  run('docker', [
    ...compose,
    '-e', `OCPP_SIMULATOR_STATION_IDENTITY=${station.identity}`,
    '-e', `OCPP_SIMULATOR_CONNECTION_ACTION=${action === 'connect' ? 'open' : 'close'}`,
    'ocpp-connection',
  ])
} else {
  throw new Error('Usage: ocpp-control <status|fleet-status|plug|unplug|scenario|transaction-scenario|stop-transaction|connect|disconnect> [station] [connector]')
}
