import { httpClient } from '../../api/httpClient'

export interface InvitationDetails {
  name: string
  email: string
  role: string
  organization: string
  expires_at: string
}

export async function inspectInvitation(email: string, token: string): Promise<{ valid: boolean; invitation?: InvitationDetails }> {
  const response = await httpClient.post<{ valid: boolean; invitation?: InvitationDetails }>('/account-invitations/inspect', { email, token })
  return response.data
}

export async function acceptInvitation(payload: {
  email: string
  token: string
  password: string
  password_confirmation: string
}): Promise<{ message: string }> {
  const response = await httpClient.post<{ message: string }>('/account-invitations/accept', payload)
  return response.data
}
