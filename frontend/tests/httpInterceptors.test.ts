import assert from 'node:assert/strict'
import test from 'node:test'
import axios, { AxiosError, AxiosHeaders } from 'axios'
import type { InternalAxiosRequestConfig } from 'axios'
import { installResponseInterceptor } from '../src/api/httpInterceptors.ts'

function rejectedResponse(status: number, config: InternalAxiosRequestConfig): AxiosError {
  return new AxiosError('Request failed', 'ERR_BAD_RESPONSE', config, undefined, {
    config,
    data: {},
    headers: {},
    status,
    statusText: 'Error',
  })
}

function successfulResponse(config: InternalAxiosRequestConfig) {
  return {
    config,
    data: { ok: true },
    headers: new AxiosHeaders(),
    status: 200,
    statusText: 'OK',
  }
}

test('refreshes CSRF and retries a rejected request once', async () => {
  const client = axios.create()
  let requests = 0
  let refreshes = 0
  client.defaults.adapter = async (config) => {
    requests += 1
    if (requests === 1) throw rejectedResponse(419, config)
    return successfulResponse(config)
  }
  installResponseInterceptor(client, {
    csrfCookiePath: '/sanctum/csrf-cookie',
    refreshCsrf: async () => { refreshes += 1 },
    onUnauthorized: () => undefined,
  })

  const response = await client.post('/protected-action', { value: 1 })
  assert.equal(response.status, 200)
  assert.equal(requests, 2)
  assert.equal(refreshes, 1)
})

test('does not loop when the retried request still returns 419', async () => {
  const client = axios.create()
  let requests = 0
  let refreshes = 0
  client.defaults.adapter = async (config) => {
    requests += 1
    throw rejectedResponse(419, config)
  }
  installResponseInterceptor(client, {
    csrfCookiePath: '/sanctum/csrf-cookie',
    refreshCsrf: async () => { refreshes += 1 },
    onUnauthorized: () => undefined,
  })

  await assert.rejects(client.post('/protected-action'))
  assert.equal(requests, 2)
  assert.equal(refreshes, 1)
})

test('never retries the CSRF-cookie request itself', async () => {
  const client = axios.create()
  let refreshes = 0
  client.defaults.adapter = async (config) => {
    throw rejectedResponse(419, config)
  }
  installResponseInterceptor(client, {
    csrfCookiePath: '/sanctum/csrf-cookie',
    refreshCsrf: async () => { refreshes += 1 },
    onUnauthorized: () => undefined,
  })

  await assert.rejects(client.get('/sanctum/csrf-cookie'))
  assert.equal(refreshes, 0)
})

test('reports unauthorized responses without retrying them', async () => {
  const client = axios.create()
  let unauthorized = 0
  let requests = 0
  client.defaults.adapter = async (config) => {
    requests += 1
    throw rejectedResponse(401, config)
  }
  installResponseInterceptor(client, {
    csrfCookiePath: '/sanctum/csrf-cookie',
    refreshCsrf: async () => undefined,
    onUnauthorized: () => { unauthorized += 1 },
  })

  await assert.rejects(client.get('/protected-resource'))
  assert.equal(requests, 1)
  assert.equal(unauthorized, 1)
})
