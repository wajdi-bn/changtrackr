import { spawn } from 'node:child_process'
import { createHash, timingSafeEqual } from 'node:crypto'
import fs from 'node:fs'
import http from 'node:http'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const host = process.env.OCPP_SIMULATOR_CONTROL_HOST ?? '0.0.0.0'
const port = Number(process.env.OCPP_SIMULATOR_CONTROL_PORT ?? 8081)
const token = process.env.OCPP_SIMULATOR_CONTROL_TOKEN ?? ''
const cliConfig = process.env.OCPP_SIMULATOR_CLI_CONFIG ?? '/tmp/evse-cli-config.json'
const cliPath = process.env.OCPP_SIMULATOR_CLI_PATH ?? '/usr/app/cli/cli.js'
const commandTimeoutMs = Number(process.env.OCPP_SIMULATOR_CONTROL_TIMEOUT_MS ?? 15000)
const stationSecret = process.env.OCPP_SIMULATOR_STATION_SECRET ?? ''
const supervisionUrl = process.env.OCPP_SIMULATOR_SUPERVISION_URL ?? 'ws://ocpp-gateway:9000/ocpp'
const profilesPath = process.env.OCPP_SIMULATOR_PROFILES_FILE ?? fileURLToPath(new URL('./profiles.json', import.meta.url))
const stationsPath = process.env.OCPP_SIMULATOR_STATIONS_FILE ?? fileURLToPath(new URL('./stations.json', import.meta.url))

const connectorActions = new Set(['plug', 'unplug', 'inject_fault', 'recover'])
const stationActions = new Set(['connect', 'disconnect', 'heartbeat'])
const scenarios = new Set(['normal_cycle', 'fault_recovery'])

function readJsonFile(file) {
  return JSON.parse(fs.readFileSync(file, 'utf8'))
}

export function loadProfiles(file = profilesPath) {
  const profiles = readJsonFile(file)
  if (!Array.isArray(profiles) || profiles.length === 0) {
    throw new Error('The simulator profile catalog is invalid.')
  }
  return profiles
}

export function publicProfiles(profiles) {
  return profiles.map(({ key, label, description, manufacturer, model, max_power_kw, model_image, connectors }) => ({
    key,
    label,
    description,
    manufacturer,
    model,
    max_power_kw,
    model_image,
    connectors,
  }))
}

export function validateProvisionPayload(payload, profiles) {
  const identity = String(payload?.identity ?? '')
  const profileKey = String(payload?.profile ?? '')
  if (!/^[A-Za-z0-9._:-]{1,80}$/.test(identity)) {
    throw new Error('A valid simulator station identity is required.')
  }
  const profile = profiles.find((candidate) => candidate.key === profileKey)
  if (!profile) {
    throw new Error('The selected simulator profile is not supported.')
  }
  return { identity, profile }
}

export function findStation(payload, identity) {
  const stations = Array.isArray(payload?.chargingStations) ? payload.chargingStations : []
  return stations.find((station) => station?.stationInfo?.chargingStationId === identity) ?? null
}

export function summarizeStation(station) {
  if (!station) return null

  return {
    identity: station.stationInfo?.chargingStationId ?? null,
    started: station.started === true,
    connected: station.wsState === 1,
    ws_state: station.wsState ?? null,
    supervision_url: station.supervisionUrl ?? null,
    connectors: (station.connectors ?? [])
      .filter((connector) => Number(connector.connectorId) > 0)
      .map((connector) => ({
        connector_id: Number(connector.connectorId),
        status: connector.connectorStatus?.status ?? connector.connectorStatus?.bootStatus ?? 'Unknown',
        error_code: connector.connectorStatus?.errorCode ?? 'NoError',
        availability: connector.connectorStatus?.availability ?? 'Unknown',
        transaction_started: connector.connectorStatus?.transactionStarted === true,
      })),
  }
}

export function validateAction(action, connectorId) {
  if (!stationActions.has(action) && !connectorActions.has(action) && !scenarios.has(action)) {
    throw new Error('Unsupported simulator action.')
  }
  if ((connectorActions.has(action) || scenarios.has(action))
    && (!Number.isInteger(connectorId) || connectorId < 1 || connectorId > 65535)) {
    throw new Error('A valid connector_id is required for this action.')
  }
}

function secureTokenMatches(candidate) {
  if (!token || !candidate) return false
  const expected = createHash('sha256').update(token).digest()
  const actual = createHash('sha256').update(candidate).digest()
  return timingSafeEqual(expected, actual)
}

