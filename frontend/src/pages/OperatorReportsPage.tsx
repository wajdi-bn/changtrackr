import { useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { App, Tag } from 'antd'
import dayjs from 'dayjs'
import { Activity, AlertTriangle, BatteryCharging, CircleDot, Radio, Wrench } from 'lucide-react'
import { Area, Bar, CartesianGrid, Cell, ComposedChart, Pie, PieChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { MountainBanner } from '../components/MountainBanner'
import { InternalReportMailbox } from '../components/reports/InternalReportMailbox'
import { ReportLoading, ReportPanel, ReportPeriodToolbar } from '../components/reports/ReportingUI'
import { REPORT_COLORS, humanize } from '../components/reports/reportingUtils'
import { exportReportAnalytics, getOperationsReportAnalytics } from '../features/reports/reportingApi'
import type { ExportFormat } from '../components/ExportDropdown'
import type { ReportPeriodKey } from '../types/reporting'
import { downloadBlob } from '../utils/downloadBlob'

export function OperatorReportsPage() {
  const [period, setPeriod] = useState<ReportPeriodKey>('30d')
  const { message } = App.useApp()
  const query = useQuery({ queryKey: ['reporting', 'operations', period], queryFn: () => getOperationsReportAnalytics(period) })
  const exportMutation = useMutation({ mutationFn: (format: ExportFormat) => exportReportAnalytics('operations', period, format), onSuccess: (blob, format) => downloadBlob(blob, `network-operations-${dayjs().format('YYYY-MM-DD')}.${format}`), onError: () => void message.error('The operations report could not be exported.') })
  const data = query.data

  return <div className="report-page report-page--operator">
    <MountainBanner color="teal" breadcrumb={['Operator', 'Network desk', 'Reports']} title="Operations control room" subtitle="Live network posture, incident pressure, shift handover and stations requiring immediate attention." />
    <ReportPeriodToolbar period={data?.period} value={period} onChange={setPeriod} onRefresh={() => void query.refetch()} refreshing={query.isFetching} exporting={exportMutation.isPending} onExport={(format) => exportMutation.mutate(format)} />
    {query.isLoading ? <ReportLoading /> : !data ? <ReportPanel title="Reporting unavailable"><p>Operations reporting data could not be loaded.</p></ReportPanel> : <>
      <section className="operator-live-strip">
        <header><span><Radio size={18}/></span><div><strong>Network now</strong><small>Derived from live OCPP state</small></div><i>LIVE</i></header>
        <article className="available"><CircleDot size={16}/><span><small>Available</small><strong>{data.live.available}</strong></span></article>
        <article className="charging"><BatteryCharging size={16}/><span><small>Charging</small><strong>{data.live.charging}</strong></span></article>
        <article className="maintenance"><Wrench size={16}/><span><small>Maintenance</small><strong>{data.live.maintenance}</strong></span></article>
        <article className="offline"><AlertTriangle size={16}/><span><small>Offline</small><strong>{data.live.offline}</strong></span></article>
        <article><Activity size={16}/><span><small>Active sessions</small><strong>{data.live.active_sessions}</strong></span></article>
      </section>
      <div className="operator-report-grid">
        <ReportPanel className="operator-activity-chart" title="Network activity and incidents" subtitle="Sessions completed against alerts detected each day.">
          <div className="report-chart report-chart--wide"><ResponsiveContainer><ComposedChart data={data.trend}><CartesianGrid stroke="var(--app-grid)" vertical={false}/><XAxis dataKey="date" tickFormatter={(value) => dayjs(value).format('DD MMM')} tickLine={false} axisLine={false}/><YAxis tickLine={false} axisLine={false}/><Tooltip labelFormatter={(value) => dayjs(value).format('DD MMM YYYY')}/><Area type="monotone" dataKey="sessions" name="Sessions" stroke="#159a61" fill="var(--chart-green-fill)" strokeWidth={2.3}/><Bar dataKey="alerts" name="Alerts" fill="#ef8b19" radius={[5,5,0,0]} barSize={11}/></ComposedChart></ResponsiveContainer></div>
        </ReportPanel>
        <ReportPanel className="operator-status-chart" title="Station state mix" subtitle="Current computed availability state.">
          <div className="report-donut-layout"><div className="report-chart report-chart--donut"><ResponsiveContainer><PieChart><Pie data={data.station_status} dataKey="value" nameKey="label" innerRadius={54} outerRadius={78} paddingAngle={3}>{data.station_status.map((entry, index) => <Cell key={entry.key} fill={REPORT_COLORS[index % REPORT_COLORS.length]}/>)}</Pie><Tooltip/></PieChart></ResponsiveContainer></div><div className="report-legend">{data.station_status.map((entry, index) => <span key={entry.key}><i style={{ background: REPORT_COLORS[index % REPORT_COLORS.length] }}/><small>{entry.label}</small><strong>{entry.value}</strong></span>)}</div></div>
        </ReportPanel>
        <ReportPanel className="operator-watchlist" title="Station watchlist" subtitle="Prioritized by alert load and availability risk." extra={<Tag color={data.live.unresolved_alerts ? 'red' : 'green'}>{data.live.unresolved_alerts} unresolved alerts</Tag>}>
          <div className="operator-watchlist-table"><div className="operator-watchlist-table__head"><span>Station</span><span>Status</span><span>Availability</span><span>Utilization</span><span>Alerts</span><span>Last signal</span></div>{data.station_watchlist.map((station) => <article key={station.id}><span><strong>{station.name}</strong><small>{station.city ?? 'Unknown city'}</small></span><Tag color={station.status === 'available' ? 'green' : station.status === 'offline' ? 'red' : 'gold'}>{humanize(station.status)}</Tag><strong>{station.uptime_percent}%</strong><strong>{station.utilization_percent}%</strong><b className={station.open_alerts ? 'danger' : ''}>{station.open_alerts}</b><time>{station.last_heartbeat_at ? dayjs(station.last_heartbeat_at).format('DD MMM HH:mm') : 'Never'}</time></article>)}</div>
        </ReportPanel>
        <ReportPanel className="operator-handover" title="Shift handover brief" subtitle="Items the next operator should know before taking control.">
          <div className="handover-grid"><article><span>01</span><div><small>Interventions in progress</small><strong>{data.handover.in_progress_interventions}</strong><p>Monitor ownership and blocked work.</p></div></article><article><span>02</span><div><small>Maintenance due in 7 days</small><strong>{data.handover.maintenance_due}</strong><p>Coordinate access and station windows.</p></div></article><article><span>03</span><div><small>Unread internal reports</small><strong>{data.handover.unread_reports}</strong><p>Review before closing the shift.</p></div></article><article><span>04</span><div><small>Draft handovers</small><strong>{data.handover.draft_reports}</strong><p>Complete or send pending notes.</p></div></article></div>
        </ReportPanel>
      </div>
      <InternalReportMailbox variant="operator" title="Shift reports and handovers" subtitle="Exchange operational findings with administrators, operators and field technicians." />
    </>}
  </div>
}
