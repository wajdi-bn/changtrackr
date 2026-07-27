import { csrfCookieRequest, httpClient } from '../../api/httpClient'

export interface InvitationDetails {
  name: string
  email: string
  role: string
  organization: string
  expires_at: string
}

export async function inspectInvitation(email: string, token: string): Promise<{ valid: boolean; invitation?: InvitationDetails }> {
  await csrfCookieRequest()
  const response = await httpClient.post<{ valid: boolean; invitation?: InvitationDetails }>('/account-invitations/inspect', { email, token })
  return response.data
}

export async function acceptInvitation(payload: {
  email: string
  token: string
  password: string
  password_confirmation: string
  phone?: string
  job_title?: string
  organization_logo?: File
}): Promise<{ message: string }> {
  await csrfCookieRequest()
  const body = new FormData()
  body.append('email', payload.email)
  body.append('token', payload.token)
  body.append('password', payload.password)
  body.append('password_confirmation', payload.password_confirmation)
  if (payload.phone) body.append('phone', payload.phone)
  if (payload.job_title) body.append('job_title', payload.job_title)
  if (payload.organization_logo) body.append('organization_logo', payload.organization_logo)
  const response = await httpClient.post<{ message: string }>('/account-invitations/accept', body, {
    headers: { 'Content-Type': 'multipart/form-data' },
    timeout: 30000,
  })
  return response.data
}
