import { lazy, Suspense } from 'react'
import { Spin } from 'antd'
import { Navigate, Route, Routes } from 'react-router-dom'
import { ProtectedRoute } from '../features/auth/ProtectedRoute'
import { GuestRoute } from '../features/auth/GuestRoute'
import { OnboardingRoute } from '../features/auth/OnboardingRoute'
import { PermissionProtectedRoute } from '../features/auth/PermissionProtectedRoute'
import { RoleProtectedRoute } from '../features/auth/RoleProtectedRoute'
import { useAuth } from '../features/auth/useAuth'
import { AppLayout } from '../layouts/AppLayout'
import { GoogleOAuthCallbackPage } from '../pages/GoogleOAuthCallbackPage'
import { ForgotPasswordPage } from '../pages/ForgotPasswordPage'
import { LoginPage } from '../pages/LoginPage'
import { RegisterPage } from '../pages/RegisterPage'
import { ResetPasswordPage } from '../pages/ResetPasswordPage'
import { VerifyEmailPage } from '../pages/VerifyEmailPage'
import { ActivateInvitationPage } from '../pages/ActivateInvitationPage'
import { WorkspacePage } from '../pages/WorkspacePage'
import { SettingsPage } from '../pages/SettingsPage'
import { ProfilePage } from '../pages/ProfilePage'
import { WelcomePage } from '../pages/WelcomePage'
import { getAuthenticatedEntryPath } from '../features/auth/authNavigation'

const LandingPage = lazy(() => import('../pages/LandingPage').then((module) => ({ default: module.LandingPage })))
const HomePage = lazy(() => import('../pages/HomePage').then((module) => ({ default: module.HomePage })))
const StationsPage = lazy(() => import('../pages/StationsPage').then((module) => ({ default: module.StationsPage })))
const StationDetailPage = lazy(() => import('../pages/StationDetailPage').then((module) => ({ default: module.StationDetailPage })))
const AlertsPage = lazy(() => import('../pages/AlertsPage').then((module) => ({ default: module.AlertsPage })))
const InterventionsPage = lazy(() => import('../pages/InterventionsPage').then((module) => ({ default: module.InterventionsPage })))
const MaintenancePage = lazy(() => import('../pages/MaintenancePage').then((module) => ({ default: module.MaintenancePage })))
const SessionsPage = lazy(() => import('../pages/SessionsPage').then((module) => ({ default: module.SessionsPage })))
const FindStationPage = lazy(() => import('../pages/FindStationPage').then((module) => ({ default: module.FindStationPage })))
const PaymentsPage = lazy(() => import('../pages/PaymentsPage').then((module) => ({ default: module.PaymentsPage })))
const TariffsPage = lazy(() => import('../pages/TariffsPage').then((module) => ({ default: module.TariffsPage })))
const UsersPage = lazy(() => import('../pages/UsersPage').then((module) => ({ default: module.UsersPage })))
const CustomersPage = lazy(() => import('../pages/CustomersPage').then((module) => ({ default: module.CustomersPage })))
const SubscriptionsPage = lazy(() => import('../pages/SubscriptionsPage').then((module) => ({ default: module.SubscriptionsPage })))
const DemoRequestsPage = lazy(() => import('../pages/DemoRequestsPage').then((module) => ({ default: module.DemoRequestsPage })))
const OrganizationsPage = lazy(() => import('../pages/OrganizationsPage').then((module) => ({ default: module.OrganizationsPage })))
const PlatformUsersPage = lazy(() => import('../pages/PlatformUsersPage').then((module) => ({ default: module.PlatformUsersPage })))
const RolesPermissionsPage = lazy(() => import('../pages/RolesPermissionsPage').then((module) => ({ default: module.RolesPermissionsPage })))
const PlatformAuditLogsPage = lazy(() => import('../pages/PlatformAuditLogsPage').then((module) => ({ default: module.PlatformAuditLogsPage })))
const IntegrationsPage = lazy(() => import('../pages/IntegrationsPage').then((module) => ({ default: module.IntegrationsPage })))
const SystemSettingsPage = lazy(() => import('../pages/SystemSettingsPage').then((module) => ({ default: module.SystemSettingsPage })))
const SuperAdminReportsPage = lazy(() => import('../pages/SuperAdminReportsPage').then((module) => ({ default: module.SuperAdminReportsPage })))
const AdministratorReportsPage = lazy(() => import('../pages/AdministratorReportsPage').then((module) => ({ default: module.AdministratorReportsPage })))
const OperatorReportsPage = lazy(() => import('../pages/OperatorReportsPage').then((module) => ({ default: module.OperatorReportsPage })))
const TechnicianReportsPage = lazy(() => import('../pages/TechnicianReportsPage').then((module) => ({ default: module.TechnicianReportsPage })))
const CommercialManagementPage = lazy(() => import('../pages/CommercialManagementPage').then((module) => ({ default: module.CommercialManagementPage })))
const OrganizationBillingPage = lazy(() => import('../pages/OrganizationBillingPage').then((module) => ({ default: module.OrganizationBillingPage })))
const HelpPage = lazy(() => import('../pages/HelpPage').then((module) => ({ default: module.HelpPage })))
const SimulationLabPage = lazy(() => import('../pages/SimulationLabPage').then((module) => ({ default: module.SimulationLabPage })))

