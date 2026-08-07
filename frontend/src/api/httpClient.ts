import axios from 'axios'
import { notifySessionExpired } from '../features/auth/authSession'
import { installResponseInterceptor } from './httpInterceptors'

const browserLocation = typeof window === 'undefined'
  ? { protocol: 'http:', hostname: 'localhost' }
  : window.location
const localBackendUrl = `${browserLocation.protocol}//${browserLocation.hostname}:8000`
const baseURL = import.meta.env.DEV
  ? `${localBackendUrl}/api`
  : (import.meta.env.VITE_API_URL ?? `${localBackendUrl}/api`)
export const backendUrl = (
  import.meta.env.DEV
    ? localBackendUrl
    : (import.meta.env.VITE_BACKEND_URL ?? baseURL.replace(/\/api\/?$/, ''))
).replace(/\/$/, '')

export const httpClient = axios.create({
  baseURL,
  timeout: 10000,
  withCredentials: true,
  withXSRFToken: true,
  headers: {
    'Content-Type': 'application/json',
    Accept: 'application/json',
  },
})

export const backendClient = axios.create({
  baseURL: backendUrl,
  timeout: 10000,
  withCredentials: true,
  withXSRFToken: true,
  headers: {
    Accept: 'application/json',
  },
})

const csrfCookiePath = '/sanctum/csrf-cookie'
let csrfRequestPromise: Promise<void> | null = null

export function csrfCookieRequest(): Promise<void> {
  if (!csrfRequestPromise) {
    csrfRequestPromise = backendClient.get(csrfCookiePath)
      .then(() => undefined)
      .finally(() => {
        csrfRequestPromise = null
      })
  }

  return csrfRequestPromise
}

const responseInterceptorOptions = {
  csrfCookiePath,
  refreshCsrf: csrfCookieRequest,
  onUnauthorized: notifySessionExpired,
}

installResponseInterceptor(httpClient, responseInterceptorOptions)
installResponseInterceptor(backendClient, responseInterceptorOptions)
