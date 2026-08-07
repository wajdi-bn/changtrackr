import { csrfCookieRequest, httpClient } from '../../api/httpClient'
import { getApiErrorCode } from '../../api/apiErrors'
import type { AuthUser, LoginResponse } from '../../types/auth'

export interface LoginPayload {
  email: string
  password: string
}

export interface RegisterClientPayload {
  name: string
  email: string
  password: string
  password_confirmation: string
  terms_accepted: boolean
}

export interface ResetPasswordPayload {
  token: string
  email: string
  password: string
  password_confirmation: string
}

interface MessageResponse {
  message: string
}

interface RegistrationResponse extends MessageResponse {
  code: 'verification_required'
  email: string
}

interface SessionResponse {
  authenticated: boolean
  user: AuthUser | null
}

let sessionRequestPromise: Promise<SessionResponse> | null = null
let sessionRequestExpiresAt = 0

export async function loginRequest(payload: LoginPayload): Promise<LoginResponse> {
  await csrfCookieRequest()
  const { data } = await httpClient.post<LoginResponse>('/auth/login', payload)
  resetSessionRequestCache()
  return data
}

export async function registerClientRequest(
  payload: RegisterClientPayload,
): Promise<RegistrationResponse> {
  await csrfCookieRequest()
  const { data } = await httpClient.post<RegistrationResponse>('/auth/register', payload)
  return data
}

export async function resendVerificationRequest(email: string): Promise<MessageResponse> {
  await csrfCookieRequest()
  const { data } = await httpClient.post<MessageResponse>('/auth/email/resend', { email })
  return data
}

export async function forgotPasswordRequest(email: string): Promise<MessageResponse> {
  await csrfCookieRequest()
  const { data } = await httpClient.post<MessageResponse>('/auth/forgot-password', { email })
  return data
}

export async function resetPasswordRequest(
  payload: ResetPasswordPayload,
): Promise<MessageResponse> {
  await csrfCookieRequest()
  const { data } = await httpClient.post<MessageResponse>('/auth/reset-password', payload)
  return data
}

export { getApiErrorCode as getAuthErrorCode }

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
