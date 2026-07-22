import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { App, Button, Card, Empty, Progress, Select, Skeleton, Tag } from 'antd'
import {
  Activity,
  AlertTriangle,
  BatteryCharging,
  Building2,
  CalendarDays,
  CarFront,
  ChevronRight,
  CircleDollarSign,
  ClipboardList,
  CreditCard,
  Gauge,
  Inbox,
  KeyRound,
  MapPin,
  PlugZap,
  ReceiptText,
  Settings,
  ShieldCheck,
  TimerReset,
  Users,
  WifiOff,
  Wrench,
  Zap,
} from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import type { ReactNode } from 'react'
import {
  Area,
  AreaChart,
  CartesianGrid,
  Cell,
  Line,
  LineChart,
  Pie,
  PieChart,
  RadialBar,
  RadialBarChart,
  ResponsiveContainer,
  Tooltip as ChartTooltip,
  XAxis,
  YAxis,
} from 'recharts'
import { useNavigate } from 'react-router-dom'
import { MountainBanner } from '../components/MountainBanner'
import { MetricItem, MetricStrip } from '../components/MetricStrip'
import { useAuth } from '../features/auth/useAuth'
import { getDashboard } from '../features/dashboard/dashboardApi'
import { copyCoordinates } from '../features/maps/mapUtils'
import { StationMap, StationPopupDetailButton } from '../features/maps/StationMap'
import { getStationMap } from '../features/stations/stationApi'
import type { StationMapMarker } from '../types/station'
import type {
  DashboardActivity,
  DashboardBreakdown,
  DashboardData,
  DashboardKpi,
  DashboardPeriodKey,
  DashboardRanking,
} from '../types/dashboard'
import type { UserRole } from '../types/auth'

const periodOptions = [
  { label: 'Last 7 days', value: '7d' },
  { label: 'Last 30 days', value: '30d' },
  { label: 'Last 90 days', value: '90d' },
]

const chartColors = ['#22c55e', '#7c3aed', '#f59e0b', '#ef4444', '#64748b', '#0ea5e9', '#f97316']

export function HomePage() {
  const { user, primaryRole } = useAuth()
  const [period, setPeriod] = useState<DashboardPeriodKey>('30d')
  const dashboardQuery = useQuery({
    queryKey: ['dashboard', user?.id, primaryRole, period],
    queryFn: () => getDashboard(period),
    enabled: Boolean(user && primaryRole),
    refetchInterval: 30_000,
  })
  const dashboard = dashboardQuery.data
  const needsMap = dashboard ? ['admin', 'operator', 'technician', 'client'].includes(dashboard.role) : false
  const mapQuery = useQuery({
    queryKey: ['stations', 'role-dashboard-map', user?.id, dashboard?.role],
    queryFn: () => getStationMap({}),
    enabled: Boolean(dashboard && needsMap),
  })

  if (dashboardQuery.isLoading) return <DashboardSkeleton />
  if (dashboardQuery.isError || !dashboard) {
    return <div className="dashboard-error"><Empty description="Dashboard data could not be loaded" /><Button onClick={() => void dashboardQuery.refetch()}>Try again</Button></div>
  }

  const mapState: DashboardMapState = {
    stations: mapQuery.data?.data ?? [],
    stationCount: mapQuery.data?.summary.stations ?? 0,
    availableConnectors: mapQuery.data?.summary.available_connectors ?? 0,
    loading: mapQuery.isLoading,
    error: mapQuery.isError,
  }

  if (dashboard.role === 'super_admin') return <SuperAdminDashboard data={dashboard} period={period} onPeriodChange={setPeriod} />
  if (dashboard.role === 'admin') return <AdministratorDashboard data={dashboard} map={mapState} period={period} onPeriodChange={setPeriod} />
  if (dashboard.role === 'technician') return <TechnicianDashboard data={dashboard} map={mapState} period={period} onPeriodChange={setPeriod} />
  if (dashboard.role === 'client') return <ClientDashboard data={dashboard} map={mapState} period={period} onPeriodChange={setPeriod} />
  return <OperatorDashboard data={dashboard} map={mapState} period={period} onPeriodChange={setPeriod} />
}

interface DashboardMapState {
  stations: StationMapMarker[]
  stationCount: number
  availableConnectors: number
  loading: boolean
  error: boolean
}

interface RoleDashboardProps {
  data: DashboardData
  period: DashboardPeriodKey
  onPeriodChange: (period: DashboardPeriodKey) => void
}

function DashboardFrame({ data, period, onPeriodChange, color, breadcrumb, title, count, className, children }: RoleDashboardProps & {
  color: 'green' | 'teal' | 'orange'
  breadcrumb: string
  title: string
  count?: number
  className: string
  children: ReactNode
}) {
  return <div className={`page-stack role-dashboard ${className}`}>
    <div className="role-dashboard-banner">
      <MountainBanner color={color} breadcrumb={[breadcrumb, title]} title={title} count={count} subtitle={data.description} />
      <PeriodPicker data={data} period={period} onChange={onPeriodChange} />
    </div>
    {children}
  </div>
}

