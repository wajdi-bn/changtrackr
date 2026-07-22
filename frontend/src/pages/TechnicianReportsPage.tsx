import { useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { App, Progress, Tag } from 'antd'
import dayjs from 'dayjs'
import { CheckCircle2, Clock3, FileCheck2, ListTodo, TimerReset, TriangleAlert } from 'lucide-react'
import { Bar, BarChart, CartesianGrid, Cell, Pie, PieChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { MountainBanner } from '../components/MountainBanner'
import { InternalReportMailbox } from '../components/reports/InternalReportMailbox'
import { ReportLoading, ReportPanel, ReportPeriodToolbar } from '../components/reports/ReportingUI'
import { REPORT_COLORS, humanize } from '../components/reports/reportingUtils'
import { exportReportAnalytics, getFieldReportAnalytics } from '../features/reports/reportingApi'
import type { ExportFormat } from '../components/ExportDropdown'
import type { ReportPeriodKey } from '../types/reporting'
import { downloadBlob } from '../utils/downloadBlob'

export function TechnicianReportsPage() {
  const [period, setPeriod] = useState<ReportPeriodKey>('30d')
  const { message } = App.useApp()
  const query = useQuery({ queryKey: ['reporting', 'field', period], queryFn: () => getFieldReportAnalytics(period) })
  const exportMutation = useMutation({ mutationFn: (format: ExportFormat) => exportReportAnalytics('field', period, format), onSuccess: (blob, format) => downloadBlob(blob, `field-service-${dayjs().format('YYYY-MM-DD')}.${format}`), onError: () => void message.error('The field report could not be exported.') })
  const data = query.data
  const total = data ? data.workload.assigned + data.workload.in_progress + data.workload.completed : 0
  const completionRate = data && total ? Math.round((data.workload.completed / total) * 100) : 0

  return <div className="report-page report-page--technician">
    <MountainBanner color="orange" breadcrumb={['Technician', 'Field service', 'Maintenance reports']} title="Field report desk" subtitle="Your assignments, completion evidence, intervention outcomes and reports shared with the organization." />
    <ReportPeriodToolbar period={data?.period} value={period} onChange={setPeriod} onRefresh={() => void query.refetch()} refreshing={query.isFetching} exporting={exportMutation.isPending} onExport={(format) => exportMutation.mutate(format)} />
    {query.isLoading ? <ReportLoading /> : !data ? <ReportPanel title="Reporting unavailable"><p>Field reporting data could not be loaded.</p></ReportPanel> : <>
      <section className="technician-scoreboard">
        <div className="technician-completion"><Progress type="dashboard" percent={completionRate} strokeColor="#159a61" railColor="#e8f2ed" size={112}/><span><strong>Completion rate</strong><small>{data.period.label}</small></span></div>
        <article><ListTodo size={18}/><span><small>Assigned</small><strong>{data.workload.assigned}</strong></span></article>
        <article><Clock3 size={18}/><span><small>In progress</small><strong>{data.workload.in_progress}</strong></span></article>
        <article className={data.workload.overdue ? 'danger' : ''}><TriangleAlert size={18}/><span><small>Overdue</small><strong>{data.workload.overdue}</strong></span></article>
        <article><TimerReset size={18}/><span><small>Average resolution</small><strong>{data.workload.average_minutes} min</strong></span></article>
        <article><FileCheck2 size={18}/><span><small>Reports submitted</small><strong>{data.report_activity.field_reports_submitted}</strong></span></article>
      </section>
      <div className="technician-report-grid">
        <ReportPanel className="technician-completion-chart" title="Completed field work" subtitle="Resolved interventions and maintenance jobs by day.">
          <div className="report-chart"><ResponsiveContainer><BarChart data={data.completion_trend}><CartesianGrid stroke="#edf1ef" vertical={false}/><XAxis dataKey="date" tickFormatter={(value) => dayjs(value).format('DD MMM')} tickLine={false} axisLine={false}/><YAxis allowDecimals={false} tickLine={false} axisLine={false}/><Tooltip labelFormatter={(value) => dayjs(value).format('DD MMM YYYY')}/><Bar dataKey="completed" name="Completed jobs" fill="#159a61" radius={[6,6,0,0]} barSize={17}/></BarChart></ResponsiveContainer></div>
        </ReportPanel>
        <ReportPanel className="technician-outcomes" title="Verified outcomes" subtitle="Final state recorded in submitted intervention reports.">
          <div className="report-donut-layout"><div className="report-chart report-chart--donut"><ResponsiveContainer><PieChart><Pie data={data.outcomes} dataKey="value" nameKey="label" innerRadius={52} outerRadius={76} paddingAngle={3}>{data.outcomes.map((entry, index) => <Cell key={entry.key} fill={REPORT_COLORS[index % REPORT_COLORS.length]}/>)}</Pie><Tooltip/></PieChart></ResponsiveContainer></div><div className="report-legend">{data.outcomes.map((entry, index) => <span key={entry.key}><i style={{ background: REPORT_COLORS[index % REPORT_COLORS.length] }}/><small>{entry.label}</small><strong>{entry.value}</strong></span>)}</div></div>
        </ReportPanel>
        <ReportPanel className="technician-assignment-board" title="My active field queue" subtitle="Ordered by planned execution time and current priority.">
          <div className="technician-assignment-list">{data.assignments.length === 0 ? <p className="report-empty-copy">No active field assignment.</p> : data.assignments.map((assignment) => <article key={assignment.id}><span className={`assignment-priority assignment-priority--${assignment.priority}`}/><div><small>{assignment.reference} · {humanize(assignment.type)}</small><strong>{assignment.station ?? 'Station not available'}</strong><p>{assignment.problem}</p></div><span><Tag color={assignment.status === 'in-progress' ? 'blue' : assignment.status === 'assigned' ? 'gold' : 'default'}>{humanize(assignment.status)}</Tag><time>{assignment.scheduled_at ? dayjs(assignment.scheduled_at).format('DD MMM, HH:mm') : 'Not scheduled'}</time></span></article>)}</div>
        </ReportPanel>
        <ReportPanel className="technician-proof-status" title="Documentation readiness" subtitle="Keep field proof complete before closing an intervention.">
          <div className="technician-proof-list"><article><CheckCircle2 size={18}/><span><strong>Diagnosis and action notes</strong><small>Recorded in the guided intervention workflow</small></span></article><article><CheckCircle2 size={18}/><span><strong>Before and after evidence</strong><small>Private photos included in intervention PDFs</small></span></article><article><FileCheck2 size={18}/><span><strong>Immutable final submission</strong><small>Auditable after the intervention is resolved</small></span></article><article><Clock3 size={18}/><span><strong>{data.report_activity.draft_reports} internal drafts</strong><small>Complete pending reports before your shift ends</small></span></article></div>
        </ReportPanel>
      </div>
      <InternalReportMailbox variant="technician" title="Field communications" subtitle="Send diagnoses, handovers and maintenance findings to authorized organization employees." />
    </>}
  </div>
}
