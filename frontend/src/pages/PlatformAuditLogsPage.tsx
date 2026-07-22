import { useDeferredValue, useMemo, useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { App, Avatar, Button, DatePicker, Drawer, Input, Select, Table, Tag, Tooltip } from 'antd'
import type { ColumnsType } from 'antd/es/table'
import type { Dayjs } from 'dayjs'
import dayjs from 'dayjs'
import { Building2, CalendarClock, Eye, Fingerprint, Search, ShieldCheck, Users } from 'lucide-react'
import { MountainBanner } from '../components/MountainBanner'
import { ExportDropdown, type ExportFormat } from '../components/ExportDropdown'
import { AdminDataPanel, AdminEmpty, AdminLoading, AdminMetric, AdminMetricGrid } from '../components/admin/AdminSurface'
import { exportPlatformAuditLogs, getPlatformAuditLogs } from '../features/platform/platformApi'
import type { PlatformAuditFilters, PlatformAuditLog } from '../types/platform'
import { downloadBlob } from '../utils/downloadBlob'

const roleOptions = [
  { value: 'super_admin', label: 'Super Administrator' },
  { value: 'admin', label: 'Organization Administrator' },
  { value: 'operator', label: 'Operator' },
  { value: 'technician', label: 'Technician' },
  { value: 'client', label: 'Client' },
]

export function PlatformAuditLogsPage() {
  const [search, setSearch] = useState('')
  const [eventType, setEventType] = useState<string>()
  const [module, setModule] = useState<string>()
  const [actorId, setActorId] = useState<number>()
  const [role, setRole] = useState<string>()
  const [organizationId, setOrganizationId] = useState<number>()
  const [dateRange, setDateRange] = useState<[Dayjs | null, Dayjs | null] | null>(null)
  const [page, setPage] = useState(1)
  const [selectedLog, setSelectedLog] = useState<PlatformAuditLog | null>(null)
  const deferredSearch = useDeferredValue(search)
  const { message } = App.useApp()
  const filters = useMemo<PlatformAuditFilters>(() => ({
    search: deferredSearch.trim() || undefined,
    event_type: eventType,
    module,
    actor_id: actorId,
    role,
    organization_id: organizationId,
    date_from: dateRange?.[0]?.format('YYYY-MM-DD'),
    date_to: dateRange?.[1]?.format('YYYY-MM-DD'),
    page,
    per_page: 25,
  }), [actorId, dateRange, deferredSearch, eventType, module, organizationId, page, role])
  const logsQuery = useQuery({ queryKey: ['platform-audit-logs', filters], queryFn: () => getPlatformAuditLogs(filters) })
  const exportMutation = useMutation({
    mutationFn: (format: ExportFormat) => exportPlatformAuditLogs(filters, format),
    onSuccess: (blob, format) => downloadBlob(blob, `platform-audit-${dayjs().format('YYYY-MM-DD')}.${format}`),
    onError: () => void message.error('The audit export could not be generated.'),
  })
  const logs = logsQuery.data?.data ?? []
  const facets = logsQuery.data?.facets
  const modules = useMemo(() => Array.from(new Set((facets?.event_types ?? []).map((item) => item.value.split('.')[0]))).sort(), [facets])
  const resetPage = () => setPage(1)
  const columns: ColumnsType<PlatformAuditLog> = [
    { title: 'Date / time', dataIndex: 'created_at', width: 154, render: (value: string) => <div className="audit-time"><strong>{dayjs(value).format('DD MMM YYYY')}</strong><small>{dayjs(value).format('HH:mm:ss')}</small></div> },
    { title: 'Actor', key: 'actor', width: 220, render: (_, log) => <div className="admin-primary-cell"><Avatar src={log.actor?.avatar_url ?? undefined}>{initials(log.actor?.name ?? 'System')}</Avatar><span><strong>{log.actor?.name ?? 'System'}</strong><small>{log.actor?.email ?? 'Automated process'}</small></span></div> },
    { title: 'Role', key: 'role', width: 142, render: (_, log) => roleLabel(log.actor?.roles[0]) },
    { title: 'Action', key: 'action', width: 210, render: (_, log) => <div className="admin-stack-cell"><span>{eventLabel(log.event_type)}</span><small>{moduleLabel(log.module)}</small></div> },
    { title: 'Organization', key: 'organization', width: 180, render: (_, log) => log.organization?.name ?? 'Platform' },
    { title: 'Object', key: 'subject', width: 130, render: (_, log) => log.subject ? `${log.subject.type} #${log.subject.id}` : '-' },
    { title: 'IP address', dataIndex: 'ip_address', width: 132, render: (value: string | null) => <code className="audit-ip">{value ?? '-'}</code> },
    { title: 'Status', key: 'status', width: 106, render: () => <Tag color="green">Success</Tag> },
    { title: '', key: 'actions', width: 58, align: 'right', render: (_, log) => <Tooltip title="View event details"><Button aria-label={`View audit event ${log.id}`} type="text" icon={<Eye size={15} />} onClick={() => setSelectedLog(log)} /></Tooltip> },
  ]

  return <div className="super-admin-page platform-audit-page">
    <MountainBanner color="purple" breadcrumb={['Super Admin', 'Governance', 'Audit logs']} title="Audit logs" count={logsQuery.data?.summary.total ?? 0} subtitle="Review security-sensitive changes across organizations, accounts and platform access." />
    <AdminMetricGrid>
      <AdminMetric icon={ShieldCheck} label="Recorded events" value={logsQuery.data?.summary.total ?? 0} helper="Server-side governance history" />
      <AdminMetric icon={CalendarClock} label="Activity today" value={logsQuery.data?.summary.today ?? 0} helper="Events since midnight" tone="blue" />
      <AdminMetric icon={Users} label="Active actors" value={logsQuery.data?.summary.actors ?? 0} helper="Distinct recorded identities" tone="purple" />
      <AdminMetric icon={Building2} label="Organizations" value={logsQuery.data?.summary.organizations ?? 0} helper="Tenants represented in history" tone="orange" />
    </AdminMetricGrid>
    <AdminDataPanel title="Platform activity" subtitle="Filter auditable actions and inspect their complete security context." extra={<ExportDropdown loading={exportMutation.isPending} onExport={(format) => exportMutation.mutate(format)} />}>
      <div className="audit-filter-toolbar">
        <Input value={search} onChange={(event) => { setSearch(event.target.value); resetPage() }} prefix={<Search size={15} />} placeholder="Search actor, event or organization" allowClear />
        <DatePicker.RangePicker value={dateRange} onChange={(value) => { setDateRange(value); resetPage() }} format="DD MMM YYYY" />
        <Select value={actorId} onChange={(value) => { setActorId(value); resetPage() }} allowClear showSearch optionFilterProp="label" placeholder="All actors" options={(facets?.actors ?? []).map((actor) => ({ value: actor.id, label: actor.name }))} />
        <Select value={role} onChange={(value) => { setRole(value); resetPage() }} allowClear placeholder="All roles" options={roleOptions} />
        <Select value={module} onChange={(value) => { setModule(value); setEventType(undefined); resetPage() }} allowClear placeholder="All modules" options={modules.map((value) => ({ value, label: moduleLabel(value) }))} />
        <Select value={eventType} onChange={(value) => { setEventType(value); resetPage() }} allowClear showSearch optionFilterProp="label" placeholder="All actions" options={(facets?.event_types ?? []).filter((item) => !module || item.value.startsWith(`${module}.`)).map((item) => ({ value: item.value, label: `${eventLabel(item.value)} (${item.count})` }))} />
        <Select value={organizationId} onChange={(value) => { setOrganizationId(value); resetPage() }} allowClear showSearch optionFilterProp="label" placeholder="All organizations" options={(facets?.organizations ?? []).map((organization) => ({ value: organization.id, label: organization.name }))} />
      </div>
      {logsQuery.isLoading ? <AdminLoading rows={9} /> : logsQuery.isError ? <AdminEmpty description="Audit history could not be loaded" actionLabel="Try again" onAction={() => void logsQuery.refetch()} /> : logs.length === 0 ? <AdminEmpty description="No audit event matches the selected filters" /> : <Table rowKey="id" className="admin-data-table platform-audit-table" columns={columns} dataSource={logs} pagination={{ current: logsQuery.data?.meta.current_page, total: logsQuery.data?.meta.total, pageSize: logsQuery.data?.meta.per_page, showSizeChanger: false, onChange: setPage }} scroll={{ x: 1360 }} onRow={(record) => ({ onDoubleClick: () => setSelectedLog(record) })} />}
    </AdminDataPanel>
    <AuditDetailDrawer log={selectedLog} onClose={() => setSelectedLog(null)} />
  </div>
}

function AuditDetailDrawer({ log, onClose }: { log: PlatformAuditLog | null; onClose: () => void }) {
  const metadata = log ? Object.entries(log.metadata).filter(([key]) => key !== 'user_agent' && key !== 'ip_address') : []
  return <Drawer className="admin-detail-drawer audit-detail-drawer" size={570} open={Boolean(log)} onClose={onClose} title="Audit event details">
    {log && <div className="audit-detail">
      <header><span><Fingerprint size={24} /></span><div><Tag color="green">Success</Tag><h2>{eventLabel(log.event_type)}</h2><p>{log.description}</p></div></header>
      <section className="audit-detail-facts">
        <div><small>Date and time</small><strong>{dayjs(log.created_at).format('DD MMM YYYY, HH:mm:ss')}</strong></div>
        <div><small>Event ID</small><strong>#{log.id}</strong></div>
        <div><small>Actor</small><strong>{log.actor?.name ?? 'System'}</strong><span>{log.actor?.email ?? 'Automated process'}</span></div>
        <div><small>Role</small><strong>{roleLabel(log.actor?.roles[0])}</strong></div>
        <div><small>Organization</small><strong>{log.organization?.name ?? 'Platform'}</strong></div>
        <div><small>Affected object</small><strong>{log.subject ? `${log.subject.type} #${log.subject.id}` : 'Not specified'}</strong></div>
      </section>
      <section className="audit-security-context"><h3>Security context</h3><div><span>IP address</span><code>{log.ip_address ?? 'Not recorded'}</code></div><div><span>User agent</span><p>{String(log.metadata.user_agent ?? 'Not recorded')}</p></div></section>
      <section className="audit-change-set"><h3>Recorded changes</h3>{metadata.length === 0 ? <p>No additional change data was recorded.</p> : <div>{metadata.map(([key, value]) => <div key={key}><span>{moduleLabel(key)}</span><strong>{formatMetadata(value)}</strong></div>)}</div>}</section>
    </div>}
  </Drawer>
}

function eventLabel(event: string): string {
  const [, action = event] = event.split('.', 2)
  return action.replaceAll('_', ' ').replace(/^./, (character) => character.toUpperCase())
}
function moduleLabel(module: string): string { return module.replaceAll('_', ' ').replace(/^./, (character) => character.toUpperCase()) }
function roleLabel(role?: string): string { return roleOptions.find((option) => option.value === role)?.label ?? (role ? moduleLabel(role) : 'System') }
function initials(name: string): string { return name.split(' ').filter(Boolean).slice(0, 2).map((part) => part[0]).join('').toUpperCase() }
function formatMetadata(value: unknown): string {
  if (Array.isArray(value)) return value.length ? value.join(', ') : 'None'
  if (value === null || value === undefined || value === '') return 'None'
  return typeof value === 'object' ? JSON.stringify(value) : String(value)
}