function PeriodPicker({ data, period, onChange }: { data: DashboardData; period: DashboardPeriodKey; onChange: (period: DashboardPeriodKey) => void }) {
  return <div className="role-period-picker">
    <CalendarDays size={27} />
    <Select aria-label="Reporting period" value={period} options={periodOptions} onChange={onChange} popupMatchSelectWidth={false} />
    <small>{data.period.start} - {data.period.end}</small>
  </div>
}

function SuperAdminDashboard(props: RoleDashboardProps) {
  const navigate = useNavigate()
  const counts = props.data.widgets.module_counts ?? {}
  const moduleGroups: Array<{ title: string; modules: Array<{ key: string; label: string; helper: string; path: string; icon: LucideIcon }> }> = [
    {
      title: 'Authentication and authorization',
      modules: [
        { key: 'organizations', label: 'Organizations', helper: 'Tenant companies and charging networks', path: '/organizations', icon: Building2 },
        { key: 'demo_requests', label: 'Demo requests', helper: 'Review onboarding and provision administrators', path: '/demo-requests', icon: Inbox },
        { key: 'users', label: 'Platform users', helper: 'Accounts, roles and access state', path: '/admin-users', icon: Users },
        { key: 'permissions', label: 'Roles and permissions', helper: 'Global permission matrix', path: '/roles-permissions', icon: ShieldCheck },
      ],
    },
    {
      title: 'Charging network',
      modules: [
        { key: 'stations', label: 'Stations', helper: 'Global station inventory and OCPP status', path: '/stations', icon: PlugZap },
        { key: 'alerts', label: 'Open alerts', helper: 'Cross-organization incident supervision', path: '/alerts', icon: AlertTriangle },
        { key: 'sessions', label: 'Charging sessions', helper: 'Global activity and energy records', path: '/sessions', icon: ReceiptText },
        { key: 'payments', label: 'Payments', helper: 'Provider transactions and reconciliation', path: '/payments', icon: CreditCard },
      ],
    },
    {
      title: 'System',
      modules: [
        { key: 'integrations', label: 'Integrations', helper: 'OAuth, email, payment and OCPP services', path: '/integrations', icon: Gauge },
        { key: 'audit', label: 'Audit logs', helper: 'Security-sensitive platform activity', path: '/audit-logs', icon: ClipboardList },
        { key: 'settings', label: 'System settings', helper: 'Global security and operational configuration', path: '/system-settings', icon: Settings },
      ],
    },
  ]

  return <DashboardFrame {...props} color="green" breadcrumb="Super Admin" title="Admin Home" className="super-admin-dashboard">
    <KpiStrip kpis={props.data.kpis} />
    <div className="admin-index-groups">
      {moduleGroups.map((group) => <DashboardCard key={group.title} title={group.title} subtitle="Django Admin-style module index in the ChargeTrackr interface">
        <div className="admin-index-grid">
          {group.modules.map((module) => <button key={module.path} type="button" onClick={() => navigate(module.path)}>
            <span className="admin-module-icon"><module.icon size={18} /></span>
            <span><strong>{module.label}</strong><small>{module.helper}</small></span>
            {counts[module.key] !== undefined && <b>{counts[module.key]}</b>}
            <ChevronRight size={15} />
          </button>)}
        </div>
      </DashboardCard>)}
    </div>
    <div className="super-admin-footer-grid">
      <DashboardCard title="Organizations by status" subtitle="Current tenant lifecycle distribution"><CompactBreakdown breakdown={breakdown(props.data, 'organizations')} /></DashboardCard>
      <DashboardCard title="Recent administration" subtitle="Latest organizations and user accounts"><ActivityList activities={props.data.recent_activity.slice(0, 5)} onOpen={navigate} /></DashboardCard>
      {props.data.rankings[0] && <RankingCard ranking={props.data.rankings[0]} onOpen={navigate} />}
    </div>
  </DashboardFrame>
}

