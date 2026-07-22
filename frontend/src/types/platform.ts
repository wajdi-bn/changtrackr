export interface PlatformPermission {
  name: string
  module: string
  action: string
  label: string
}

export interface PlatformRole {
  name: 'super_admin' | 'admin' | 'operator' | 'technician' | 'client'
  label: string
  description: string
  boundary: string
  immutable: boolean
  users_count: number
  permissions: string[]
}

export interface RolePermissionResponse {
  data: { roles: PlatformRole[]; permissions: PlatformPermission[] }
  summary: { roles: number; permissions: number; assignments: number; editable_roles: number }
}

export interface PlatformAuditLog {
  id: number
  event_type: string
  module: string
  action: string
  description: string
  metadata: Record<string, unknown>
  ip_address: string | null
  created_at: string
  subject: { type: string; id: number | null } | null
  actor: { id: number; name: string; email: string; avatar_url: string | null; roles: string[] } | null
  organization: { id: number; name: string } | null
}

export interface PlatformAuditFilters {
  search?: string
  event_type?: string
  module?: string
  actor_id?: number
  role?: string
  organization_id?: number
  date_from?: string
  date_to?: string
  page?: number
  per_page?: number
}

export interface PlatformAuditResponse {
  data: PlatformAuditLog[]
  summary: { total: number; today: number; actors: number; organizations: number }
  facets: {
    event_types: Array<{ value: string; count: number }>
    actors: Array<{ id: number; name: string; role: string | null }>
    organizations: Array<{ id: number; name: string }>
  }
  meta: { current_page: number; last_page: number; per_page: number; total: number }
}
