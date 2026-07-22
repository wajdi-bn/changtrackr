import { httpClient } from '../../api/httpClient'
import type { ProfileData, UpdateProfilePayload } from '../../types/profile'

export async function getProfile(): Promise<ProfileData> {
  const response = await httpClient.get<{ data: ProfileData }>('/profile')
  return response.data.data
}

export async function updateProfile(payload: UpdateProfilePayload): Promise<ProfileData> {
  const response = await httpClient.put<{ data: ProfileData }>('/profile', payload)
  return response.data.data
}

export async function uploadProfileAvatar(avatar: File): Promise<ProfileData> {
  const body = new FormData()
  body.append('avatar', avatar)
  const response = await httpClient.post<{ data: ProfileData }>('/profile/avatar', body, {
    headers: { 'Content-Type': 'multipart/form-data' },
  })
  return response.data.data
}

export async function removeProfileAvatar(): Promise<ProfileData> {
  const response = await httpClient.delete<{ data: ProfileData }>('/profile/avatar')
  return response.data.data
}