function AdministratorDashboard({ data, map, ...props }: RoleDashboardProps & { map: DashboardMapState }) {
  const navigate = useNavigate()
  const organization = data.widgets.organization
  const health = data.widgets.health
  const usersByRole = breakdown(data, 'users_by_role')
  const alertActivities = data.recent_activity.filter((activity) => activity.type === 'alert').slice(0, 5)

  return <DashboardFrame data={data} {...props} color="teal" breadcrumb="Administrator" title="Organization Overview" className="administrator-dashboard">
    <div className="administrator-intro-grid">
      <DashboardCard className="organization-identity-card">
        <img src="/assets/Logo.png" alt="" />
        <div><small>ORGANIZATION</small><h2>{organization?.name ?? 'Organization'}</h2><p>{organization?.contact_email ?? 'No organization contact email configured.'}</p><Tag color={organization?.status === 'active' ? 'green' : 'orange'}>{humanStatus(organization?.status ?? 'unknown')}</Tag></div>
      </DashboardCard>
      <DashboardCard title="Quick actions" subtitle="Common administrator tasks">
        <div className="administrator-quick-actions">
          {[['Add employee', '/users/employees'], ['Create tariff', '/tariffs'], ['Review stations', '/stations'], ['Generate report', '/analytics-reports']].map(([label, path]) => <button key={path} type="button" onClick={() => navigate(path)}>{label}<ChevronRight size={14} /></button>)}
        </div>
      </DashboardCard>
    </div>
    <KpiStrip kpis={data.kpis} />
    <div className="administrator-health-grid">
      <DashboardCard title="Organization Health Score" subtitle="Availability, alert control, resolution and session success">
        <div className="organization-health">
          <Progress type="circle" percent={health?.score ?? 0} size={132} strokeColor="#16a765" railColor="#e8f4ed" format={(value) => <span><strong>{value}</strong><small>/ 100</small></span>} />
          <div>{health?.factors.map((factor) => <ProgressRow key={factor.label} label={factor.label} value={factor.value} />)}</div>
        </div>
      </DashboardCard>
      <DashboardCard title="Users by role" subtitle="Organization account distribution">
        <DonutWithLegend breakdown={usersByRole} />
      </DashboardCard>
    </div>
    <div className="administrator-chart-grid">
      <DashboardCard title="Revenue trend" subtitle="Settled revenue in TND">
        <ChartFrame><AreaChart data={data.trend.points}><ChartGrid /><ChartAxes /><ChartTooltip /><Area type="monotone" dataKey="revenue_tnd" name="Revenue (TND)" stroke="#22c55e" fill="#dcfce7" strokeWidth={2.3} /></AreaChart></ChartFrame>
      </DashboardCard>
      <DashboardCard title="Energy delivered trend" subtitle="Delivered energy in kWh">
        <ChartFrame><AreaChart data={data.trend.points}><ChartGrid /><ChartAxes /><ChartTooltip /><Area type="monotone" dataKey="energy_kwh" name="Energy (kWh)" stroke="#0ea5e9" fill="#e0f2fe" strokeWidth={2.3} /></AreaChart></ChartFrame>
      </DashboardCard>
    </div>
    <div className="administrator-live-grid">
      <DashboardMapPanel map={map} role="admin" title="Organization station map" subtitle="Live availability across your stations" />
      <DashboardCard title="Open operational attention" subtitle="Recent alert events">
        <ActivityList activities={alertActivities} onOpen={navigate} compact />
      </DashboardCard>
    </div>
    <section className="dashboard-role-section"><SectionHeading title="Performance summary" subtitle="Real organization rankings for the selected period" /><div className="administrator-ranking-grid">{data.rankings.map((ranking) => <RankingCard key={ranking.key} ranking={ranking} onOpen={navigate} />)}</div></section>
  </DashboardFrame>
}

function OperatorDashboard({ data, map, ...props }: RoleDashboardProps & { map: DashboardMapState }) {
  const navigate = useNavigate()
  const stations = breakdown(data, 'stations')
  const alertActivities = data.recent_activity.filter((activity) => activity.type === 'alert')

  return <DashboardFrame data={data} {...props} color="green" breadcrumb="Dashboard" title="Overview" count={stations?.total} className="operator-dashboard">
    <KpiStrip kpis={data.kpis} />
    <DashboardMapPanel map={map} role="operator" title="Network map" subtitle="Status-colored live station markers" className="operator-network-map" />
    <div className="operator-chart-grid">
      <DashboardCard title="Availability trend" subtitle="Station availability and charging demand">
        <ChartFrame large><AreaChart data={data.trend.points}><defs><linearGradient id="operatorAvailability" x1="0" x2="0" y1="0" y2="1"><stop offset="5%" stopColor="#22c55e" stopOpacity=".28" /><stop offset="95%" stopColor="#22c55e" stopOpacity="0" /></linearGradient><linearGradient id="operatorSessions" x1="0" x2="0" y1="0" y2="1"><stop offset="5%" stopColor="#7c3aed" stopOpacity=".2" /><stop offset="95%" stopColor="#7c3aed" stopOpacity="0" /></linearGradient></defs><ChartGrid /><XAxis dataKey="label" tickLine={false} axisLine={false} minTickGap={24} /><YAxis yAxisId="availability" domain={[0, 100]} tickLine={false} axisLine={false} unit="%" /><YAxis yAxisId="sessions" orientation="right" allowDecimals={false} tickLine={false} axisLine={false} /><ChartTooltip /><Area yAxisId="availability" type="monotone" dataKey="availability_percent" name="Availability (%)" stroke="#22c55e" fill="url(#operatorAvailability)" strokeWidth={2.3} /><Area yAxisId="sessions" type="monotone" dataKey="sessions" name="Sessions" stroke="#7c3aed" fill="url(#operatorSessions)" strokeWidth={2.1} /></AreaChart></ChartFrame>
      </DashboardCard>
      <DashboardCard title="Stations by status" subtitle="Current network distribution"><DonutWithLegend breakdown={stations} /></DashboardCard>
    </div>
    <div className="operator-live-grid">
      <DashboardCard title="Recent events" subtitle="Latest station telemetry and operations"><ActivityList activities={data.recent_activity.slice(0, 6)} onOpen={navigate} /></DashboardCard>
      <DashboardCard title="Live station status panel" subtitle="Heartbeat and connector availability"><LiveStationsList stations={map.stations.slice(0, 5)} onOpen={(station) => navigate(`/stations/${station.id}`)} /></DashboardCard>
      <DashboardCard title="Active alerts panel" subtitle={`${alertActivities.length} recent alerts require attention`}><ActivityList activities={alertActivities.slice(0, 5)} onOpen={navigate} compact /></DashboardCard>
    </div>
  </DashboardFrame>
}

