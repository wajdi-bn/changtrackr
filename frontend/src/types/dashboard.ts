import type { UserRole } from './auth'

export type DashboardPeriodKey = '7d' | '30d' | '90d'
export type DashboardValueFormat = 'number' | 'percentage' | 'currency' | 'energy' | 'duration'

export interface DashboardPeriod {
  key: DashboardPeriodKey
  days: number
  label: string
  comparison_label: string
  start: string
  end: string
}

export interface DashboardKpi {
  key: string
  label: string
  value: number
  format: DashboardValueFormat
  change_percent: number | null
  context: string
}

export interface DashboardTrendSeries {
  key: string
  label: string
}

export interface DashboardTrendPoint {
  date: string
  label: string
  sessions: number
  energy_kwh: number
  revenue_tnd: number
  alerts: number
  completed: number
  availability_percent?: number
  resolution_hours?: number
}

export interface DashboardBreakdownItem {
  key: string
  label: string
  count: number
  percentage: number
}

export interface DashboardBreakdown {
  key: string
  title: string
  total: number
  items: DashboardBreakdownItem[]
}

export interface DashboardRankingItem {
  id: number | string
  label: string
  secondary: string
  value: number
  unit: string
  action_url: string
}

export interface DashboardRanking {
  key: string
  title: string
  description: string
  items: DashboardRankingItem[]
}

export interface DashboardActivity {
  id: string
  type: string
  title: string
  description: string
  status: string
  occurred_at: string
  occurred_relative: string
  action_url: string
}

export interface DashboardOrganizationWidget {
  id: number | null
  name: string | null
  status: string | null
  contact_email: string | null
  employees: number
  stations: number
}

export interface DashboardHealthWidget {
  score: number
  factors: Array<{ label: string; value: number }>
}

export interface DashboardTechnicianTask {
  id: number
  reference: string
  label: string
  station: string
  scheduled_at: string | null
  scheduled_label: string
  status: string
  priority: string
  action_url: string
}

export interface DashboardCriticalAlert {
  id: number
  title: string
  station: string
  connector: string | null
  status: string
  due_at: string | null
  due_label: string | null
  action_url: string
}

export interface DashboardFaultWidget {
  id: number
  station: string
  issue: string
  severity: string
  occurred_relative: string
  action_url: string
}

export interface DashboardPerformanceWidget {
  label: string
  value: number | null
  unit: string
  helper: string
}

export interface DashboardActiveSessionWidget {
  id: number
  reference: string
  station: string
  connector: string
  status: string
  started_at: string
  energy_kwh: number
  current_power_kw: number | null
  state_of_charge_percent: number | null
  total_millimes: number
  action_url: string
}

export interface DashboardIdentifierWidget {
  label: string | null
  masked_token: string
  status: string
  last_used_at: string | null
}

export interface DashboardSubscriptionWidget {
  plan: string | null
  organization: string | null
  discount_basis_points: number
  current_period_ends_at: string
}

export interface DashboardVehicleWidget {
  id: number
  name: string
  make: string | null
  model: string | null
  connector_types: string[]
  battery_capacity_kwh: number | null
}

export interface DashboardRecentSessionWidget {
  id: number
  reference: string
  station: string
  energy_kwh: number
  amount_millimes: number
  status: string
  payment_status: string
  started_at: string
  action_url: string
}

export interface DashboardWidgets {
  module_counts?: Record<string, number>
  organization?: DashboardOrganizationWidget
  health?: DashboardHealthWidget
  tasks?: DashboardTechnicianTask[]
  critical_alerts?: DashboardCriticalAlert[]
  recent_faults?: DashboardFaultWidget[]
  performance?: DashboardPerformanceWidget[]
  active_session?: DashboardActiveSessionWidget | null
  identifier?: DashboardIdentifierWidget | null
  subscription?: DashboardSubscriptionWidget | null
  vehicle?: DashboardVehicleWidget | null
  recent_sessions?: DashboardRecentSessionWidget[]
}

export interface DashboardData {
  role: UserRole
  period: DashboardPeriod
  headline: string
  description: string
  kpis: DashboardKpi[]
  trend: {
    title: string
    series: DashboardTrendSeries[]
    points: DashboardTrendPoint[]
  }
  breakdowns: DashboardBreakdown[]
  rankings: DashboardRanking[]
  recent_activity: DashboardActivity[]
  methodology: string[]
  widgets: DashboardWidgets
  generated_at: string
}