function DefaultRedirect() {
  const { user } = useAuth()
  return <Navigate to={user ? getAuthenticatedEntryPath(user) : '/login'} replace />
}

export function AppRouter() {
  return (
    <Suspense fallback={<div className="route-loading"><Spin size="large" /></div>}>
      <Routes>
      <Route element={<GuestRoute />}>
        <Route path="/" element={<LandingPage />} />
        <Route path="/login" element={<LoginPage />} />
        <Route path="/register" element={<RegisterPage />} />
        <Route path="/verify-email" element={<VerifyEmailPage />} />
        <Route path="/forgot-password" element={<ForgotPasswordPage />} />
        <Route path="/reset-password" element={<ResetPasswordPage />} />
        <Route path="/activate-invitation" element={<ActivateInvitationPage />} />
      </Route>
      <Route path="/auth/google/callback" element={<GoogleOAuthCallbackPage />} />

      <Route element={<ProtectedRoute />}>
        <Route path="/welcome" element={<WelcomePage />} />
        <Route element={<OnboardingRoute />}>
          <Route element={<PermissionProtectedRoute permission="ocpp_commands.view" />}>
            <Route path="/simulation-lab" element={<SimulationLabPage />} />
          </Route>
          <Route element={<AppLayout />}>
          <Route path="/app" element={<DefaultRedirect />} />
          <Route path="/overview" element={<HomePage />} />
          <Route element={<RoleProtectedRoute allowedRoles={['super_admin']} />}>
            <Route path="/admin-home" element={<HomePage />} />
            <Route path="/organizations" element={<OrganizationsPage />} />
            <Route path="/commercial" element={<CommercialManagementPage />} />
            <Route path="/demo-requests" element={<DemoRequestsPage />} />
            <Route path="/admin-users" element={<PlatformUsersPage />} />
            <Route path="/roles-permissions" element={<RolesPermissionsPage />} />
            <Route path="/platform-reports" element={<SuperAdminReportsPage />} />
            <Route path="/audit-logs" element={<PlatformAuditLogsPage />} />
            <Route path="/integrations" element={<IntegrationsPage />} />
            <Route path="/system-settings" element={<SystemSettingsPage />} />
          </Route>
          <Route element={<RoleProtectedRoute allowedRoles={['admin']} />}>
            <Route path="/users" element={<Navigate to="/users/employees" replace />} />
            <Route path="/users/employees" element={<UsersPage />} />
            <Route path="/users/customers" element={<CustomersPage />} />
            <Route path="/analytics-reports" element={<AdministratorReportsPage />} />
            <Route path="/organization-billing" element={<OrganizationBillingPage />} />
          </Route>
          <Route element={<PermissionProtectedRoute permission="tariffs.view" />}>
            <Route path="/tariffs" element={<TariffsPage />} />
          </Route>
          <Route element={<PermissionProtectedRoute permission="stations.view" />}>
            <Route path="/stations" element={<StationsPage />} />
            <Route path="/stations/:stationId" element={<StationDetailPage />} />
            <Route path="/map" element={<Navigate to="/stations?view=map" replace />} />
          </Route>
          <Route element={<PermissionProtectedRoute permission="alerts.view" />}>
            <Route path="/alerts" element={<AlertsPage />} />
            <Route path="/assigned-alerts" element={<AlertsPage />} />
          </Route>
          <Route element={<PermissionProtectedRoute permission="interventions.view" />}>
            <Route path="/interventions" element={<InterventionsPage />} />
            <Route path="/my-interventions" element={<InterventionsPage />} />
          </Route>
          <Route element={<PermissionProtectedRoute permission="maintenances.view" />}>
            <Route path="/maintenance" element={<MaintenancePage />} />
          </Route>
          <Route element={<PermissionProtectedRoute permission="sessions.view" />}>
            <Route path="/sessions" element={<SessionsPage />} />
            <Route path="/my-sessions" element={<SessionsPage />} />
          </Route>
          <Route element={<RoleProtectedRoute allowedRoles={['client']} />}>
            <Route path="/find-station" element={<FindStationPage />} />
            <Route path="/charge/scan/:qrToken" element={<FindStationPage />} />
            <Route path="/charge/:stationId/:connectorId" element={<FindStationPage />} />
          </Route>
          <Route element={<RoleProtectedRoute allowedRoles={['client']} />}>
            <Route path="/subscriptions" element={<SubscriptionsPage />} />
          </Route>
          <Route element={<PermissionProtectedRoute permission="payments.view" />}>
            <Route path="/payments" element={<PaymentsPage />} />
          </Route>
          <Route element={<RoleProtectedRoute allowedRoles={['operator']} />}>
            <Route path="/reports" element={<OperatorReportsPage />} />
          </Route>
          <Route element={<RoleProtectedRoute allowedRoles={['technician']} />}>
            <Route path="/maintenance-reports" element={<TechnicianReportsPage />} />
          </Route>
          <Route path="/profile" element={<ProfilePage />} />
          <Route path="/settings" element={<SettingsPage />} />
          <Route path="/help" element={<HelpPage />} />
          <Route path="/subscription-required" element={<WorkspacePage title="Organization access suspended" subtitle="Operational modules are unavailable until your organization administrator renews the ChargeTrackr subscription." />} />
          </Route>
        </Route>
      </Route>

      <Route path="*" element={<Navigate to="/" replace />} />
      </Routes>
    </Suspense>
  )
}
