export type DemoTopic = 'platform' | 'operator' | 'technician' | 'client' | 'admin'

export type DemoRequestStatus =
  | 'new'
  | 'under_review'
  | 'contacted'
  | 'demo_scheduled'
  | 'qualified'
  | 'approved'
  | 'provisioned'
  | 'rejected'

export interface PublicDemoRequestPayload {
  full_name: string
  email: string
  company_name: string
  phone?: string
  topic: DemoTopic
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
  topic: DemoTopic
  estimated_stations: number | null
  message: string
  status: DemoRequestStatus
  allowed_transitions: DemoRequestStatus[]
  scheduled_at: string | null
  internal_notes: string | null
  handled_by: { id: number; name: string } | null
  organization: { id: number; name: string; slug: string; status: string } | null
  invitation: { status: string; expires_at: string | null; accepted_at: string | null } | null
  consent_at: string | null
  provisioned_at: string | null
  created_at: string
  updated_at: string
}

export interface DemoRequestFilters {
  search?: string
  status?: DemoRequestStatus
  topic?: DemoTopic
  page?: number
  per_page?: number
}

export interface DemoRequestsResponse {
  data: DemoRequest[]
  summary: {
    total: number
    new: number
    in_progress: number
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
