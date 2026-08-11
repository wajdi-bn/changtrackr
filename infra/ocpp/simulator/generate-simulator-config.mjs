import fs from 'node:fs'
import path from 'node:path'

const [configPath, templatePath, stationsPath, profilesPath] = process.argv.slice(2)

if (!configPath || !templatePath || !stationsPath || !profilesPath) {
  throw new Error('Usage: generate-simulator-config <config> <template> <stations> <profiles>')
}

const stationSecret = process.env.OCPP_SIMULATOR_STATION_SECRET
const uiPassword = process.env.OCPP_SIMULATOR_UI_PASSWORD

if (!stationSecret || stationSecret.length < 32) {
  throw new Error('OCPP_SIMULATOR_STATION_SECRET must contain at least 32 characters.')
}

if (!uiPassword) {
  throw new Error('OCPP_SIMULATOR_UI_PASSWORD is required.')
}

const readJson = (file) => JSON.parse(fs.readFileSync(file, 'utf8'))
const config = readJson(configPath)
const template = readJson(templatePath)
const stations = readJson(stationsPath)
const profiles = readJson(profilesPath)

if (!Array.isArray(stations)) {
  throw new Error('The simulator station manifest must be an array.')
}

if (!Array.isArray(profiles) || profiles.length === 0) {
  throw new Error('At least one simulator hardware profile must be configured.')
}

const identities = new Set()
const profileKeys = new Set()
const templatesDirectory = path.dirname(templatePath)

const writeTemplate = ({ fileName, baseName, manufacturer, model, maxPowerKw, connectorPowersKw }) => {
  const stationTemplate = structuredClone(template)
  stationTemplate.baseName = baseName
  stationTemplate.fixedName = true
  stationTemplate.chargePointModel = model
  stationTemplate.chargePointVendor = manufacturer
  stationTemplate.supervisionUser = baseName
  stationTemplate.supervisionPassword = stationSecret
  stationTemplate.power = Math.round(maxPowerKw * 1000)
  stationTemplate.numberOfConnectors = connectorPowersKw.length
  stationTemplate.Connectors = { 0: {} }

  connectorPowersKw.forEach((powerKw, index) => {
    stationTemplate.Connectors[index + 1] = {
      bootStatus: 'Available',
      maximumPower: Math.round(powerKw * 1000),
      MeterValues: [{ unit: 'Wh', context: 'Sample.Periodic' }],
    }
  })

  fs.writeFileSync(path.join(templatesDirectory, fileName), `${JSON.stringify(stationTemplate, null, 2)}\n`)
}

const configuredStations = stations.map((station) => {
  const { identity, manufacturer, model, maxPowerKw, connectorPowersKw } = station

  if (!identity || identities.has(identity)) {
    throw new Error(`Simulator station identity is missing or duplicated: ${identity ?? '(empty)'}`)
  }

  if (!Array.isArray(connectorPowersKw) || connectorPowersKw.length === 0) {
    throw new Error(`Station ${identity} must define at least one connector.`)
  }

  identities.add(identity)
  const fileName = `chargetrackr.${identity.toLowerCase()}.json`
  writeTemplate({ fileName, baseName: identity, manufacturer, model, maxPowerKw, connectorPowersKw })

  return { file: fileName, numberOfStations: 1 }
})

const availableProfiles = profiles.map((profile) => {
  if (!profile.key || !profile.template || profileKeys.has(profile.key)) {
    throw new Error(`Simulator profile key is missing or duplicated: ${profile.key ?? '(empty)'}`)
  }
  if (!Array.isArray(profile.connectors) || profile.connectors.length === 0) {
    throw new Error(`Simulator profile ${profile.key} must define at least one connector.`)
  }

  profileKeys.add(profile.key)
  const fileName = `${profile.template}.json`
  writeTemplate({
    fileName,
    baseName: profile.template,
    manufacturer: profile.manufacturer,
    model: profile.model,
    maxPowerKw: profile.max_power_kw,
    connectorPowersKw: profile.connectors.map((connector) => connector.max_power_kw),
  })

  return { file: fileName, numberOfStations: 0 }
})

config.stationTemplateUrls = [...configuredStations, ...availableProfiles]

config.uiServer.authentication.password = uiPassword
fs.writeFileSync(configPath, `${JSON.stringify(config, null, 2)}\n`)

process.stdout.write(`Configured ${stations.length} OCPP simulator stations and ${profiles.length} dynamic profiles.\n`)
