import { useDeferredValue, useMemo, useState, type ReactNode } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Card, Empty, Input, Select, Skeleton, Tag } from 'antd'
import { Activity, Building2, Clock3, Search, ShieldCheck, UserRound } from 'lucide-react'
import dayjs from 'dayjs'
import { httpClient } from '../api/httpClient'
import { MountainBanner } from '../components/MountainBanner'

interface AuditLog {
  id: number
  event_type: 'organization.created' | 'organization.updated'
  description: string
  metadata: { changed_fields?: string[] } | null
  created_at: string
  actor: { id: number; name: string; email: string; avatar_url: string | null } | null
  organization: { id: number; name: string } | null
}

interface AuditResponse { data: AuditLog[]; meta: { total: number } }

export function PlatformAuditLogsPage() {
  const [search, setSearch] = useState('')
  const [eventType, setEventType] = useState<AuditLog['event_type'] | undefined>()
  const deferredSearch = useDeferredValue(search)
  const filters = useMemo(() => ({ search: deferredSearch.trim() || undefined, event_type: eventType, per_page: 50 }), [deferredSearch, eventType])
  const logsQuery = useQuery({
    queryKey: ['platform-audit-logs', filters],
    queryFn: async () => (await httpClient.get<AuditResponse>('/platform/audit-logs', { params: filters })).data,
  })
  const logs = logsQuery.data?.data ?? []

  return <div className="platform-audit-page">
    <MountainBanner color="purple" breadcrumb={['Super Admin', 'Governance', 'Audit logs']} title="Audit logs" count={logsQuery.data?.meta.total ?? 0} subtitle="Traceable platform actions across organizations and administrator accounts." />
    <div className="platform-audit-summary">
      <AuditMetric icon={<ShieldCheck size={19} />} label="Protected history" value="Immutable" detail="Recorded server-side" />
      <AuditMetric icon={<Activity size={19} />} label="Events in view" value={logs.length} detail="Filtered platform actions" />
      <AuditMetric icon={<Clock3 size={19} />} label="Retention" value="90 days" detail="MVP audit policy" />
    </div>
    <Card className="platform-audit-card" title="Platform activity" extra={<span>{logs.length} events</span>}>
      <div className="platform-audit-toolbar">
        <Input value={search} onChange={(event) => setSearch(event.target.value)} prefix={<Search size={15} />} placeholder="Search actor, organization or action" allowClear />
        <Select value={eventType} onChange={setEventType} allowClear placeholder="All actions" options={[{ value: 'organization.created', label: 'Organization created' }, { value: 'organization.updated', label: 'Organization updated' }]} />
      </div>
      {logsQuery.isLoading ? <Skeleton active paragraph={{ rows: 8 }} /> : logs.length === 0 ? <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="No audited platform action matches the current filters" /> : <div className="platform-audit-list">
        {logs.map((log) => <article key={log.id}>
          <div className={`platform-audit-icon ${log.event_type.replace('.', '-')}`}><Building2 size={18} /></div>
          <div className="platform-audit-entry">
            <strong>{log.description}</strong>
            <span><UserRound size={13} />{log.actor?.name ?? 'System'} <i /> {log.organization?.name ?? 'Platform'} {log.metadata?.changed_fields?.length ? <em>Changed: {log.metadata.changed_fields.join(', ')}</em> : null}</span>
          </div>
          <Tag color={log.event_type === 'organization.created' ? 'green' : 'blue'}>{log.event_type === 'organization.created' ? 'Created' : 'Updated'}</Tag>
          <time>{dayjs(log.created_at).format('DD MMM YYYY, HH:mm')}</time>
        </article>)}
      </div>}
    </Card>
  </div>
}

function AuditMetric({ icon, label, value, detail }: { icon: ReactNode; label: string; value: string | number; detail: string }) {
  return <div><span>{icon}</span><p>{label}<strong>{value}</strong><small>{detail}</small></p></div>
}
