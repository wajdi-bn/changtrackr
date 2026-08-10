import { useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { App, Tag } from 'antd'
import dayjs from 'dayjs'
import { Activity, Building2, CircleDollarSign, PlugZap, ShieldAlert, Users } from 'lucide-react'
import { Area, AreaChart, Bar, BarChart, CartesianGrid, Cell, Pie, PieChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { MountainBanner } from '../components/MountainBanner'
import { MetricItem, MetricStrip } from '../components/MetricStrip'
import { ReportLoading, ReportPanel, ReportPeriodToolbar } from '../components/reports/ReportingUI'
import { REPORT_COLORS, formatMoney, humanize } from '../components/reports/reportingUtils'
import { exportReportAnalytics, getPlatformReportAnalytics } from '../features/reports/reportingApi'
import type { ExportFormat } from '../components/ExportDropdown'
import type { ReportPeriodKey } from '../types/reporting'
import { downloadBlob } from '../utils/downloadBlob'

export function SuperAdminReportsPage() {
  const [period, setPeriod] = useState<ReportPeriodKey>('30d')
  const { message } = App.useApp()
  const query = useQuery({ queryKey: ['reporting', 'platform', period], queryFn: () => getPlatformReportAnalytics(period) })
  const exportMutation = useMutation({ mutationFn: (format: ExportFormat) => exportReportAnalytics('platform', period, format), onSuccess: (blob, format) => downloadBlob(blob, `platform-report-${dayjs().format('YYYY-MM-DD')}.${format}`), onError: () => void message.error('The platform report could not be exported.') })
  const data = query.data

  return <div className="report-page report-page--platform super-admin-page">
    <MountainBanner color="gold" breadcrumb={['Super Admin', 'Governance', 'Platform reports']} title="Platform intelligence" subtitle="Cross-organization adoption, financial activity, infrastructure exposure and governance signals." />
    <ReportPeriodToolbar period={data?.period} value={period} onChange={setPeriod} onRefresh={() => void query.refetch()} refreshing={query.isFetching} exporting={exportMutation.isPending} onExport={(format) => exportMutation.mutate(format)} />
    {query.isLoading ? <ReportLoading /> : !data ? <ReportPanel title="Reporting unavailable"><p>Platform reporting data could not be loaded.</p></ReportPanel> : <>
      <MetricStrip className="report-metric-strip">
        <MetricItem icon={<Building2 size={18} />} label="Organizations" value={data.kpis.organizations} helper={`${data.kpis.active_organizations} active tenants`} />
        <MetricItem icon={<Users size={18} />} label="Workforce accounts" value={data.kpis.platform_users} helper="Managed employee identities" tone="blue" />
        <MetricItem icon={<PlugZap size={18} />} label="Charging stations" value={data.kpis.managed_stations} helper="Across the platform" tone="purple" />
        <MetricItem icon={<CircleDollarSign size={18} />} label="Settled volume" value={formatMoney(data.kpis.revenue_millimes)} helper={data.period.label} tone="orange" />
      </MetricStrip>
      <div className="platform-report-grid">
        <ReportPanel className="platform-adoption-chart" title="Platform adoption pulse" subtitle="Daily sessions and energy delivered across every organization.">
          <div className="report-chart report-chart--wide"><ResponsiveContainer><AreaChart data={data.trend}><CartesianGrid stroke="var(--app-grid)" vertical={false}/><XAxis dataKey="date" tickFormatter={(value) => dayjs(value).format('DD MMM')} tickLine={false} axisLine={false}/><YAxis yAxisId="energy" tickLine={false} axisLine={false}/><YAxis yAxisId="sessions" orientation="right" tickLine={false} axisLine={false}/><Tooltip labelFormatter={(value) => dayjs(value).format('DD MMM YYYY')}/><Area yAxisId="energy" type="monotone" dataKey="energy_kwh" name="Energy (kWh)" stroke="#8d70df" fill="var(--chart-purple-fill)" strokeWidth={2.4}/><Area yAxisId="sessions" type="monotone" dataKey="sessions" name="Sessions" stroke="#159a61" fill="transparent" strokeWidth={2}/></AreaChart></ResponsiveContainer></div>
        </ReportPanel>
        <ReportPanel className="platform-role-chart" title="Identity distribution" subtitle="Platform employee accounts by permission role.">
          <div className="report-chart"><ResponsiveContainer><BarChart data={data.user_roles} layout="vertical" margin={{ left: 12 }}><CartesianGrid stroke="var(--app-grid)" horizontal={false}/><XAxis type="number" hide/><YAxis type="category" dataKey="label" width={96} tickLine={false} axisLine={false}/><Tooltip/><Bar dataKey="value" name="Users" fill="#159a61" radius={[0, 6, 6, 0]} barSize={18}/></BarChart></ResponsiveContainer></div>
        </ReportPanel>
        <ReportPanel className="platform-ranking" title="Organization benchmark" subtitle="Operational footprint and settled activity by tenant.">
          <div className="report-ranking-table"><div className="report-ranking-table__head"><span>Organization</span><span>Stations</span><span>Users</span><span>Sessions</span><span>Revenue</span></div>{data.organization_ranking.map((organization, index) => <article key={organization.id}><b>{String(index + 1).padStart(2, '0')}</b><span><strong>{organization.name}</strong><small><Tag color={organization.status === 'active' ? 'green' : 'default'}>{humanize(organization.status)}</Tag></small></span><strong>{organization.stations}</strong><strong>{organization.users}</strong><strong>{organization.sessions}</strong><strong>{formatMoney(organization.revenue_millimes)}</strong></article>)}</div>
        </ReportPanel>
        <ReportPanel className="platform-posture" title="Governance posture" subtitle="Signals requiring platform-level attention.">
          <div className="platform-risk-list">
            <article><ShieldAlert size={20}/><span><small>Critical alerts</small><strong>{data.risk.critical_alerts}</strong></span><p>Unresolved across tenants</p></article>
            <article><Activity size={20}/><span><small>Offline stations</small><strong>{data.risk.offline_stations}</strong></span><p>Current infrastructure exposure</p></article>
            <article><Building2 size={20}/><span><small>Inactive organizations</small><strong>{data.risk.inactive_organizations}</strong></span><p>Onboarding or retention review</p></article>
            <article><CircleDollarSign size={20}/><span><small>Failed payments</small><strong>{data.risk.failed_payments}</strong></span><p>During the selected period</p></article>
          </div>
          <div className="platform-status-donut"><ResponsiveContainer><PieChart><Pie data={data.organization_status} dataKey="value" nameKey="label" innerRadius={52} outerRadius={76} paddingAngle={3}>{data.organization_status.map((entry, index) => <Cell key={entry.key} fill={REPORT_COLORS[index % REPORT_COLORS.length]}/>)}</Pie><Tooltip/></PieChart></ResponsiveContainer><div><strong>{data.kpis.organizations}</strong><small>tenants</small></div></div>
        </ReportPanel>
      </div>
    </>}
  </div>
}
