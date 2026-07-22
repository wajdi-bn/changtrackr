import axios from 'axios'

const localBackendUrl = `${window.location.protocol}//${window.location.hostname}:8000`
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

export async function csrfCookieRequest(): Promise<void> {
  await backendClient.get('/sanctum/csrf-cookie')
}
