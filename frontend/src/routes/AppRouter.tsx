import { lazy, Suspense } from 'react'
import { Spin } from 'antd'
import { Navigate, Route, Routes } from 'react-router-dom'
import { ProtectedRoute } from '../features/auth/ProtectedRoute'
import { PermissionProtectedRoute } from '../features/auth/PermissionProtectedRoute'
import { RoleProtectedRoute } from '../features/auth/RoleProtectedRoute'
import { getRoleConfig } from '../features/auth/roleConfig'
import { useAuth } from '../features/auth/useAuth'
import { AppLayout } from '../layouts/AppLayout'
import { HomePage } from '../pages/HomePage'
import { GoogleOAuthCallbackPage } from '../pages/GoogleOAuthCallbackPage'
import { ForgotPasswordPage } from '../pages/ForgotPasswordPage'
import { LoginPage } from '../pages/LoginPage'
import { RegisterPage } from '../pages/RegisterPage'
import { ResetPasswordPage } from '../pages/ResetPasswordPage'
import { VerifyEmailPage } from '../pages/VerifyEmailPage'
import { WorkspacePage } from '../pages/WorkspacePage'

const LandingPage = lazy(() => import('../pages/LandingPage').then((module) => ({ default: module.LandingPage })))
const StationsPage = lazy(() => import('../pages/StationsPage').then((module) => ({ default: module.StationsPage })))
const StationDetailPage = lazy(() => import('../pages/StationDetailPage').then((module) => ({ default: module.StationDetailPage })))
const AlertsPage = lazy(() => import('../pages/AlertsPage').then((module) => ({ default: module.AlertsPage })))
const InterventionsPage = lazy(() => import('../pages/InterventionsPage').then((module) => ({ default: module.InterventionsPage })))
const SessionsPage = lazy(() => import('../pages/SessionsPage').then((module) => ({ default: module.SessionsPage })))
const FindStationPage = lazy(() => import('../pages/FindStationPage').then((module) => ({ default: module.FindStationPage })))
const PaymentsPage = lazy(() => import('../pages/PaymentsPage').then((module) => ({ default: module.PaymentsPage })))
const TariffsPage = lazy(() => import('../pages/TariffsPage').then((module) => ({ default: module.TariffsPage })))
const UsersPage = lazy(() => import('../pages/UsersPage').then((module) => ({ default: module.UsersPage })))
const CustomersPage = lazy(() => import('../pages/CustomersPage').then((module) => ({ default: module.CustomersPage })))
const SubscriptionsPage = lazy(() => import('../pages/SubscriptionsPage').then((module) => ({ default: module.SubscriptionsPage })))

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
      <Route path="/register" element={<RegisterPage />} />
      <Route path="/verify-email" element={<VerifyEmailPage />} />
      <Route path="/forgot-password" element={<ForgotPasswordPage />} />
      <Route path="/reset-password" element={<ResetPasswordPage />} />
      <Route path="/auth/google/callback" element={<GoogleOAuthCallbackPage />} />

      <Route element={<ProtectedRoute />}>
        <Route element={<AppLayout />}>
          <Route path="/app" element={<DefaultRedirect />} />
          <Route path="/overview" element={<HomePage />} />
          <Route element={<RoleProtectedRoute allowedRoles={['super_admin']} />}>
            <Route path="/admin-home" element={<HomePage />} />
            <Route path="/organizations" element={<WorkspacePage title="Organizations" subtitle="Global tenant management for the super administrator." />} />
            <Route path="/admin-users" element={<WorkspacePage title="Platform users" subtitle="Global users, administrators and role assignments." />} />
            <Route path="/roles-permissions" element={<WorkspacePage title="Roles & Permissions" subtitle="Permission matrix inspired by admin panel content." />} />
            <Route path="/audit-logs" element={<WorkspacePage title="Audit Logs" subtitle="Security-sensitive actions and traceability." />} />
            <Route path="/integrations" element={<WorkspacePage title="Integrations" subtitle="OAuth, email, payment and OCPP integration status." />} />
            <Route path="/system-settings" element={<WorkspacePage title="System Settings" subtitle="Platform-level settings and environment diagnostics." />} />
          </Route>
          <Route element={<RoleProtectedRoute allowedRoles={['admin']} />}>
            <Route path="/users" element={<Navigate to="/users/employees" replace />} />
            <Route path="/users/employees" element={<UsersPage />} />
            <Route path="/users/customers" element={<CustomersPage />} />
            <Route path="/analytics-reports" element={<WorkspacePage title="Analytics & Reports" subtitle="Organization KPIs, trends and report generation." />} />
          </Route>
          <Route element={<PermissionProtectedRoute permission="tariffs.view" />}>
            <Route path="/tariffs" element={<TariffsPage />} />
          </Route>
          <Route element={<PermissionProtectedRoute permission="stations.view" />}>
            <Route path="/stations" element={<StationsPage />} />
            <Route path="/stations/:stationId" element={<StationDetailPage />} />
            <Route path="/map" element={<WorkspacePage title="Map" subtitle="Geographic station monitoring and operator station creation." />} />
          </Route>
          <Route element={<PermissionProtectedRoute permission="alerts.view" />}>
            <Route path="/alerts" element={<AlertsPage />} />
            <Route path="/assigned-alerts" element={<AlertsPage />} />
          </Route>
          <Route element={<PermissionProtectedRoute permission="interventions.view" />}>
            <Route path="/my-interventions" element={<InterventionsPage />} />
            <Route path="/maintenance-reports" element={<WorkspacePage title="Maintenance Reports" subtitle="Maintenance history and technician submitted reports." />} />
          </Route>
          <Route element={<PermissionProtectedRoute permission="sessions.view" />}>
            <Route path="/sessions" element={<SessionsPage />} />
            <Route path="/my-sessions" element={<SessionsPage />} />
          </Route>
          <Route element={<PermissionProtectedRoute permission="vehicles.manage" />}>
            <Route path="/vehicles" element={<WorkspacePage title="My Vehicles" subtitle="Client vehicle profiles and connector compatibility." />} />
          </Route>
          <Route element={<RoleProtectedRoute allowedRoles={['client']} />}>
            <Route path="/find-station" element={<FindStationPage />} />
          </Route>
          <Route element={<RoleProtectedRoute allowedRoles={['client']} />}>
            <Route path="/subscriptions" element={<SubscriptionsPage />} />
          </Route>
          <Route element={<PermissionProtectedRoute permission="payments.view" />}>
            <Route path="/payments" element={<PaymentsPage />} />
          </Route>
          <Route element={<PermissionProtectedRoute permission="reports.view" />}>
            <Route path="/reports" element={<WorkspacePage title="Reports" subtitle="Exports and operational reporting." />} />
          </Route>
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
