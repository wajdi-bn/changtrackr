import axios from 'axios'
import type { AxiosInstance, InternalAxiosRequestConfig } from 'axios'

type RetriableRequestConfig = InternalAxiosRequestConfig & {
  _csrfRetry?: boolean
}

interface ResponseInterceptorOptions {
  csrfCookiePath: string
  refreshCsrf: () => Promise<void>
  onUnauthorized: () => void
}

export function installResponseInterceptor(
  client: AxiosInstance,
  { csrfCookiePath, refreshCsrf, onUnauthorized }: ResponseInterceptorOptions,
): void {
  client.interceptors.response.use(
    (response) => response,
    async (error: unknown) => {
      if (!axios.isAxiosError(error) || !error.config) throw error

      if (error.response?.status === 401) {
        onUnauthorized()
        throw error
      }

      const request = error.config as RetriableRequestConfig
      const isCsrfCookieRequest = request.url?.includes(csrfCookiePath) ?? false
      if (error.response?.status !== 419 || request._csrfRetry || isCsrfCookieRequest) {
        throw error
      }

      request._csrfRetry = true
      await refreshCsrf()
      return client.request(request)
    },
  )
}
