import { Spin } from 'antd'
import type { ReactNode } from 'react'
import { Navigate, Outlet } from 'react-router-dom'
import { getAuthenticatedEntryPath } from './authNavigation'
import { useAuth } from './useAuth'

export function GuestRoute({ children }: { children?: ReactNode }) {
  const { isAuthenticated, isLoading, user } = useAuth()

  if (isLoading) {
    return <div className="auth-loading"><Spin size="large" /></div>
  }

  if (isAuthenticated && user) {
    return <Navigate to={getAuthenticatedEntryPath(user)} replace />
  }

  return children ?? <Outlet />
}