function runCli(args) {
  return new Promise((resolve, reject) => {
    const child = spawn(process.execPath, [cliPath, '--config', cliConfig, '--json', ...args], {
      env: process.env,
      stdio: ['ignore', 'pipe', 'pipe'],
    })
    let stdout = ''
    let stderr = ''
    const timer = setTimeout(() => {
      child.kill('SIGKILL')
      reject(new Error('The simulator command timed out.'))
    }, commandTimeoutMs)

    child.stdout.on('data', (chunk) => { stdout += String(chunk) })
    child.stderr.on('data', (chunk) => { stderr += String(chunk) })
    child.once('error', (error) => {
      clearTimeout(timer)
      reject(error)
    })
    child.once('close', (code) => {
      clearTimeout(timer)
      if (code !== 0) {
        reject(new Error(stderr.trim() || 'The simulator command failed.'))
        return
      }
      try {
        resolve(stdout.trim() ? JSON.parse(stdout) : { status: 'success' })
      } catch {
        resolve({ status: 'success' })
      }
    })
  })
}

async function waitForSimulator() {
  let lastError
  for (let attempt = 1; attempt <= 30; attempt += 1) {
    try {
      await runCli(['simulator', 'state'])
      return
    } catch (error) {
      lastError = error
      await new Promise((resolve) => setTimeout(resolve, 2000))
    }
  }
  throw lastError ?? new Error('The SAP simulator UI did not become ready.')
}

async function stationState(identity) {
  const payload = await runCli(['station', 'list'])
  return summarizeStation(findStation(payload, identity))
}

export function upsertStationManifest(identity, profile, manifestPath = stationsPath) {
  fs.mkdirSync(path.dirname(manifestPath), { recursive: true })
  const stations = fs.existsSync(manifestPath) ? readJsonFile(manifestPath) : []
  if (!Array.isArray(stations)) throw new Error('The simulator station manifest is invalid.')

  const entry = {
    identity,
    profile: profile.key,
    manufacturer: profile.manufacturer,
    model: profile.model,
    maxPowerKw: profile.max_power_kw,
    connectorPowersKw: profile.connectors.map((connector) => connector.max_power_kw),
  }
  const index = stations.findIndex((station) => station?.identity === identity)
  if (index >= 0) stations[index] = entry
  else stations.push(entry)

  const temporaryPath = `${manifestPath}.${process.pid}.tmp`
  fs.writeFileSync(temporaryPath, `${JSON.stringify(stations, null, 2)}\n`)
  fs.renameSync(temporaryPath, manifestPath)
}

async function waitForStation(identity) {
  for (let attempt = 1; attempt <= 20; attempt += 1) {
    const station = await stationState(identity)
    if (station) return station
    await new Promise((resolve) => setTimeout(resolve, 250))
  }
  throw new Error('The simulator did not expose the provisioned station in time.')
}

async function provisionStation(payload) {
  const profiles = loadProfiles()
  const { identity, profile } = validateProvisionPayload(payload, profiles)
  const existing = await stationState(identity)
  if (existing) {
    upsertStationManifest(identity, profile)
    return existing
  }
  if (stationSecret.length < 32) {
    throw new Error('The simulator station secret is not configured.')
  }

  const result = await runCli([
    'station', 'add', '--template', profile.template, '--count', '1', '--auto-start',
    '--base-name', identity, '--fixed-name', '--ocpp-strict', '--persistent-config',
    '--supervision-url', supervisionUrl, '--supervision-user', identity,
    '--supervision-password', stationSecret,
  ])
  if (result?.status !== 'success') {
    throw new Error(result?.errorMessage ?? 'The simulator rejected station provisioning.')
  }

  const station = await waitForStation(identity)
  upsertStationManifest(identity, profile)
  return station
}

async function stationHash(identity) {
  const payload = await runCli(['station', 'list'])
  const station = findStation(payload, identity)
  const hashId = station?.stationInfo?.hashId
  if (!hashId) throw new Error('The station is not registered in the simulator fleet.')
  return hashId
}

async function connectorStatus(hashId, connectorId, status, errorCode = 'NoError') {
  await runCli([
    'ocpp', 'status-notification', '--connector-id', String(connectorId),
    '--error-code', errorCode, '--status', status, hashId,
  ])
}

