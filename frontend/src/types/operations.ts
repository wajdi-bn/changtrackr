import type { PaginationMeta } from './pagination'

export type AlertSeverity = 'critical' | 'warning' | 'info'
export type AlertStatus = 'new' | 'in-progress' | 'resolved'
export type InterventionStatus = 'assigned' | 'in-progress' | 'paused' | 'waiting-parts' | 'resolved' | 'cancelled'
export type InterventionOutcome = 'operational' | 'operational-monitoring' | 'follow-up-required'

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
  meta: PaginationMeta
}

export interface InterventionItem {
  id: number
  reference: string
  organization_id: number
  alert_id: number | null
  maintenance_plan_id: number | null
  maintenance_occurrence_number: number | null
  source: 'alert' | 'maintenance'
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
  report: InterventionReport | null
  photos: InterventionPhoto[]
  alert: { id: number; reference: string; title: string } | null
  maintenance_plan: MaintenancePlanSummary | null
  station: {
    id: number
    name: string
    city: string
    availability_override: 'maintenance' | 'disabled' | null
    maintenance_intervention_id: number | null
  }
  connector: { id: number; external_id: string; type: string } | null
  assigned_technician: TechnicianOption | null
  events: WorkflowEvent[]
}

export interface InterventionReport {
  id: number
  diagnosis: string
  actions_taken: string
  final_outcome: InterventionOutcome
  safety_checks: {
    work_area_safe: boolean
    connector_inspected: boolean
    station_status_verified: boolean
  }
  parts: string[]
  observations: string | null
  actual_duration_minutes: number
  submitted_at: string
  submitted_by: { id: number; name: string } | null
}

export interface InterventionPhoto {
  id: number
  phase: 'before' | 'after' | 'evidence'
  caption: string | null
  original_name: string
  mime_type: string
  size_bytes: number
  uploaded_at: string
  uploaded_by: { id: number; name: string } | null
}

export interface InterventionReportPayload {
  diagnosis: string
  actions_taken: string
  final_outcome: InterventionOutcome
  observations?: string | null
  parts: string[]
  safety_checks: {
    work_area_safe: boolean
    connector_inspected: boolean
    station_status_verified: boolean
  }
}

export type MaintenanceType = 'preventive' | 'corrective'
export type MaintenanceRecurrence = 'none' | 'daily' | 'weekly' | 'monthly'

export interface MaintenancePlanSummary {
  id: number
  reference: string
  title: string
  type: MaintenanceType
  status: 'active' | 'paused' | 'completed' | 'cancelled'
  recurrence_frequency: MaintenanceRecurrence
  recurrence_interval: number
  recurrence_ends_at: string | null
  next_occurrence_at: string | null
}

export interface MaintenanceStationOption {
  id: number
  name: string
  reference: string
  connectors: Array<{ id: number; external_id: string; type: string }>
}

export interface MaintenancesResponse {
  data: InterventionItem[]
  summary: { total: number; planned: number; in_progress: number; completed: number; cancelled: number }
  technicians: TechnicianOption[]
  stations: MaintenanceStationOption[]
}

export interface MaintenancePlanPayload {
  station_id: number
  connector_id?: number | null
  assigned_technician_id: number
  title: string
  type: MaintenanceType
  priority: AlertSeverity
  instructions: string
  first_scheduled_at: string
  estimated_duration_minutes: number
  recurrence_frequency: MaintenanceRecurrence
  recurrence_interval: number
  recurrence_ends_at?: string | null
}

export interface InterventionsResponse {
  data: InterventionItem[]
  summary: { total: number; assigned: number; in_progress: number; resolved: number; cancelled: number }
  technicians: TechnicianOption[]
}

export interface InterventionPayload {
  assigned_technician_id: number
  scheduled_at?: string | null
  estimated_duration_minutes?: number | null
  problem?: string | null
  comments?: string | null
  parts?: string[]
}