function TechnicianDashboard({ data, map, ...props }: RoleDashboardProps & { map: DashboardMapState }) {
  const navigate = useNavigate()
  const tasks = data.widgets.tasks ?? []
  const criticalAlerts = data.widgets.critical_alerts ?? []
  const severity = breakdown(data, 'severity')
  const workStatus = breakdown(data, 'work')
  const workload = breakdown(data, 'priority')

  return <DashboardFrame data={data} {...props} color="orange" breadcrumb="Technician" title="Technician Overview" className="technician-dashboard">
    <KpiStrip kpis={data.kpis} />
    <div className="technician-focus-grid">
      <DashboardCard title="Today's tasks" subtitle="Personal schedule and assigned work"><TaskList tasks={tasks} onOpen={navigate} /></DashboardCard>
      <DashboardCard title="Critical alerts assigned to me" subtitle="Highest priority field work"><CriticalAlertList alerts={criticalAlerts} onOpen={navigate} /></DashboardCard>
      <DashboardMapPanel map={map} role="technician" title="Assigned station map" subtitle="Consultation only - live station status" />
    </div>
    <div className="technician-chart-grid">
      <DashboardCard title="Resolved over time" subtitle="Closed interventions by day"><ChartFrame><AreaChart data={data.trend.points}><defs><linearGradient id="resolvedFill" x1="0" x2="0" y1="0" y2="1"><stop offset="5%" stopColor="#22c55e" stopOpacity=".28" /><stop offset="95%" stopColor="#22c55e" stopOpacity="0" /></linearGradient></defs><ChartGrid /><ChartAxes /><ChartTooltip /><Area type="monotone" dataKey="completed" name="Resolved" stroke="#22c55e" fill="url(#resolvedFill)" strokeWidth={2.2} /></AreaChart></ChartFrame></DashboardCard>
      <DashboardCard title="Resolution time trend" subtitle="Average hours per intervention"><ChartFrame><LineChart data={data.trend.points}><ChartGrid /><ChartAxes /><ChartTooltip /><Line type="monotone" dataKey="resolution_hours" name="Hours" stroke="#f97316" strokeWidth={2.7} dot={{ r: 3 }} /></LineChart></ChartFrame></DashboardCard>
      <DashboardCard title="Alerts by severity" subtitle="Assigned alert mix"><DonutWithLegend breakdown={severity} compact /></DashboardCard>
      <DashboardCard title="Intervention status" subtitle="Current workload distribution"><RadialBreakdown breakdown={workStatus} /></DashboardCard>
    </div>
    <div className="technician-summary-grid">
      <DashboardCard title="Workload" subtitle="Assigned effort by priority"><CompactBreakdown breakdown={workload} /></DashboardCard>
      <DashboardCard title="Recent station faults" subtitle="Faults visible to the assigned technician"><FaultCards faults={data.widgets.recent_faults ?? []} onOpen={navigate} /></DashboardCard>
      <DashboardCard title="Personal performance" subtitle="Verified field-work indicators"><PerformanceList items={data.widgets.performance ?? []} /></DashboardCard>
    </div>
  </DashboardFrame>
}

