import { lazy, Suspense } from 'react'
import { Spin } from 'antd'
import { Navigate, Route, Routes } from 'react-router-dom'
import { ProtectedRoute } from '../features/auth/ProtectedRoute'
import { getRoleConfig } from '../features/auth/roleConfig'
import { useAuth } from '../features/auth/useAuth'
import { AppLayout } from '../layouts/AppLayout'
import { HomePage } from '../pages/HomePage'
import { LoginPage } from '../pages/LoginPage'
import { WorkspacePage } from '../pages/WorkspacePage'

const LandingPage = lazy(() => import('../pages/LandingPage').then((module) => ({ default: module.LandingPage })))
const StationsPage = lazy(() => import('../pages/StationsPage').then((module) => ({ default: module.StationsPage })))
const StationDetailPage = lazy(() => import('../pages/StationDetailPage').then((module) => ({ default: module.StationDetailPage })))
const AlertsPage = lazy(() => import('../pages/AlertsPage').then((module) => ({ default: module.AlertsPage })))
const InterventionsPage = lazy(() => import('../pages/InterventionsPage').then((module) => ({ default: module.InterventionsPage })))
const SessionsPage = lazy(() => import('../pages/SessionsPage').then((module) => ({ default: module.SessionsPage })))
const FindStationPage = lazy(() => import('../pages/FindStationPage').then((module) => ({ default: module.FindStationPage })))
const PaymentsPage = lazy(() => import('../pages/PaymentsPage').then((module) => ({ default: module.PaymentsPage })))

function DefaultRedirect() {
  const { primaryRole } = useAuth()
  return <Navigate to={getRoleConfig(primaryRole).defaultPath} replace />
}

export function AppRouter() {
  return (
    <Suspense fallback={<div className="route-loading"><Spin size="large" /></div>}>
      <Routes>
      <Route path="/" element={<LandingPage />} />
      <Route path="/login" element={<LoginPage />} />

      <Route element={<ProtectedRoute />}>
        <Route element={<AppLayout />}>
          <Route path="/app" element={<DefaultRedirect />} />
          <Route path="/overview" element={<HomePage />} />
          <Route path="/admin-home" element={<HomePage />} />
          <Route path="/organizations" element={<WorkspacePage title="Organizations" subtitle="Global tenant management for the super administrator." />} />
          <Route path="/admin-users" element={<WorkspacePage title="Platform users" subtitle="Global users, administrators and role assignments." />} />
          <Route path="/roles-permissions" element={<WorkspacePage title="Roles & Permissions" subtitle="Permission matrix inspired by admin panel content." />} />
          <Route path="/audit-logs" element={<WorkspacePage title="Audit Logs" subtitle="Security-sensitive actions and traceability." />} />
          <Route path="/integrations" element={<WorkspacePage title="Integrations" subtitle="OAuth, email, payment and OCPP integration status." />} />
          <Route path="/system-settings" element={<WorkspacePage title="System Settings" subtitle="Platform-level settings and environment diagnostics." />} />
          <Route path="/users" element={<WorkspacePage title="Users" subtitle="Organization users with role-based CRUD." />} />
          <Route path="/tariffs" element={<WorkspacePage title="Tariffs & Pricing" subtitle="Pricing profiles and tariff rules for charging sessions." />} />
          <Route path="/analytics-reports" element={<WorkspacePage title="Analytics & Reports" subtitle="Organization KPIs, trends and report generation." />} />
          <Route path="/stations" element={<StationsPage />} />
          <Route path="/stations/:stationId" element={<StationDetailPage />} />
          <Route path="/map" element={<WorkspacePage title="Map" subtitle="Geographic station monitoring and operator station creation." />} />
          <Route path="/alerts" element={<AlertsPage />} />
          <Route path="/assigned-alerts" element={<AlertsPage />} />
          <Route path="/my-interventions" element={<InterventionsPage />} />
          <Route path="/maintenance-reports" element={<WorkspacePage title="Maintenance Reports" subtitle="Maintenance history and technician submitted reports." />} />
          <Route path="/sessions" element={<SessionsPage />} />
          <Route path="/my-sessions" element={<SessionsPage />} />
          <Route path="/vehicles" element={<WorkspacePage title="My Vehicles" subtitle="Client vehicle profiles and connector compatibility." />} />
          <Route path="/find-station" element={<FindStationPage />} />
          <Route path="/payments" element={<PaymentsPage />} />
          <Route path="/reports" element={<WorkspacePage title="Reports" subtitle="Exports and operational reporting." />} />
          <Route path="/profile" element={<WorkspacePage title="Profile" subtitle="Personal information, organization and account metadata." />} />
          <Route path="/settings" element={<WorkspacePage title="Settings" subtitle="Personal preferences, password and activity history." />} />
          <Route path="/help" element={<WorkspacePage title="Help" subtitle="Internal user guidance and support." />} />
        </Route>
      </Route>

      <Route path="*" element={<Navigate to="/" replace />} />
      </Routes>
    </Suspense>
  )
}