async function executeAction(identity, action, connectorId) {
  validateAction(action, connectorId)
  const hashId = await stationHash(identity)

  if (action === 'connect') await runCli(['station', 'start', hashId])
  if (action === 'disconnect') await runCli(['station', 'stop', hashId])
  if (action === 'heartbeat') await runCli(['ocpp', 'heartbeat', hashId])
  if (action === 'plug') await connectorStatus(hashId, connectorId, 'Preparing')
  if (action === 'unplug' || action === 'recover') await connectorStatus(hashId, connectorId, 'Available')
  if (action === 'inject_fault') await connectorStatus(hashId, connectorId, 'Faulted', 'ConnectorLockFailure')
  if (action === 'normal_cycle') {
    await connectorStatus(hashId, connectorId, 'Preparing')
    await new Promise((resolve) => setTimeout(resolve, 1200))
    await connectorStatus(hashId, connectorId, 'Available')
  }
  if (action === 'fault_recovery') {
    await connectorStatus(hashId, connectorId, 'Faulted', 'ConnectorLockFailure')
    await new Promise((resolve) => setTimeout(resolve, 1800))
    await connectorStatus(hashId, connectorId, 'Available')
  }

  await new Promise((resolve) => setTimeout(resolve, 350))
  return stationState(identity)
}

function sendJson(response, status, payload) {
  response.writeHead(status, { 'content-type': 'application/json; charset=utf-8' })
  response.end(JSON.stringify(payload))
}

function readJson(request) {
  return new Promise((resolve, reject) => {
    let body = ''
    request.on('data', (chunk) => {
      body += String(chunk)
      if (body.length > 16_384) request.destroy(new Error('Request body is too large.'))
    })
    request.on('end', () => {
      try { resolve(body ? JSON.parse(body) : {}) } catch { reject(new Error('Invalid JSON body.')) }
    })
    request.on('error', reject)
  })
}

let commandChain = Promise.resolve()
function serialized(task) {
  const result = commandChain.then(task, task)
  commandChain = result.catch(() => undefined)
  return result
}

export const server = http.createServer(async (request, response) => {
  if (request.method === 'GET' && request.url === '/health') {
    sendJson(response, 200, { status: 'ok' })
    return
  }

  const authToken = String(request.headers.authorization ?? '').replace(/^Bearer\s+/i, '')
  if (!secureTokenMatches(authToken)) {
    sendJson(response, 401, { message: 'Unauthorized.' })
    return
  }

  if (request.method === 'GET' && request.url === '/profiles') {
    try {
      sendJson(response, 200, { data: publicProfiles(loadProfiles()) })
    } catch (error) {
      sendJson(response, 500, { message: error instanceof Error ? error.message : 'Profile catalog unavailable.' })
    }
    return
  }

  if (request.method === 'POST' && request.url === '/stations') {
    try {
      const payload = await readJson(request)
      const station = await serialized(() => provisionStation(payload))
      sendJson(response, 201, { data: station })
    } catch (error) {
      process.stderr.write(`[simulator-control] ${error instanceof Error ? error.message : 'Unknown error'}\n`)
      sendJson(response, 422, { message: error instanceof Error ? error.message : 'Simulator provisioning failed.' })
    }
    return
  }

  const match = request.url?.match(/^\/stations\/([A-Za-z0-9._:-]{1,100})(?:\/actions)?$/)
  if (!match) {
    sendJson(response, 404, { message: 'Not found.' })
    return
  }

  try {
    const identity = decodeURIComponent(match[1])
    if (request.method === 'GET' && !request.url.endsWith('/actions')) {
      const station = await serialized(() => stationState(identity))
      if (!station) {
        sendJson(response, 404, { message: 'The station is not registered in the simulator fleet.' })
        return
      }
      sendJson(response, 200, { data: station })
      return
    }
    if (request.method === 'POST' && request.url.endsWith('/actions')) {
      const payload = await readJson(request)
      const connectorId = payload.connector_id === undefined ? null : Number(payload.connector_id)
      const station = await serialized(() => executeAction(identity, String(payload.action ?? ''), connectorId))
      sendJson(response, 200, { data: station })
      return
    }
    sendJson(response, 405, { message: 'Method not allowed.' })
  } catch (error) {
    process.stderr.write(`[simulator-control] ${error instanceof Error ? error.message : 'Unknown error'}\n`)
    sendJson(response, 422, { message: error instanceof Error ? error.message : 'Simulator action failed.' })
  }
})

const isEntryPoint = process.argv[1]
  && new URL(import.meta.url).pathname.endsWith(process.argv[1].replaceAll('\\', '/'))
if (isEntryPoint) {
  if (!token) throw new Error('OCPP_SIMULATOR_CONTROL_TOKEN is required.')
  if (stationSecret.length < 32) throw new Error('OCPP_SIMULATOR_STATION_SECRET must contain at least 32 characters.')
  await waitForSimulator()
  server.listen(port, host, () => {
    process.stdout.write(`OCPP simulator control listening on ${host}:${port}\n`)
  })
}
