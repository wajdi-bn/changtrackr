import { Navigate, Outlet } from 'react-router-dom'
import { useAuth } from './useAuth'

export function OnboardingRoute() {
  const { user } = useAuth()

  if (user?.onboarding.should_show) {
    return <Navigate to="/welcome" replace />
  }

  return <Outlet />
}
