import axios from 'axios'

export interface ApiErrorPayload {
  message?: string
  code?: string
  errors?: Record<string, unknown>
}

export type ApiValidationErrors = Record<string, string[]>

export function getApiErrorStatus(error: unknown): number | null {
  return axios.isAxiosError(error) ? (error.response?.status ?? null) : null
}

export function shouldRetryApiQuery(failureCount: number, error: unknown): boolean {
  const status = getApiErrorStatus(error)
  return status !== 401 && status !== 419 && failureCount < 1
}

export function getApiErrorCode(error: unknown): string | null {
  if (!axios.isAxiosError<ApiErrorPayload>(error)) return null
  return typeof error.response?.data?.code === 'string' ? error.response.data.code : null
}

export function getApiValidationErrors(error: unknown): ApiValidationErrors {
  if (!axios.isAxiosError<ApiErrorPayload>(error)) return {}

  const errors = error.response?.data?.errors
  if (!errors || typeof errors !== 'object') return {}

  return Object.fromEntries(
    Object.entries(errors).flatMap(([field, value]) => {
      const messages = (Array.isArray(value) ? value : [value])
        .filter((item): item is string => typeof item === 'string' && item.trim().length > 0)
      return messages.length > 0 ? [[field, messages]] : []
    }),
  )
}

export function getApiErrorMessage(error: unknown, fallback: string): string {
  if (!axios.isAxiosError<ApiErrorPayload>(error)) return fallback

  if (error.response?.status === 429) {
    const retryAfter = error.response.headers['retry-after']
    return retryAfter
      ? `Too many requests. Try again in ${retryAfter} seconds.`
      : 'Too many requests. Please wait before trying again.'
  }

  if (error.response && error.response.status >= 500) {
    return fallback
  }

  const validationMessage = Object.values(getApiValidationErrors(error)).flat()[0]
  const responseMessage = error.response?.data?.message
  if (validationMessage) return validationMessage
  if (typeof responseMessage === 'string' && responseMessage.trim()) return responseMessage

  if (error.code === 'ECONNABORTED') {
    return 'The request timed out. Please try again.'
  }

  if (!error.response) {
    return 'The server could not be reached. Check your connection and try again.'
  }

  return fallback
}
