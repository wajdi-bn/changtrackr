import { httpClient } from '../../api/httpClient'
import type { AuthUser } from '../../types/auth'

export interface OnboardingProgressPayload {
  action: 'progress' | 'dismiss' | 'complete'
  current_step: number
  completed_steps: string[]
}

export interface OrganizationOnboardingPayload {
  name: string
  contact_email?: string
  contact_phone?: string
}

export async function updateOnboarding(payload: OnboardingProgressPayload): Promise<AuthUser> {
  const response = await httpClient.put<{ data: AuthUser }>('/onboarding', payload)
  return response.data.data
}

export async function updateOnboardingOrganization(
  payload: OrganizationOnboardingPayload,
): Promise<AuthUser> {
  const response = await httpClient.put<{ data: AuthUser }>('/onboarding/organization', payload)
  return response.data.data
}

export async function uploadOnboardingOrganizationLogo(logo: File): Promise<AuthUser> {
  const body = new FormData()
  body.append('logo', logo)
  const response = await httpClient.post<{ data: AuthUser }>('/onboarding/organization-logo', body, {
    headers: { 'Content-Type': 'multipart/form-data' },
  })
  return response.data.data
}
