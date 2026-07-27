export type UserRole = 'super_admin' | 'admin' | 'operator' | 'technician' | 'client'

export interface OrganizationSummary {
  id: number
  name: string
  slug: string
  logo_url?: string | null
}

export interface Organization extends OrganizationSummary {
  contact_email: string | null
  contact_phone: string | null
  logo_url: string | null
  status: string
  settings: Record<string, unknown> | null
  commercial: {
    status: string
    plan: string | null
    trial_ends_at: string | null
    current_period_ends_at: string | null
    grace_ends_at: string | null
    operations_blocked: boolean
  } | null
}

export interface AuthUser {
  id: number
  name: string
  email: string
  phone: string | null
  avatar_url: string | null
  team: string | null
  address: string | null
  timezone: string | null
  status: string
  roles: UserRole[]
  permissions: string[]
  organization: Organization | null
  last_login_at: string | null
  onboarding: {
    version: number
    current_version: number
    completed: boolean
    dismissed: boolean
    should_show: boolean
    progress: {
      current_step: number
      completed_steps: string[]
      tour_completed?: boolean
    }
  }
  activity: {
    assigned_alerts: number
    assigned_interventions: number
    charging_sessions: number
    payments: number
  }
  created_at: string | null
  updated_at: string | null
}

export interface LoginResponse {
  user: AuthUser
}
