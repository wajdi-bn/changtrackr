import fs from 'node:fs'

const passwordPlaceholder = '__OCPP_SIMULATOR_UI_PASSWORD__'

export function buildCliConfig(template, password) {
  if (!password) {
    throw new Error('OCPP_SIMULATOR_UI_PASSWORD is required.')
  }

  if (template?.uiServer?.authentication?.password !== passwordPlaceholder) {
    throw new Error('The OCPP CLI configuration template has an unexpected password field.')
  }

  const config = structuredClone(template)
  config.uiServer.authentication.password = password

  return config
}

export function writeCliConfig(templatePath, outputPath, password) {
  const template = JSON.parse(fs.readFileSync(templatePath, 'utf8'))
  const config = buildCliConfig(template, password)

  fs.writeFileSync(outputPath, `${JSON.stringify(config, null, 2)}\n`, { mode: 0o600 })
  fs.chmodSync(outputPath, 0o600)
}

const [templatePath, outputPath] = process.argv.slice(2)

if (!templatePath || !outputPath) {
  throw new Error('Usage: write-cli-config <template> <output>')
}

writeCliConfig(templatePath, outputPath, process.env.OCPP_SIMULATOR_UI_PASSWORD)
