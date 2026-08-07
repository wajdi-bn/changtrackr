import { Navigate, Outlet } from 'react-router-dom'
import type { UserRole } from '../../types/auth'
import { getRoleConfig } from './roleConfig'
import { useAuth } from './useAuth'

export function RoleProtectedRoute({ allowedRoles }: { allowedRoles: UserRole[] }) {
  const { hasRole, primaryRole } = useAuth()

  if (!primaryRole) {
    return <Navigate to="/login?oauth_error=invalid_role" replace />
  }

  if (!hasRole(allowedRoles)) {
    return <Navigate to={getRoleConfig(primaryRole).defaultPath} replace />
  }

  return <Outlet />
}
