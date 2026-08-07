import assert from 'node:assert/strict'
import test from 'node:test'
import { AxiosError, AxiosHeaders } from 'axios'
import type { InternalAxiosRequestConfig } from 'axios'
import {
  getApiErrorCode,
  getApiErrorMessage,
  getApiErrorStatus,
  getApiValidationErrors,
  shouldRetryApiQuery,
} from '../src/api/apiErrors.ts'

function apiError(status: number, data: unknown, headers: Record<string, string> = {}): AxiosError {
  const config = { headers: new AxiosHeaders() } as InternalAxiosRequestConfig
  return new AxiosError('Request failed', 'ERR_BAD_RESPONSE', config, undefined, {
    config,
    data,
    headers,
    status,
    statusText: 'Error',
  })
}

test('extracts Laravel validation details, status and error code', () => {
  const error = apiError(422, {
    code: 'invalid_station',
    message: 'The request is invalid.',
    errors: { station_id: ['Select an available station.'] },
  })

  assert.equal(getApiErrorStatus(error), 422)
  assert.equal(getApiErrorCode(error), 'invalid_station')
  assert.deepEqual(getApiValidationErrors(error), {
    station_id: ['Select an available station.'],
  })
  assert.equal(getApiErrorMessage(error, 'Fallback'), 'Select an available station.')
})

test('uses the API message when no validation detail exists', () => {
  assert.equal(
    getApiErrorMessage(apiError(409, { message: 'The connector is already occupied.' }), 'Fallback'),
    'The connector is already occupied.',
  )
})

test('normalizes rate-limit and network failures', () => {
  assert.equal(
    getApiErrorMessage(apiError(429, {}, { 'retry-after': '30' }), 'Fallback'),
    'Too many requests. Try again in 30 seconds.',
  )
  assert.equal(
    getApiErrorMessage(new AxiosError('Network Error', 'ERR_NETWORK'), 'Fallback'),
    'The server could not be reached. Check your connection and try again.',
  )
})

test('does not expose technical server messages', () => {
  const error = apiError(500, {
    message: 'SQLSTATE[42803]: internal database query details',
  })

  assert.equal(getApiErrorMessage(error, 'The operation could not be completed.'), 'The operation could not be completed.')
})

test('does not let the query layer repeat authentication and CSRF failures', () => {
  assert.equal(shouldRetryApiQuery(0, apiError(401, {})), false)
  assert.equal(shouldRetryApiQuery(0, apiError(419, {})), false)
  assert.equal(shouldRetryApiQuery(0, apiError(503, {})), true)
  assert.equal(shouldRetryApiQuery(1, apiError(503, {})), false)
})

test('returns the contextual fallback for unknown errors', () => {
  assert.equal(getApiErrorMessage(new Error('Unexpected'), 'Contextual fallback'), 'Contextual fallback')
})
