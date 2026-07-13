import type { AuthUser, UserRole } from './auth'

export type ManagedUser = AuthUser
export type ManagedUserStatus = 'active' | 'inactive' | 'pending'
export type LastLoginFilter = 'today' | 'week' | 'month'

export interface ManagedUserPayload {
  name: string
  email: string
  phone?: string | null
  avatar_url?: string | null
  team?: string | null
  address?: string | null
  status: ManagedUserStatus
  role: UserRole
  password?: string
}

export interface ManagedUserFilters {
  search?: string
  role?: UserRole
  status?: ManagedUserStatus
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
    by_role: Record<Exclude<UserRole, 'super_admin'>, number>
  }
  meta: {
    current_page: number
    last_page: number
    per_page: number
    total: number
  }
}
