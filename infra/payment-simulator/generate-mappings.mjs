import crypto from 'node:crypto'
import {
  mkdir,
  readFile,
  readdir,
  rename,
  rm,
  writeFile,
} from 'node:fs/promises'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const apiKeyPlaceholder = '__PAYMENT_SIMULATOR_API_KEY__'

export function readEnvValue(content, key) {
  const match = content.match(new RegExp(`^${key}=(.*)$`, 'm'))
  if (!match) {
    return null
  }

  const value = match[1].trim()
  if (
    value.length >= 2
    && ((value.startsWith('"') && value.endsWith('"')) || (value.startsWith("'") && value.endsWith("'")))
  ) {
    return value.slice(1, -1)
  }

  return value
}

export function upsertEnvValue(content, key, value) {
  const endOfLine = content.includes('\r\n') ? '\r\n' : '\n'
  const line = `${key}=${value}`
  const pattern = new RegExp(`^${key}=.*$`, 'm')

  if (pattern.test(content)) {
    return content.replace(pattern, line)
  }

  const separator = content.length === 0 || content.endsWith('\n') ? '' : endOfLine
  return `${content}${separator}${line}${endOfLine}`
}

function replacePlaceholder(value, apiKey, state) {
  if (value === apiKeyPlaceholder) {
    state.replacements += 1
    return apiKey
  }

  if (Array.isArray(value)) {
    return value.map((item) => replacePlaceholder(item, apiKey, state))
  }

  if (value && typeof value === 'object') {
    return Object.fromEntries(
      Object.entries(value).map(([key, item]) => [key, replacePlaceholder(item, apiKey, state)]),
    )
  }

  return value
}

function assertStrongApiKey(apiKey) {
  if (!apiKey || apiKey === apiKeyPlaceholder || apiKey.length < 32) {
    throw new Error('PAYMENT_SIMULATOR_API_KEY must contain at least 32 characters.')
  }
}

export async function generateMappings(sourceDirectory, targetDirectory, apiKey) {
  assertStrongApiKey(apiKey)

  const sourceFiles = (await readdir(sourceDirectory))
    .filter((file) => file.endsWith('.json'))
    .sort()

  if (sourceFiles.length === 0) {
    throw new Error('No WireMock mapping templates were found.')
  }

  const stagingDirectory = `${targetDirectory}.tmp-${process.pid}-${Date.now()}`
  await mkdir(stagingDirectory, { recursive: true })

  try {
    for (const file of sourceFiles) {
      const template = JSON.parse(await readFile(path.join(sourceDirectory, file), 'utf8'))
      const state = { replacements: 0 }
      const mapping = replacePlaceholder(template, apiKey, state)

      if (state.replacements !== 1) {
        throw new Error(`${file} must contain exactly one payment simulator API key placeholder.`)
      }

      await writeFile(path.join(stagingDirectory, file), `${JSON.stringify(mapping, null, 2)}\n`, { mode: 0o600 })
    }

    await rm(targetDirectory, { force: true, recursive: true })
    await rename(stagingDirectory, targetDirectory)
  } catch (error) {
    await rm(stagingDirectory, { force: true, recursive: true })
    throw error
  }

  return sourceFiles.length
}

export async function preparePaymentSimulator(repositoryRoot) {
  const backendEnvPath = path.join(repositoryRoot, 'backend', '.env')
  const sourceDirectory = path.join(repositoryRoot, 'infra', 'payment-simulator', 'mappings')
  const targetDirectory = path.join(repositoryRoot, 'infra', 'payment-simulator', 'generated', 'mappings')
  let envContent = await readFile(backendEnvPath, 'utf8')
  let apiKey = readEnvValue(envContent, 'PAYMENT_SIMULATOR_API_KEY')
  let generatedApiKey = false

  if (!apiKey || apiKey.length < 32) {
    apiKey = crypto.randomBytes(32).toString('hex')
    envContent = upsertEnvValue(envContent, 'PAYMENT_SIMULATOR_API_KEY', apiKey)
    await writeFile(backendEnvPath, envContent)
    generatedApiKey = true
  }

  const mappingCount = await generateMappings(sourceDirectory, targetDirectory, apiKey)

  return { generatedApiKey, mappingCount }
}

const modulePath = fileURLToPath(import.meta.url)
const isMainModule = process.argv[1] && path.resolve(process.argv[1]) === path.resolve(modulePath)

if (isMainModule) {
  const repositoryRoot = path.resolve(path.dirname(modulePath), '..', '..')

  preparePaymentSimulator(repositoryRoot)
    .then(({ generatedApiKey, mappingCount }) => {
      const keyStatus = generatedApiKey ? 'A new local API key was generated.' : 'The existing local API key was retained.'
      process.stdout.write(`${keyStatus} Prepared ${mappingCount} WireMock mappings.\n`)
    })
    .catch((error) => {
      process.stderr.write(`${error instanceof Error ? error.message : String(error)}\n`)
      process.exitCode = 1
    })
}
