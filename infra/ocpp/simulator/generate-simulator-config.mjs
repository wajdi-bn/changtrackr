import fs from 'node:fs'
import path from 'node:path'

const [configPath, templatePath, stationsPath] = process.argv.slice(2)

if (!configPath || !templatePath || !stationsPath) {
  throw new Error('Usage: generate-simulator-config <config> <template> <stations>')
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

if (!Array.isArray(stations) || stations.length === 0) {
  throw new Error('At least one simulator station must be configured.')
}

const identities = new Set()
const templatesDirectory = path.dirname(templatePath)

config.stationTemplateUrls = stations.map((station) => {
  const { identity, manufacturer, model, maxPowerKw, connectorPowersKw } = station

  if (!identity || identities.has(identity)) {
    throw new Error(`Simulator station identity is missing or duplicated: ${identity ?? '(empty)'}`)
  }

  if (!Array.isArray(connectorPowersKw) || connectorPowersKw.length === 0) {
    throw new Error(`Station ${identity} must define at least one connector.`)
  }

  identities.add(identity)

  const stationTemplate = structuredClone(template)
  stationTemplate.baseName = identity
  stationTemplate.fixedName = true
  stationTemplate.chargePointModel = model
  stationTemplate.chargePointVendor = manufacturer
  stationTemplate.supervisionUser = identity
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

  const fileName = `chargetrackr.${identity.toLowerCase()}.json`
  fs.writeFileSync(path.join(templatesDirectory, fileName), `${JSON.stringify(stationTemplate, null, 2)}\n`)

  return { file: fileName, numberOfStations: 1 }
})

config.uiServer.authentication.password = uiPassword
fs.writeFileSync(configPath, `${JSON.stringify(config, null, 2)}\n`)

process.stdout.write(`Configured ${stations.length} OCPP simulator stations.\n`)
