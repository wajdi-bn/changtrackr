import { backendClient, httpClient } from '../../api/httpClient'
import type { AuthUser, LoginResponse } from '../../types/auth'

export interface LoginPayload {
  email: string
  password: string
}

interface SessionResponse {
  authenticated: boolean
  user: AuthUser | null
}

let sessionRequestPromise: Promise<SessionResponse> | null = null
let sessionRequestExpiresAt = 0

export async function csrfCookieRequest(): Promise<void> {
  await backendClient.get('/sanctum/csrf-cookie')
}

export async function loginRequest(payload: LoginPayload): Promise<LoginResponse> {
  await csrfCookieRequest()
  const { data } = await httpClient.post<LoginResponse>('/auth/login', payload)
  resetSessionRequestCache()
  return data
}

export function sessionRequest(): Promise<SessionResponse> {
  const now = Date.now()

  if (!sessionRequestPromise || now >= sessionRequestExpiresAt) {
    sessionRequestExpiresAt = now + 1000
    sessionRequestPromise = (async () => {
      await csrfCookieRequest()
      const { data } = await httpClient.get<SessionResponse>('/auth/session')
      return data
    })().catch((error: unknown) => {
      resetSessionRequestCache()
      throw error
    })
  }

  return sessionRequestPromise
}

export function resetSessionRequestCache(): void {
  sessionRequestPromise = null
  sessionRequestExpiresAt = 0
}

export async function logoutRequest(): Promise<void> {
  await httpClient.post('/auth/logout')
  resetSessionRequestCache()
}
