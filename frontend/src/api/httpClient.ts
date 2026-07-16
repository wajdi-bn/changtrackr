import axios from 'axios'

const baseURL = import.meta.env.VITE_API_URL ?? 'http://localhost:8000/api'
export const backendUrl = (
  import.meta.env.VITE_BACKEND_URL ?? baseURL.replace(/\/api\/?$/, '')
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
