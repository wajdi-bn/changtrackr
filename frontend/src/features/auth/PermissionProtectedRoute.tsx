import { Navigate, Outlet } from 'react-router-dom'
import { getRoleConfig } from './roleConfig'
import { useAuth } from './useAuth'

export function PermissionProtectedRoute({ permission }: { permission: string }) {
  const { user, primaryRole } = useAuth()

  if (!primaryRole) {
    return <Navigate to="/login?oauth_error=invalid_role" replace />
  }

  if (!user?.permissions.includes(permission)) {
    return <Navigate to={getRoleConfig(primaryRole).defaultPath} replace />
  }

  return <Outlet />
}
