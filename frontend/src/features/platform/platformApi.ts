import { httpClient } from '../../api/httpClient'
import type { PlatformAuditFilters, PlatformAuditResponse, PlatformIntegrationResponse, PlatformRole, PlatformSettingResponse, RolePermissionResponse } from '../../types/platform'

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

export async function getPlatformIntegrations(): Promise<PlatformIntegrationResponse> {
  return (await httpClient.get<PlatformIntegrationResponse>('/platform/integrations')).data
}

export async function getPlatformSettings(): Promise<PlatformSettingResponse> {
  return (await httpClient.get<PlatformSettingResponse>('/platform/system-settings')).data
}

export async function updatePlatformSettings(settings: Record<string, boolean | number | string>): Promise<PlatformSettingResponse> {
  return (await httpClient.put<PlatformSettingResponse>('/platform/system-settings', { settings })).data
}
