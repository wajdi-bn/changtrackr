import { useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { App, Progress, Tag } from 'antd'
import dayjs from 'dayjs'
import { AlertTriangle, BatteryCharging, CircleDollarSign, Gauge, Users, Wrench } from 'lucide-react'
import { Area, Bar, CartesianGrid, Cell, ComposedChart, Legend, Pie, PieChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { MountainBanner } from '../components/MountainBanner'
import { MetricItem, MetricStrip } from '../components/MetricStrip'
import { InternalReportMailbox } from '../components/reports/InternalReportMailbox'
import { ReportLoading, ReportPanel, ReportPeriodToolbar } from '../components/reports/ReportingUI'
import { REPORT_COLORS, formatMoney, humanize } from '../components/reports/reportingUtils'
import { exportReportAnalytics, getOrganizationReportAnalytics } from '../features/reports/reportingApi'
import type { ExportFormat } from '../components/ExportDropdown'
import type { ReportPeriodKey } from '../types/reporting'
import { downloadBlob } from '../utils/downloadBlob'

export function AdministratorReportsPage() {
  const [period, setPeriod] = useState<ReportPeriodKey>('30d')
  const { message } = App.useApp()
  const query = useQuery({ queryKey: ['reporting', 'organization', period], queryFn: () => getOrganizationReportAnalytics(period) })
  const exportMutation = useMutation({ mutationFn: (format: ExportFormat) => exportReportAnalytics('organization', period, format), onSuccess: (blob, format) => downloadBlob(blob, `organization-performance-${dayjs().format('YYYY-MM-DD')}.${format}`), onError: () => void message.error('The organization report could not be exported.') })
  const data = query.data

  return <div className="report-page report-page--administrator">
    <MountainBanner color="purple" breadcrumb={['Administrator', 'Business intelligence', 'Reports']} title="Performance studio" subtitle="Connect financial results, customer activity, workforce capacity and charging-network reliability." />
    <ReportPeriodToolbar period={data?.period} value={period} onChange={setPeriod} onRefresh={() => void query.refetch()} refreshing={query.isFetching} exporting={exportMutation.isPending} onExport={(format) => exportMutation.mutate(format)} />
    {query.isLoading ? <ReportLoading /> : !data ? <ReportPanel title="Reporting unavailable"><p>Organization reporting data could not be loaded.</p></ReportPanel> : <>
      <MetricStrip className="report-metric-strip">
        <MetricItem icon={<CircleDollarSign size={18}/>} label="Settled revenue" value={formatMoney(data.business.revenue_millimes)} helper={data.period.label} tone="purple" />
        <MetricItem icon={<BatteryCharging size={18}/>} label="Energy delivered" value={`${data.business.energy_kwh.toLocaleString()} kWh`} helper={`${data.business.sessions} charging sessions`} tone="blue" />
        <MetricItem icon={<Gauge size={18}/>} label="Fleet availability" value={`${data.network.availability_percent}%`} helper={`${data.network.stations} managed stations`} />
        <MetricItem icon={<Users size={18}/>} label="Active customers" value={data.business.customers} helper={`${data.workforce.employees} organization employees`} tone="orange" />
      </MetricStrip>
      <div className="administrator-report-grid">
        <ReportPanel className="administrator-main-chart" title="Revenue and network activity" subtitle="Daily settled revenue, sessions and delivered energy.">
          <div className="report-chart report-chart--wide"><ResponsiveContainer><ComposedChart data={data.trend}><CartesianGrid stroke="var(--app-grid)" vertical={false}/><XAxis dataKey="date" tickFormatter={(value) => dayjs(value).format('DD MMM')} tickLine={false} axisLine={false}/><YAxis yAxisId="left" tickLine={false} axisLine={false}/><YAxis yAxisId="right" orientation="right" tickLine={false} axisLine={false}/><Tooltip labelFormatter={(value) => dayjs(value).format('DD MMM YYYY')} formatter={(value, name) => name === 'Revenue' ? formatMoney(Number(value)) : Number(value).toLocaleString()}/><Legend/><Area yAxisId="right" type="monotone" dataKey="revenue_millimes" name="Revenue" stroke="#8d70df" fill="var(--chart-purple-fill)" strokeWidth={2.4}/><Bar yAxisId="left" dataKey="sessions" name="Sessions" fill="#159a61" radius={[5,5,0,0]} barSize={12}/></ComposedChart></ResponsiveContainer></div>
        </ReportPanel>
        <ReportPanel className="administrator-alert-mix" title="Risk mix" subtitle="Alerts detected in the selected period.">
          <div className="report-donut-layout"><div className="report-chart report-chart--donut"><ResponsiveContainer><PieChart><Pie data={data.alert_distribution} dataKey="value" nameKey="label" innerRadius={58} outerRadius={84} paddingAngle={3}>{data.alert_distribution.map((entry, index) => <Cell key={entry.key} fill={REPORT_COLORS[index % REPORT_COLORS.length]}/>)}</Pie><Tooltip/></PieChart></ResponsiveContainer></div><div className="report-legend">{data.alert_distribution.map((entry, index) => <span key={entry.key}><i style={{ background: REPORT_COLORS[index % REPORT_COLORS.length] }}/><small>{entry.label}</small><strong>{entry.value}</strong></span>)}</div></div>
        </ReportPanel>
        <ReportPanel className="administrator-stations" title="Station portfolio" subtitle="Compare availability, demand and alert pressure.">
          <div className="station-performance-list">{data.station_performance.map((station, index) => <article key={station.id}><b>{String(index + 1).padStart(2, '0')}</b><span><strong>{station.name}</strong><small>{station.city ?? 'Location not specified'} / <Tag color={station.status === 'available' ? 'green' : station.status === 'offline' ? 'red' : 'gold'}>{humanize(station.status)}</Tag></small></span><div><small>Availability</small><Progress percent={station.uptime_percent} showInfo={false} strokeColor={station.uptime_percent >= 95 ? '#159a61' : '#ef8b19'}/><strong>{station.uptime_percent}%</strong></div><span className="station-performance-list__value"><strong>{station.sessions}</strong><small>sessions</small></span><span className="station-performance-list__value"><strong>{station.energy_kwh}</strong><small>kWh</small></span><span className={station.open_alerts ? 'danger' : ''}><strong>{station.open_alerts}</strong><small>alerts</small></span></article>)}</div>
        </ReportPanel>
        <ReportPanel className="administrator-workforce" title="Workforce and SLA" subtitle="Capacity available to act on network conditions.">
          <div className="workforce-balance"><article><Users size={20}/><strong>{data.workforce.operators}</strong><span>Operators</span></article><article><Wrench size={20}/><strong>{data.workforce.technicians}</strong><span>Technicians</span></article><article><AlertTriangle size={20}/><strong>{data.workforce.open_work}</strong><span>Open work</span></article><article className={data.network.sla_breaches ? 'warning' : ''}><Gauge size={20}/><strong>{data.network.sla_breaches}</strong><span>SLA breaches</span></article></div>
        </ReportPanel>
      </div>
      <InternalReportMailbox variant="admin" title="Organization reporting desk" subtitle="Request, receive and distribute decision-ready reports across your organization." />
    </>}
  </div>
}
