import { httpClient } from '../../api/httpClient'
import type { PlatformAuditFilters, PlatformAuditResponse, PlatformRole, RolePermissionResponse } from '../../types/platform'

export async function getRolePermissions(): Promise<RolePermissionResponse> {
  return (await httpClient.get<RolePermissionResponse>('/platform/roles-permissions')).data
}

export async function updateRolePermissions(role: string, permissions: string[]): Promise<PlatformRole> {
  return (await httpClient.put<{ data: PlatformRole }>(`/platform/roles/${role}/permissions`, { permissions })).data.data
}

export async function getPlatformAuditLogs(filters: PlatformAuditFilters): Promise<PlatformAuditResponse> {
  return (await httpClient.get<PlatformAuditResponse>('/platform/audit-logs', { params: filters })).data
}

export async function exportPlatformAuditLogs(filters: PlatformAuditFilters): Promise<Blob> {
  return (await httpClient.get<Blob>('/platform/audit-logs/export', { params: filters, responseType: 'blob' })).data
}
