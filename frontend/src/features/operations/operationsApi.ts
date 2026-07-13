import { httpClient } from '../../api/httpClient'
import type {
  AlertItem,
  AlertSeverity,
  AlertStatus,
  AlertsResponse,
  InterventionItem,
  InterventionPayload,
  InterventionsResponse,
  InterventionStatus,
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
