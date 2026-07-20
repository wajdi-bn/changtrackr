import { httpClient } from '../../api/httpClient'
import type {
  AlertItem,
  AlertSeverity,
  AlertStatus,
  AlertsResponse,
  InterventionItem,
  InterventionPayload,
  InterventionReportPayload,
  InterventionsResponse,
  InterventionStatus,
  MaintenancePlanPayload,
  MaintenanceType,
  MaintenancesResponse,
} from '../../types/operations'

export async function getAlerts(filters: { search?: string; severity?: AlertSeverity; status?: AlertStatus }): Promise<AlertsResponse> {
  const response = await httpClient.get<AlertsResponse>('/alerts', { params: filters })
  return response.data
}

export async function createAlert(payload: {
  station_id: number
  connector_id?: number | null
  assigned_technician_id?: number | null
  title: string
  problem_type: string
  severity: AlertSeverity
  description: string
  source?: 'operator'
  due_at?: string | null
}): Promise<AlertItem> {
  const response = await httpClient.post<{ data: AlertItem }>('/alerts', payload)
  return response.data.data
}

export async function updateAlert(alertId: number, payload: Partial<{
  assigned_technician_id: number | null
  status: AlertStatus
  severity: AlertSeverity
  description: string
  due_at: string | null
}>): Promise<AlertItem> {
  const response = await httpClient.patch<{ data: AlertItem }>(`/alerts/${alertId}`, payload)
  return response.data.data
}

export async function createIntervention(alertId: number, payload: InterventionPayload): Promise<InterventionItem> {
  const response = await httpClient.post<{ data: InterventionItem }>(`/alerts/${alertId}/interventions`, payload)
  return response.data.data
}

export async function getInterventions(filters: { search?: string; status?: InterventionStatus }): Promise<InterventionsResponse> {
  const response = await httpClient.get<InterventionsResponse>('/interventions', { params: filters })
  return response.data
}

export async function updateIntervention(interventionId: number, payload: Partial<{
  assigned_technician_id: number | null
  status: InterventionStatus
  scheduled_at: string | null
  estimated_duration_minutes: number | null
  diagnosis: string | null
  resolution: string | null
  final_status: string | null
  comments: string | null
  parts: string[]
}>): Promise<InterventionItem> {
  const response = await httpClient.patch<{ data: InterventionItem }>(`/interventions/${interventionId}`, payload)
  return response.data.data
}

export async function addInterventionNote(interventionId: number, description: string): Promise<InterventionItem> {
  const response = await httpClient.post<{ data: InterventionItem }>(`/interventions/${interventionId}/notes`, { description })
  return response.data.data
}

export async function uploadInterventionPhoto(interventionId: number, payload: { photo: File; phase: 'before' | 'after' | 'evidence'; caption?: string }): Promise<InterventionItem> {
  const formData = new FormData()
  formData.append('photo', payload.photo)
  formData.append('phase', payload.phase)
  if (payload.caption) formData.append('caption', payload.caption)
  const response = await httpClient.post<{ data: InterventionItem }>(`/interventions/${interventionId}/photos`, formData, {
    headers: { 'Content-Type': 'multipart/form-data' },
    timeout: 30000,
  })
  return response.data.data
}

export async function deleteInterventionPhoto(interventionId: number, photoId: number): Promise<InterventionItem> {
  const response = await httpClient.delete<{ data: InterventionItem }>(`/interventions/${interventionId}/photos/${photoId}`)
  return response.data.data
}

export async function viewInterventionPhoto(interventionId: number, photoId: number): Promise<void> {
  const response = await httpClient.get<Blob>(`/interventions/${interventionId}/photos/${photoId}`, { responseType: 'blob' })
  const url = URL.createObjectURL(response.data)
  window.open(url, '_blank', 'noopener,noreferrer')
  window.setTimeout(() => URL.revokeObjectURL(url), 60000)
}

export async function submitInterventionReport(interventionId: number, payload: InterventionReportPayload): Promise<InterventionItem> {
  const response = await httpClient.post<{ data: InterventionItem }>(`/interventions/${interventionId}/report`, payload)
  return response.data.data
}

export async function getMaintenances(filters: {
  search?: string
  status?: InterventionStatus
  type?: MaintenanceType
  station_id?: number
  date_from?: string
  date_to?: string
}): Promise<MaintenancesResponse> {
  const response = await httpClient.get<MaintenancesResponse>('/maintenances', { params: filters })
  return response.data
}

export async function createMaintenancePlan(payload: MaintenancePlanPayload): Promise<InterventionItem> {
  const response = await httpClient.post<{ data: InterventionItem }>('/maintenances', payload)
  return response.data.data
}

export async function updateMaintenanceOccurrence(interventionId: number, payload: Partial<{
  assigned_technician_id: number
  scheduled_at: string
  estimated_duration_minutes: number
  priority: AlertSeverity
  problem: string
}>): Promise<InterventionItem> {
  const response = await httpClient.patch<{ data: InterventionItem }>(`/maintenances/${interventionId}`, payload)
  return response.data.data
}
