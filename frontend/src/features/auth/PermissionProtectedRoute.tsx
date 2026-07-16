import { Navigate, Outlet } from 'react-router-dom'
import { getRoleConfig } from './roleConfig'
import { useAuth } from './useAuth'

export function PermissionProtectedRoute({ permission }: { permission: string }) {
  const { user, primaryRole } = useAuth()

  if (!user?.permissions.includes(permission)) {
    return <Navigate to={getRoleConfig(primaryRole).defaultPath} replace />
  }

  return <Outlet />
}
