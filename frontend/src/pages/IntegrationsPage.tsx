import { useQuery } from '@tanstack/react-query'
import { Button, Tag } from 'antd'
import { CheckCircle2, Clock3, CreditCard, KeyRound, Mail, MapPinned, PlugZap, RefreshCw, ShieldCheck, TriangleAlert } from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import { MountainBanner } from '../components/MountainBanner'
import { AdminDataPanel, AdminEmpty, AdminLoading, AdminMetric, AdminMetricGrid, AdminStatus } from '../components/admin/AdminSurface'
import { getPlatformIntegrations } from '../features/platform/platformApi'
import type { PlatformIntegration } from '../types/platform'

const integrationIcons: Record<PlatformIntegration['id'], LucideIcon> = {
  'google-oauth': KeyRound,
  'transactional-email': Mail,
  'ocpp-gateway': PlugZap,
  'payment-adapter': CreditCard,
  mapping: MapPinned,
}

export function IntegrationsPage() {
  const integrationsQuery = useQuery({ queryKey: ['platform-integrations'], queryFn: getPlatformIntegrations })
  const summary = integrationsQuery.data?.summary

  return <div className="super-admin-page integrations-page">
    <MountainBanner color="teal" breadcrumb={['Super Admin', 'Platform', 'Integrations']} title="Integrations" count={summary?.total} subtitle="Monitor the services that connect authentication, communications, charging operations, billing and mapping." />
    <AdminMetricGrid>
      <AdminMetric icon={PlugZap} label="Connected services" value={summary?.total ?? 0} helper="Platform integration inventory" />
      <AdminMetric icon={CheckCircle2} label="Ready" value={summary?.operational ?? 0} helper="Operational or fully configured" tone="blue" />
      <AdminMetric icon={TriangleAlert} label="Need attention" value={summary?.attention ?? 0} helper="Missing or incomplete configuration" tone="orange" />
      <AdminMetric icon={ShieldCheck} label="Sandbox services" value={summary?.sandbox ?? 0} helper="No real financial transaction" tone="purple" />
    </AdminMetricGrid>
    <AdminDataPanel title="Connected services" subtitle="Live configuration posture and safe operational indicators. Secrets are never returned." extra={<Button icon={<RefreshCw size={14} />} loading={integrationsQuery.isFetching} onClick={() => void integrationsQuery.refetch()}>Refresh status</Button>}>
      {integrationsQuery.isLoading ? <AdminLoading rows={12} /> : integrationsQuery.isError ? <AdminEmpty description="Integration status could not be loaded" actionLabel="Try again" onAction={() => void integrationsQuery.refetch()} /> : <>
        <div className="integration-checkline"><Clock3 size={13} /><span>Last checked {formatDateTime(integrationsQuery.data?.checked_at)}</span><i />Environment secrets remain managed outside the browser.</div>
        <div className="integration-grid">{integrationsQuery.data?.data.map((integration) => <IntegrationCard key={integration.id} integration={integration} />)}</div>
      </>}
    </AdminDataPanel>
  </div>
}

function IntegrationCard({ integration }: { integration: PlatformIntegration }) {
  const Icon = integrationIcons[integration.id]
  return <article className={`integration-card integration-card--${integration.status}`}>
    <header>
      <span className="integration-card__icon"><Icon size={20} /></span>
      <div><small>{integration.category}</small><h2>{integration.name}</h2></div>
      <AdminStatus status={integration.status} />
    </header>
    <div className="integration-provider"><div><span>Provider</span><strong>{integration.provider}</strong></div><Tag color={integration.mode === 'Sandbox' ? 'purple' : integration.mode === 'Development' ? 'blue' : 'green'}>{integration.mode}</Tag></div>
    <p>{integration.description}</p>
    <div className="integration-metrics">{integration.metrics.map((metric) => <div key={metric.label}><span>{metric.label}</span><strong>{metric.value}</strong></div>)}</div>
    <div className="integration-activity"><Clock3 size={13} /><span>{integration.last_activity_at ? `Last activity ${formatDateTime(integration.last_activity_at)}` : 'No activity timestamp recorded'}</span></div>
    <footer>{integration.safeguards.map((safeguard) => <span key={safeguard}><CheckCircle2 size={12} />{safeguard}</span>)}</footer>
  </article>
}

function formatDateTime(value?: string | null): string {
  if (!value) return 'just now'
  return new Intl.DateTimeFormat('en', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value))
}
