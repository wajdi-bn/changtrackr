export type UserRole = 'super_admin' | 'admin' | 'operator' | 'technician' | 'client'

export interface Organization {
  id: number
  name: string
  slug: string
  contact_email: string | null
  contact_phone: string | null
  status: string
  settings: Record<string, unknown> | null
}

export interface AuthUser {
  id: number
  name: string
  email: string
  phone: string | null
  avatar_url: string | null
  status: string
  roles: UserRole[]
  permissions: string[]
  organization: Organization | null
  last_login_at: string | null
}

export interface LoginResponse {
  token_type: 'Bearer'
  access_token: string
  user: AuthUser
}
