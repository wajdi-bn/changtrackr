import { httpClient } from '../../api/httpClient'
import type { ExportFormat } from '../../components/ExportDropdown'
import type { ManagedUser, ManagedUserFilters, ManagedUserPayload, ManagedUsersResponse, PlatformUsersResponse } from '../../types/user'

export async function getManagedUsers(filters: ManagedUserFilters): Promise<ManagedUsersResponse> {
  const response = await httpClient.get<ManagedUsersResponse>('/users', { params: filters })
  return response.data
}

export async function getPlatformUsers(filters: ManagedUserFilters): Promise<PlatformUsersResponse> {
  const response = await httpClient.get<PlatformUsersResponse>('/users', { params: filters })
  return response.data
}

export async function createManagedUser(payload: ManagedUserPayload): Promise<ManagedUser> {
  const response = await httpClient.post<{ data: ManagedUser }>('/users', payload)
  return response.data.data
}

export async function updateManagedUser(userId: number, payload: Partial<ManagedUserPayload>): Promise<ManagedUser> {
  const response = await httpClient.patch<{ data: ManagedUser }>(`/users/${userId}`, payload)
  return response.data.data
}

export async function deactivateManagedUser(userId: number): Promise<ManagedUser> {
  const response = await httpClient.delete<{ data: ManagedUser }>(`/users/${userId}`)
  return response.data.data
}

export async function remindEmployeeInvitation(userId: number): Promise<ManagedUser> {
  const response = await httpClient.post<{ data: ManagedUser }>(`/users/${userId}/invitation/remind`)
  return response.data.data
}

export async function renewEmployeeInvitation(userId: number): Promise<ManagedUser> {
  const response = await httpClient.post<{ data: ManagedUser }>(`/users/${userId}/invitation/renew`)
  return response.data.data
}

export async function cancelEmployeeInvitation(userId: number): Promise<ManagedUser> {
  const response = await httpClient.delete<{ data: ManagedUser }>(`/users/${userId}/invitation`)
  return response.data.data
}

export async function exportManagedUsers(filters: ManagedUserFilters, format: ExportFormat): Promise<Blob> {
  const response = await httpClient.get<Blob>('/users/export', {
    params: { ...filters, format, page: undefined, per_page: undefined },
    responseType: 'blob',
  })
  return response.data
}
