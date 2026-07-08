import axios from 'axios'

const baseURL = import.meta.env.VITE_API_URL ?? 'http://127.0.0.1:8000/api'

export const authTokenStorageKey = 'chargetrackr_access_token'

export const httpClient = axios.create({
  baseURL,
  timeout: 10000,
  headers: {
    'Content-Type': 'application/json',
    Accept: 'application/json',
  },
})

httpClient.interceptors.request.use((config) => {
  const token = localStorage.getItem(authTokenStorageKey)

  if (token) {
    config.headers.Authorization = `Bearer ${token}`
  }

  return config
})

httpClient.interceptors.response.use(
  (response) => response,
  (error) => {
    if (error.response?.status === 401) {
      localStorage.removeItem(authTokenStorageKey)
      localStorage.removeItem('chargetrackr_user')
    }

    return Promise.reject(error)
  },
)