function ClientDashboard({ data, map, ...props }: RoleDashboardProps & { map: DashboardMapState }) {
  const navigate = useNavigate()
  const session = data.widgets.active_session
  const identifier = data.widgets.identifier
  const subscription = data.widgets.subscription
  const paymentStatus = breakdown(data, 'payments')
  const availableStations = map.stations.filter((station) => station.available_connectors_count > 0).slice(0, 4)

  return <DashboardFrame data={data} {...props} color="green" breadcrumb="Client" title="Client Overview" className="client-dashboard">
    <KpiStrip kpis={data.kpis} />
    <div className="client-primary-grid">
      <DashboardCard title="Current charging session" subtitle={session?.reference ?? 'No active session'}><CurrentSessionWidget session={session} onOpen={navigate} /></DashboardCard>
      <DashboardCard title="Vehicle and identifier" subtitle="Default vehicle and charging access">
        <div className="client-access-widget">
          <div className="client-vehicle-empty"><CarFront size={25} /><span><strong>No vehicle profile yet</strong><small>The vehicle module will store connector compatibility.</small></span></div>
          <div className="client-identifier"><KeyRound size={18} /><span><small>OCPP / RFID IDENTIFIER</small><strong>{identifier?.masked_token ?? 'No active identifier'}</strong><b>{identifier?.label ?? identifier?.status ?? 'Not configured'}</b></span></div>
          <div className="client-subscription"><ShieldCheck size={18} /><span><small>CURRENT PLAN</small><strong>{subscription?.plan ?? 'No active plan'}</strong><b>{subscription ? `${subscription.organization} - ${subscription.discount_basis_points / 100}% discount` : 'Choose a plan from subscriptions'}</b></span></div>
        </div>
      </DashboardCard>
      <DashboardMapPanel map={map} role="client" title="Nearby station map" subtitle="Public station availability" />
    </div>
    <div className="client-discovery-grid">
      <DashboardCard title="Nearby available stations" subtitle="Driver-ready chargers"><AvailableStationGrid stations={availableStations} onOpen={(station) => navigate(`/find-station?station=${station.id}`)} /></DashboardCard>
      {data.rankings[0] ? <RankingCard ranking={data.rankings[0]} onOpen={navigate} title="Most used stations" /> : <DashboardCard title="Most used stations" subtitle="Your personal station history"><Empty image={Empty.PRESENTED_IMAGE_SIMPLE} /></DashboardCard>}
      <DashboardCard title="Payment status" subtitle="Personal invoices only"><DonutWithLegend breakdown={paymentStatus} compact /></DashboardCard>
    </div>
    <DashboardCard title="Recent sessions" subtitle="Personal charging history"><RecentSessionsTable sessions={data.widgets.recent_sessions ?? []} onOpen={navigate} /></DashboardCard>
  </DashboardFrame>
}

function DashboardCard({ title, subtitle, className = '', children }: { title?: string; subtitle?: string; className?: string; children: ReactNode }) {
  return <Card className={`role-dashboard-card ${className}`} title={title ? <PanelTitle title={title} subtitle={subtitle} /> : undefined}>{children}</Card>
}

function PanelTitle({ title, subtitle }: { title: string; subtitle?: string }) {
  return <span className="role-panel-title"><strong>{title}</strong>{subtitle && <small>{subtitle}</small>}</span>
}

function KpiStrip({ kpis }: { kpis: DashboardKpi[] }) {
  return <MetricStrip className="role-kpi-strip">{kpis.map((kpi) => <MetricItem
    key={kpi.key}
    icon={kpiIcon(kpi.key)}
    label={kpi.label}
    value={formatKpi(kpi)}
    helper={kpi.context}
    tone={kpiTone(kpi.key)}
    badge={kpi.change_percent !== null ? <Tag color={kpi.change_percent >= 0 ? 'green' : 'red'}>{kpi.change_percent >= 0 ? '+' : ''}{kpi.change_percent}%</Tag> : undefined}
  />)}</MetricStrip>
}

function ChartFrame({ children, large = false }: { children: ReactNode; large?: boolean }) {
  return <div className={`role-chart-frame${large ? ' is-large' : ''}`}><ResponsiveContainer width="100%" height="100%">{children}</ResponsiveContainer></div>
}

function ChartGrid() {
  return <CartesianGrid strokeDasharray="3 3" stroke="#e5e7eb" vertical={false} />
}

function ChartAxes() {
  return <><XAxis dataKey="label" tickLine={false} axisLine={false} minTickGap={22} /><YAxis tickLine={false} axisLine={false} allowDecimals={false} /></>
}

function DonutWithLegend({ breakdown: item, compact = false }: { breakdown: DashboardBreakdown | null; compact?: boolean }) {
  if (!item || item.items.length === 0) return <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="No data available" />
  return <div className={`role-donut-layout${compact ? ' is-compact' : ''}`}>
    <div className="role-donut-chart"><ResponsiveContainer width="100%" height="100%"><PieChart><Pie data={item.items} dataKey="count" nameKey="label" innerRadius="57%" outerRadius="79%" paddingAngle={3} stroke="#fff" strokeWidth={3}>{item.items.map((entry, index) => <Cell key={entry.key} fill={chartColors[index % chartColors.length]} />)}</Pie><ChartTooltip /></PieChart></ResponsiveContainer><span><strong>{item.total}</strong><small>Total</small></span></div>
    <Legend breakdown={item} />
  </div>
}

