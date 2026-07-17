export type DemoObjective =
  | 'availability_monitoring'
  | 'remote_supervision'
  | 'maintenance_coordination'
  | 'charging_activity'
  | 'team_access'
  | 'ocpp_onboarding'
  | 'performance_uptime'

export type DemoRequestStatus =
  | 'submitted'
  | 'under_review'
  | 'provisioned'
  | 'rejected'

export type DemoInvitationStatus = 'pending' | 'accepted' | 'expired' | 'revoked'

export interface PublicDemoRequestPayload {
  full_name: string
  email: string
  company_name: string
  phone?: string
  objectives: DemoObjective[]
  estimated_stations?: number
  message: string
  consent_accepted: boolean
  website?: string
}

export interface DemoRequest {
  id: number
  reference: string
  full_name: string
  email: string
  company_name: string
  phone: string | null
  objectives: DemoObjective[]
  estimated_stations: number | null
  message: string
  status: DemoRequestStatus
  internal_notes: string | null
  rejection_reason: string | null
  handled_by: { id: number; name: string } | null
  organization: { id: number; name: string; slug: string; status: string } | null
  invitation: { status: DemoInvitationStatus; expires_at: string | null; accepted_at: string | null } | null
  consent_at: string | null
  review_started_at: string | null
  decided_at: string | null
  provisioned_at: string | null
  created_at: string
  updated_at: string
}

export interface DemoRequestFilters {
  search?: string
  status?: DemoRequestStatus
  objective?: DemoObjective
  page?: number
  per_page?: number
}

export interface DemoRequestsResponse {
  data: DemoRequest[]
  summary: {
    total: number
    submitted: number
    under_review: number
    provisioned: number
    rejected: number
  }
  meta: {
    current_page: number
    last_page: number
    per_page: number
    total: number
  }
}

export interface ProvisionDemoRequestPayload {
  organization_name: string
  admin_name: string
  trial_days: number
}

export interface RejectDemoRequestPayload {
  rejection_reason: string
  internal_notes?: string
}
