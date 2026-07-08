import { Navigate, Route, Routes } from 'react-router-dom'
import { ProtectedRoute } from '../features/auth/ProtectedRoute'
import { getRoleConfig } from '../features/auth/roleConfig'
import { useAuth } from '../features/auth/useAuth'
import { AppLayout } from '../layouts/AppLayout'
import { HomePage } from '../pages/HomePage'
import { LoginPage } from '../pages/LoginPage'
import { WorkspacePage } from '../pages/WorkspacePage'

function DefaultRedirect() {
  const { primaryRole } = useAuth()
  return <Navigate to={getRoleConfig(primaryRole).defaultPath} replace />
}

export function AppRouter() {
  return (
    <Routes>
      <Route path="/login" element={<LoginPage />} />

      <Route element={<ProtectedRoute />}>
        <Route element={<AppLayout />}>
          <Route index element={<DefaultRedirect />} />
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
          <Route path="/stations" element={<WorkspacePage title="Stations" subtitle="Station inventory, status and connector overview." />} />
          <Route path="/map" element={<WorkspacePage title="Map" subtitle="Geographic station monitoring and operator station creation." />} />
          <Route path="/alerts" element={<WorkspacePage title="Alerts" subtitle="Availability, heartbeat and connector fault alerts." />} />
          <Route path="/assigned-alerts" element={<WorkspacePage title="My Alerts" subtitle="Technician-only assigned alert queue." />} />
          <Route path="/my-interventions" element={<WorkspacePage title="My Interventions" subtitle="Technician intervention workflow and reports." />} />
          <Route path="/maintenance-reports" element={<WorkspacePage title="Maintenance Reports" subtitle="Maintenance history and technician submitted reports." />} />
          <Route path="/sessions" element={<WorkspacePage title="Sessions" subtitle="Charging sessions and operational supervision." />} />
          <Route path="/my-sessions" element={<WorkspacePage title="My Sessions" subtitle="Client charging history and receipts." />} />
          <Route path="/vehicles" element={<WorkspacePage title="My Vehicles" subtitle="Client vehicle profiles and connector compatibility." />} />
          <Route path="/find-station" element={<WorkspacePage title="Find Station" subtitle="Client station search and availability details." />} />
          <Route path="/payments" element={<WorkspacePage title="Payments & Invoices" subtitle="Simulated MVP payments with future provider adapters." />} />
          <Route path="/reports" element={<WorkspacePage title="Reports" subtitle="Exports and operational reporting." />} />
          <Route path="/profile" element={<WorkspacePage title="Profile" subtitle="Personal information, organization and account metadata." />} />
          <Route path="/settings" element={<WorkspacePage title="Settings" subtitle="Personal preferences, password and activity history." />} />
          <Route path="/help" element={<WorkspacePage title="Help" subtitle="Internal user guidance and support." />} />
        </Route>
      </Route>

      <Route path="*" element={<Navigate to="/" replace />} />
    </Routes>
  )
}