function Legend({ breakdown: item }: { breakdown: DashboardBreakdown }) {
  return <div className="role-chart-legend">{item.items.map((entry, index) => <div key={entry.key}><span><i style={{ background: chartColors[index % chartColors.length] }} />{entry.label}</span><strong>{entry.count}<small>{entry.percentage}%</small></strong></div>)}</div>
}

function RadialBreakdown({ breakdown: item }: { breakdown: DashboardBreakdown | null }) {
  if (!item || item.items.length === 0) return <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="No assigned work" />
  const data = item.items.map((entry, index) => ({ ...entry, fill: chartColors[index % chartColors.length] }))
  return <><ChartFrame><RadialBarChart innerRadius="24%" outerRadius="92%" data={data} startAngle={90} endAngle={-270}><RadialBar dataKey="count" background cornerRadius={8} /><ChartTooltip /></RadialBarChart></ChartFrame><Legend breakdown={item} /></>
}

function CompactBreakdown({ breakdown: item }: { breakdown: DashboardBreakdown | null }) {
  if (!item || item.items.length === 0) return <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="No data available" />
  return <div className="role-progress-list">{item.items.map((entry, index) => <ProgressRow key={entry.key} label={`${entry.label} (${entry.count})`} value={entry.percentage} color={chartColors[index % chartColors.length]} />)}</div>
}

function ProgressRow({ label, value, color = '#22c55e' }: { label: string; value: number; color?: string }) {
  return <div><span><strong>{label}</strong><small>{value}%</small></span><Progress percent={value} showInfo={false} strokeColor={color} railColor="#edf2ef" /></div>
}

function DashboardMapPanel({ map, role, title, subtitle, className = '' }: { map: DashboardMapState; role: UserRole; title: string; subtitle: string; className?: string }) {
  const navigate = useNavigate()
  const { message } = App.useApp()
  async function handleCopy(station: StationMapMarker) {
    try {
      await copyCoordinates(station.latitude, station.longitude)
      void message.success('Coordinates copied.')
    } catch {
      void message.error('The coordinates could not be copied.')
    }
  }
  const target = role === 'client' ? '/find-station' : '/stations?view=map'
  return <DashboardCard className={`role-map-card ${className}`} title={title} subtitle={subtitle}>
    <div className="role-map-card-action"><Button type="link" size="small" onClick={() => navigate(target)}>Open map</Button></div>
    {map.loading ? <div className="role-map-loading" /> : map.error ? <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="Map unavailable" /> : <StationMap className="role-dashboard-map" stations={map.stations} onCopyCoordinates={(station) => void handleCopy(station)} popupExtra={(station) => <StationPopupDetailButton onClick={() => navigate(role === 'client' ? `/find-station?station=${station.id}` : `/stations/${station.id}`)} />} />}
    <div className="role-map-footer"><span><strong>{map.stationCount}</strong> stations</span><span><strong>{map.availableConnectors}</strong> connectors ready</span></div>
  </DashboardCard>
}

function ActivityList({ activities, onOpen, compact = false }: { activities: DashboardActivity[]; onOpen: (url: string) => void; compact?: boolean }) {
  if (activities.length === 0) return <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="No recent activity" />
  return <div className={`role-activity-list${compact ? ' is-compact' : ''}`}>{activities.map((activity) => <button key={activity.id} type="button" onClick={() => onOpen(activity.action_url)}><span className={`role-activity-icon ${activity.type}`}>{activityIcon(activity.type)}</span><span><strong>{activity.title}</strong><small>{activity.description}</small></span><span><StatusTag status={activity.status} /><small>{activity.occurred_relative}</small></span></button>)}</div>
}

function LiveStationsList({ stations, onOpen }: { stations: StationMapMarker[]; onOpen: (station: StationMapMarker) => void }) {
  if (stations.length === 0) return <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="No station in scope" />
  return <div className="live-station-list">{stations.map((station) => <button key={station.id} type="button" onClick={() => onOpen(station)}><span className={`station-live-icon ${station.status}`}><PlugZap size={15} /></span><span><strong>{station.name}</strong><small>{station.city} - {station.available_connectors_count}/{station.connectors_count} connectors</small></span><StatusTag status={station.status} /></button>)}</div>
}

function RankingCard({ ranking, onOpen, title }: { ranking: DashboardRanking; onOpen: (url: string) => void; title?: string }) {
  return <DashboardCard title={title ?? ranking.title} subtitle={ranking.description}><ol className="role-ranking-list">{ranking.items.length === 0 ? <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} /> : ranking.items.slice(0, 6).map((item, index) => <li key={item.id}><button type="button" onClick={() => onOpen(item.action_url)}><span>{index + 1}</span><span><strong>{item.label}</strong><small>{item.secondary}</small></span><b>{formatNumber(item.value)}<small>{item.unit}</small></b></button></li>)}</ol></DashboardCard>
}

