import { httpClient } from '../../api/httpClient'
import type { ExportFormat } from '../../components/ExportDropdown'
import type {
  FieldReportAnalytics,
  InternalReport,
  InternalReportPayload,
  InternalReportResponse,
  OperationsReportAnalytics,
  OrganizationReportAnalytics,
  PlatformReportAnalytics,
  ReportMailbox,
  ReportPeriodKey,
  ReportPerson,
} from '../../types/reporting'

export type OperationalDocument = 'alert' | 'intervention' | 'maintenance' | 'receipt'

const paths: Record<OperationalDocument, (id: number) => string> = {
  alert: (id) => `/alerts/${id}/report`,
  intervention: (id) => `/interventions/${id}/document`,
  maintenance: (id) => `/maintenances/${id}/report`,
  receipt: (id) => `/payments/${id}/receipt`,
}

export async function downloadOperationalDocument(type: OperationalDocument, id: number): Promise<Blob> {
  return (await httpClient.get<Blob>(paths[type](id), { responseType: 'blob', timeout: 30000 })).data
}

export async function getPlatformReportAnalytics(period: ReportPeriodKey): Promise<PlatformReportAnalytics> {
  return (await httpClient.get<{ data: PlatformReportAnalytics }>('/reporting/platform', { params: { period } })).data.data
}

export async function getOrganizationReportAnalytics(period: ReportPeriodKey): Promise<OrganizationReportAnalytics> {
  return (await httpClient.get<{ data: OrganizationReportAnalytics }>('/reporting/organization', { params: { period } })).data.data
}

export async function getOperationsReportAnalytics(period: ReportPeriodKey): Promise<OperationsReportAnalytics> {
  return (await httpClient.get<{ data: OperationsReportAnalytics }>('/reporting/operations', { params: { period } })).data.data
}

export async function getFieldReportAnalytics(period: ReportPeriodKey): Promise<FieldReportAnalytics> {
  return (await httpClient.get<{ data: FieldReportAnalytics }>('/reporting/field', { params: { period } })).data.data
}

export async function exportReportAnalytics(scope: 'platform' | 'organization' | 'operations' | 'field', period: ReportPeriodKey, format: ExportFormat): Promise<Blob> {
  return (await httpClient.get<Blob>(`/reporting/${scope}/export`, { params: { period, format }, responseType: 'blob', timeout: 30000 })).data
}

export async function getInternalReports(mailbox: ReportMailbox, search?: string, category?: string): Promise<InternalReportResponse> {
  return (await httpClient.get<InternalReportResponse>('/internal-reports', { params: { mailbox, search: search || undefined, category: category || undefined } })).data
}

export async function getInternalReportRecipients(): Promise<ReportPerson[]> {
  return (await httpClient.get<{ data: ReportPerson[] }>('/internal-reports/recipients')).data.data
}

export async function createInternalReport(payload: InternalReportPayload): Promise<InternalReport> {
  return (await httpClient.post<{ data: InternalReport }>('/internal-reports', payload)).data.data
}

export async function updateInternalReport(id: number, payload: Partial<InternalReportPayload>): Promise<InternalReport> {
  return (await httpClient.patch<{ data: InternalReport }>(`/internal-reports/${id}`, payload)).data.data
}

export async function sendInternalReport(id: number, recipientId?: number): Promise<InternalReport> {
  return (await httpClient.post<{ data: InternalReport }>(`/internal-reports/${id}/send`, recipientId ? { recipient_id: recipientId } : {})).data.data
}

export async function readInternalReport(id: number): Promise<InternalReport> {
  return (await httpClient.post<{ data: InternalReport }>(`/internal-reports/${id}/read`)).data.data
}

export async function archiveInternalReport(id: number): Promise<void> {
  await httpClient.post(`/internal-reports/${id}/archive`)
}

export async function deleteInternalReport(id: number): Promise<void> {
  await httpClient.delete(`/internal-reports/${id}`)
}

export async function downloadInternalReport(id: number): Promise<Blob> {
  return (await httpClient.get<Blob>(`/internal-reports/${id}/document`, { responseType: 'blob', timeout: 30000 })).data
}
