import { backendClient, httpClient } from '../../api/httpClient'
import type { AuthUser, LoginResponse } from '../../types/auth'

export interface LoginPayload {
  email: string
  password: string
}

export async function csrfCookieRequest(): Promise<void> {
  await backendClient.get('/sanctum/csrf-cookie')
}

export async function loginRequest(payload: LoginPayload): Promise<LoginResponse> {
  await csrfCookieRequest()
  const { data } = await httpClient.post<LoginResponse>('/auth/login', payload)
  return data
}

export async function currentUserRequest(): Promise<AuthUser> {
  const { data } = await httpClient.get<{ data?: AuthUser } | AuthUser>('/auth/me')
  return 'data' in data && data.data ? data.data : (data as AuthUser)
}

export async function logoutRequest(): Promise<void> {
  await httpClient.post('/auth/logout')
}
