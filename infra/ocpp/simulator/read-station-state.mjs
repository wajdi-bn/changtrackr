import fs from 'node:fs'

const [identity, requestedField] = process.argv.slice(2)
const payload = JSON.parse(fs.readFileSync(0, 'utf8'))

if (!identity || !requestedField) {
  throw new Error('Usage: read-station-state <identity> <field>')
}

const normalizedKey = (key) => key.replace(/[^a-z0-9]/gi, '').toLowerCase()
const requestedKey = normalizedKey(requestedField)

function findValue(value, key) {
  if (Array.isArray(value)) {
    for (const item of value) {
      const result = findValue(item, key)
      if (result !== undefined) return result
    }
    return undefined
  }

  if (value === null || typeof value !== 'object') return undefined

  for (const [candidateKey, candidateValue] of Object.entries(value)) {
    if (normalizedKey(candidateKey) === key && candidateValue !== null) return candidateValue
  }

  for (const candidateValue of Object.values(value)) {
    const result = findValue(candidateValue, key)
    if (result !== undefined) return result
  }

  return undefined
}

function findStation(value) {
  if (Array.isArray(value)) {
    for (const item of value) {
      const result = findStation(item)
      if (result) return result
    }
    return null
  }

  if (value === null || typeof value !== 'object') return null

  for (const candidateValue of Object.values(value)) {
    const result = findStation(candidateValue)
    if (result) return result
  }

  const serialized = JSON.stringify(value)
  if (serialized.includes(`\"${identity}\"`)
    && findValue(value, 'hashid') !== undefined
    && findValue(value, requestedKey) !== undefined) {
    return value
  }

  return null
}

const station = findStation(payload)
if (!station) {
  throw new Error(`Station ${identity} was not returned by the simulator.`)
}

const result = findValue(station, requestedKey)
if (result === undefined) {
  throw new Error(`Field ${requestedField} was not found for station ${identity}.`)
}

process.stdout.write(String(result))
