import type { AssetDocument } from './documents'

export type ReportPeriodKey = '7d' | '30d' | '90d'
export type ReportMailbox = 'inbox' | 'sent' | 'drafts' | 'archived'
export type InternalReportCategory = 'operations' | 'incident' | 'intervention' | 'maintenance' | 'performance' | 'handover'
export type InternalReportPriority = 'normal' | 'important' | 'urgent'

export interface ReportingPeriod {
  key: ReportPeriodKey
  label: string
  from: string
  to: string
}
export interface ReportTrendPoint {
  date: string
  sessions?: number
  energy_kwh?: number
  revenue_millimes?: number
  alerts?: number
  completed?: number
}

export interface ReportDistribution {
  key: string
  label: string
  value: number
}

export interface PlatformReportAnalytics {
  role: 'super_admin'
  period: ReportingPeriod
  kpis: { organizations: number; active_organizations: number; platform_users: number; managed_stations: number; sessions: number; revenue_millimes: number }
  trend: ReportTrendPoint[]
  organization_status: ReportDistribution[]
  user_roles: ReportDistribution[]
  organization_ranking: Array<{ id: number; name: string; status: string; stations: number; users: number; sessions: number; revenue_millimes: number }>
  risk: { inactive_organizations: number; offline_stations: number; critical_alerts: number; failed_payments: number }
  generated_by: string
}

export interface OrganizationReportAnalytics {
  role: 'admin'
  period: ReportingPeriod
  business: { revenue_millimes: number; sessions: number; energy_kwh: number; customers: number }
  workforce: { employees: number; operators: number; technicians: number; open_work: number }
  network: { stations: number; availability_percent: number; open_alerts: number; sla_breaches: number }
  trend: ReportTrendPoint[]
  alert_distribution: ReportDistribution[]
  station_performance: Array<{ id: number; name: string; city: string | null; status: string; uptime_percent: number; sessions: number; energy_kwh: number; open_alerts: number }>
  report_activity: ReportActivity
}

export interface OperationsReportAnalytics {
  role: 'operator'
  period: ReportingPeriod
  live: { available: number; charging: number; offline: number; maintenance: number; active_sessions: number; unresolved_alerts: number }
  trend: ReportTrendPoint[]
  station_status: ReportDistribution[]
  alert_severity: ReportDistribution[]
  station_watchlist: Array<{ id: number; name: string; city: string | null; status: string; uptime_percent: number; utilization_percent: number; open_alerts: number; last_heartbeat_at: string | null }>
  handover: ReportActivity & { in_progress_interventions: number; maintenance_due: number }
}

export interface FieldReportAnalytics {
  role: 'technician'
  period: ReportingPeriod
  workload: { assigned: number; in_progress: number; completed: number; overdue: number; average_minutes: number }
  completion_trend: ReportTrendPoint[]
  outcomes: ReportDistribution[]
  assignments: Array<{ id: number; reference: string; station: string | null; type: string; priority: string; status: string; scheduled_at: string | null; problem: string }>
  report_activity: ReportActivity & { field_reports_submitted: number }
}

export interface ReportActivity {
  unread_reports: number
  reports_received: number
  reports_sent: number
  draft_reports: number
}

export interface ReportPerson {
  id: number
  name: string
  avatar_url: string | null
  role: string
  email?: string
}

export interface InternalReport {
  id: number
  title: string
  category: InternalReportCategory
  priority: InternalReportPriority
  status: 'draft' | 'sent' | 'read'
  summary: string | null
  body: string
  period_start: string | null
  period_end: string | null
  related: { type: string; id: number } | null
  sender: ReportPerson | null
  recipient: ReportPerson | null
  attachments: AssetDocument[]
  sent_at: string | null
  read_at: string | null
  created_at: string
  updated_at: string
}

export interface InternalReportPayload {
  recipient_id?: number | null
  title: string
  category: InternalReportCategory
  priority: InternalReportPriority
  summary?: string
  body: string
  period_start?: string
  period_end?: string
  send_now?: boolean
}

export interface InternalReportResponse {
  data: InternalReport[]
  summary: { inbox: number; unread: number; sent: number; drafts: number }
}
