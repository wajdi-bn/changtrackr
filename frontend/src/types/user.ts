import type { AuthUser, UserRole } from './auth'

export type EmployeeRole = Exclude<UserRole, 'client'>
export type EmployeeInvitationStatus = 'pending' | 'accepted' | 'expired' | 'revoked'
export interface EmployeeInvitation {
  status: EmployeeInvitationStatus
  expires_at: string | null
  last_sent_at: string | null
  accepted_at: string | null
  cancelled_at: string | null
  can_remind: boolean
  can_cancel: boolean
  can_renew: boolean
}
export type ManagedUser = Omit<AuthUser, 'roles'> & { roles: EmployeeRole[]; invitation: EmployeeInvitation | null }
export type PlatformUser = Omit<AuthUser, 'roles'> & { roles: UserRole[]; invitation: EmployeeInvitation | null }
export type ManagedUserStatus = 'active' | 'inactive' | 'pending'
export type ManagedUserFilterStatus = ManagedUserStatus | 'expired' | 'revoked'
export type LastLoginFilter = 'today' | 'week' | 'month'

export interface ManagedUserPayload {
  organization_id?: number | null
  name: string
  email: string
  phone?: string | null
  avatar_url?: string | null
  team?: string | null
  address?: string | null
  status?: ManagedUserStatus
  role: EmployeeRole
}

export interface ManagedUserFilters {
  organization_id?: number
  search?: string
  role?: UserRole
  status?: ManagedUserFilterStatus
  team?: string
  last_login?: LastLoginFilter
  page?: number
  per_page?: number
}

export interface ManagedUsersResponse {
  data: ManagedUser[]
  summary: {
    total: number
    active: number
    inactive: number
    pending: number
    by_role: Record<UserRole, number>
  }
  meta: {
    current_page: number
    last_page: number
    per_page: number
    total: number
  }
}

export interface PlatformUsersResponse extends Omit<ManagedUsersResponse, 'data'> {
  data: PlatformUser[]
}
