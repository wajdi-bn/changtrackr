export type AlertSeverity = 'critical' | 'warning' | 'info'
export type AlertStatus = 'new' | 'in-progress' | 'resolved'
export type InterventionStatus = 'assigned' | 'in-progress' | 'paused' | 'waiting-parts' | 'resolved'

export interface WorkflowEvent {
  id: number
  event_type: string
  description: string
  occurred_at: string
  occurred_relative: string
}

export interface TechnicianOption {
  id: number
  name: string
  avatar_url: string | null
}

export interface AlertItem {
  id: number
  reference: string
  organization_id: number
  title: string
  problem_type: string
  severity: AlertSeverity
  status: AlertStatus
  source: string
  description: string
  ocpp_log: string | null
  suggested_cause: string | null
  recommended_action: string | null
  detected_at: string
  detected_relative: string
  due_at: string | null
  resolved_at: string | null
  station: { id: number; name: string; city: string; reference: string }
  connector: { id: number; external_id: string; type: string } | null
  assigned_technician: TechnicianOption | null
  events: WorkflowEvent[]
  intervention: { id: number; reference: string; status: InterventionStatus } | null
}

export interface AlertsResponse {
  data: AlertItem[]
  summary: { total: number; critical: number; new: number; in_progress: number }
  technicians: TechnicianOption[]
}

export interface InterventionItem {
  id: number
  reference: string
  organization_id: number
  alert_id: number
  status: InterventionStatus
  priority: AlertSeverity
  scheduled_at: string | null
  started_at: string | null
  ended_at: string | null
  estimated_duration_minutes: number | null
  problem: string
  diagnosis: string | null
  resolution: string | null
  final_status: string | null
  comments: string | null
  parts: string[]
  alert: { id: number; reference: string; title: string }
  station: { id: number; name: string; city: string }
  connector: { id: number; external_id: string; type: string } | null
  assigned_technician: TechnicianOption | null
  events: WorkflowEvent[]
}

export interface InterventionsResponse {
  data: InterventionItem[]
  summary: { total: number; assigned: number; in_progress: number; resolved: number }
}

export interface InterventionPayload {
  assigned_technician_id: number
  scheduled_at?: string | null
  estimated_duration_minutes?: number | null
  problem?: string | null
  comments?: string | null
  parts?: string[]
}
