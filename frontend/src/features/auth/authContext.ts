import { createContext } from 'react'
import type { AuthUser, UserRole } from '../../types/auth'

export interface AuthContextValue {
  user: AuthUser | null
  isAuthenticated: boolean
  isLoading: boolean
  primaryRole: UserRole | null
  login: (email: string, password: string) => Promise<AuthUser>
  logout: () => Promise<void>
  hasRole: (roles: UserRole[]) => boolean
}

export const AuthContext = createContext<AuthContextValue | null>(null)