function TaskList({ tasks, onOpen }: { tasks: NonNullable<DashboardData['widgets']['tasks']>; onOpen: (url: string) => void }) {
  if (tasks.length === 0) return <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="No assigned task" />
  return <div className="technician-task-list">{tasks.map((task) => <button key={task.id} type="button" onClick={() => onOpen(task.action_url)}><time>{task.scheduled_label}</time><span><strong>{task.label}</strong><small>{task.station} - {task.reference}</small></span><StatusTag status={task.status} /></button>)}</div>
}

function CriticalAlertList({ alerts, onOpen }: { alerts: NonNullable<DashboardData['widgets']['critical_alerts']>; onOpen: (url: string) => void }) {
  if (alerts.length === 0) return <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="No critical alert assigned" />
  return <div className="technician-critical-list">{alerts.map((alert) => <button key={alert.id} type="button" onClick={() => onOpen(alert.action_url)}><span><strong>{alert.title}</strong><small>{alert.station}{alert.connector ? ` - ${alert.connector}` : ''}</small></span><StatusTag status={alert.status} /><b>Due {alert.due_label ?? 'not set'}</b></button>)}</div>
}

function FaultCards({ faults, onOpen }: { faults: NonNullable<DashboardData['widgets']['recent_faults']>; onOpen: (url: string) => void }) {
  if (faults.length === 0) return <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="No recent fault" />
  return <div className="technician-fault-grid">{faults.map((fault) => <button key={fault.id} type="button" onClick={() => onOpen(fault.action_url)}><StatusTag status={fault.severity} /><strong>{fault.station}</strong><span>{fault.issue}</span><small>{fault.occurred_relative}</small></button>)}</div>
}

function PerformanceList({ items }: { items: NonNullable<DashboardData['widgets']['performance']> }) {
  return <div className="technician-performance-list">{items.map((item) => <div key={item.label}><small>{item.label}</small><strong>{item.value === null ? 'N/A' : `${formatNumber(item.value)}${item.unit ? ` ${item.unit}` : ''}`}</strong><span>{item.helper}</span></div>)}</div>
}

function CurrentSessionWidget({ session, onOpen }: { session: DashboardData['widgets']['active_session']; onOpen: (url: string) => void }) {
  if (!session) return <div className="client-no-session"><BatteryCharging size={28} /><strong>No charging session in progress</strong><span>Choose an available station to start charging.</span><Button className="client-find-station-button" type="primary" onClick={() => onOpen('/find-station')}>Find a station</Button></div>
  return <div className="client-current-session"><div><span className="session-pulse"><Zap size={18} /></span><span><StatusTag status={session.status} /><h3>{session.station}</h3><p>Connector {session.connector} - {session.reference}</p></span></div><div className="client-session-metrics"><span><small>Energy</small><strong>{session.energy_kwh} kWh</strong></span><span><small>Power</small><strong>{session.current_power_kw ?? 0} kW</strong></span><span><small>Estimated total</small><strong>{formatMoney(session.total_millimes)}</strong></span></div>{session.state_of_charge_percent !== null && <Progress percent={session.state_of_charge_percent} strokeColor="#22c55e" />}<Button type="primary" onClick={() => onOpen(session.action_url)}>Open session</Button></div>
}

function AvailableStationGrid({ stations, onOpen }: { stations: StationMapMarker[]; onOpen: (station: StationMapMarker) => void }) {
  if (stations.length === 0) return <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="No available station" />
  return <div className="client-station-grid">{stations.map((station) => <button key={station.id} type="button" onClick={() => onOpen(station)}><span><PlugZap size={17} /></span><div><strong>{station.name}</strong><small><MapPin size={12} />{station.location_name}, {station.city}</small><p>{station.available_connectors_count}/{station.connectors_count} connectors - {station.max_power_kw} kW</p></div><ChevronRight size={15} /></button>)}</div>
}

function RecentSessionsTable({ sessions, onOpen }: { sessions: NonNullable<DashboardData['widgets']['recent_sessions']>; onOpen: (url: string) => void }) {
  if (sessions.length === 0) return <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="No charging session yet" />
  return <div className="client-session-table-wrap"><table className="client-session-table"><thead><tr><th>Session</th><th>Station</th><th>Date</th><th>Energy</th><th>Amount</th><th>Session</th><th>Payment</th></tr></thead><tbody>{sessions.map((session) => <tr key={session.id} onClick={() => onOpen(session.action_url)}><td><strong>{session.reference}</strong></td><td>{session.station}</td><td>{new Date(session.started_at).toLocaleDateString()}</td><td>{session.energy_kwh} kWh</td><td>{formatMoney(session.amount_millimes)}</td><td><StatusTag status={session.status} /></td><td><StatusTag status={session.payment_status} /></td></tr>)}</tbody></table></div>
}

