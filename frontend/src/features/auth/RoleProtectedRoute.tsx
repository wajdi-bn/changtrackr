import { Navigate, Outlet } from 'react-router-dom'
import type { UserRole } from '../../types/auth'
import { getRoleConfig } from './roleConfig'
import { useAuth } from './useAuth'

export function RoleProtectedRoute({ allowedRoles }: { allowedRoles: UserRole[] }) {
  const { primaryRole } = useAuth()

  if (!primaryRole || !allowedRoles.includes(primaryRole)) {
    return <Navigate to={getRoleConfig(primaryRole).defaultPath} replace />
  }

  return <Outlet />
}