function StatusTag({ status }: { status: string }) {
  const color = statusColor(status)
  return <Tag color={color}>{humanStatus(status)}</Tag>
}

function SectionHeading({ title, subtitle }: { title: string; subtitle: string }) {
  return <div className="dashboard-role-heading"><h2>{title}</h2><p>{subtitle}</p></div>
}

function DashboardSkeleton() {
  return <div className="page-stack role-dashboard"><Skeleton.Node active className="dashboard-hero-skeleton" /><MetricStrip className="role-kpi-strip metric-strip--loading">{Array.from({ length: 6 }, (_, index) => <article className="metric-strip__item" key={index}><Skeleton active paragraph={{ rows: 2 }} title={false} /></article>)}</MetricStrip><Card><Skeleton active paragraph={{ rows: 10 }} /></Card></div>
}

function breakdown(data: DashboardData, key: string): DashboardBreakdown | null {
  return data.breakdowns.find((item) => item.key === key) ?? null
}

function kpiIcon(key: string): ReactNode {
  if (key.includes('organization')) return <Building2 size={18} />
  if (key.includes('user') || key.includes('employee') || key.includes('customer')) return <Users size={18} />
  if (key.includes('station') || key.includes('availability')) return <PlugZap size={18} />
  if (key.includes('revenue') || key.includes('spend')) return <CircleDollarSign size={18} />
  if (key.includes('critical') || key.includes('alert') || key.includes('overdue')) return <AlertTriangle size={18} />
  if (key.includes('work') || key.includes('resolved')) return <Wrench size={18} />
  if (key.includes('energy')) return <Zap size={18} />
  if (key.includes('session')) return <BatteryCharging size={18} />
  if (key.includes('membership')) return <ShieldCheck size={18} />
  if (key.includes('identifier')) return <KeyRound size={18} />
  if (key.includes('unavailable')) return <WifiOff size={18} />
  if (key.includes('scheduled')) return <CalendarDays size={18} />
  return <Activity size={18} />
}

function kpiTone(key: string): 'green' | 'blue' | 'purple' | 'orange' | 'amber' | 'red' | 'gray' {
  if (key.includes('critical') || key.includes('alert') || key.includes('overdue')) return 'red'
  if (key.includes('revenue') || key.includes('spend') || key.includes('session')) return 'purple'
  if (key.includes('unavailable')) return 'gray'
  if (key.includes('energy')) return 'blue'
  if (key.includes('work') || key.includes('scheduled')) return 'amber'
  return 'green'
}

function activityIcon(type: string): ReactNode {
  if (type === 'alert') return <AlertTriangle size={14} />
  if (type === 'intervention') return <Wrench size={14} />
  if (type === 'payment') return <CreditCard size={14} />
  if (type === 'session') return <BatteryCharging size={14} />
  if (type === 'organization') return <Building2 size={14} />
  if (type === 'user') return <Users size={14} />
  return <TimerReset size={14} />
}

function statusColor(status: string): string {
  if (['available', 'active', 'paid', 'completed', 'resolved', 'accepted', 'success'].includes(status)) return 'green'
  if (['critical', 'faulted', 'failed', 'overdue', 'rejected'].includes(status)) return 'red'
  if (['warning', 'maintenance', 'pending', 'waiting-parts', 'under_review'].includes(status)) return 'orange'
  if (['charging', 'assigned', 'in-progress', 'submitted'].includes(status)) return 'purple'
  if (['offline', 'cancelled', 'inactive'].includes(status)) return 'default'
  return 'blue'
}

function formatKpi(kpi: DashboardKpi): string {
  if (kpi.format === 'currency') return new Intl.NumberFormat('en-TN', { style: 'currency', currency: 'TND', minimumFractionDigits: 0, maximumFractionDigits: 3 }).format(kpi.value)
  if (kpi.format === 'percentage') return `${formatNumber(kpi.value)}%`
  if (kpi.format === 'energy') return `${formatNumber(kpi.value)} kWh`
  if (kpi.format === 'duration') return `${formatNumber(kpi.value)} h`
  return formatNumber(kpi.value)
}

function formatMoney(millimes: number): string {
  return new Intl.NumberFormat('en-TN', { style: 'currency', currency: 'TND', minimumFractionDigits: 3 }).format(millimes / 1000)
}

function formatNumber(value: number): string {
  return new Intl.NumberFormat('en-TN', { maximumFractionDigits: 3 }).format(value)
}

function humanStatus(status: string): string {
  return status.replaceAll('-', ' ').replaceAll('_', ' ')
}
