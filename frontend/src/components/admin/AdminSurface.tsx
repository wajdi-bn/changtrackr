import type { ReactNode } from 'react'
import { Button, Empty, Skeleton } from 'antd'
import type { LucideIcon } from 'lucide-react'
import { MetricItem, MetricStrip, type MetricTone } from '../MetricStrip'

export function AdminMetricGrid({ children }: { children: ReactNode }) {
  return <MetricStrip className="admin-metric-grid">{children}</MetricStrip>
}

export function AdminMetric({ icon: Icon, label, value, helper, tone = 'green' }: { icon: LucideIcon; label: string; value: ReactNode; helper: string; tone?: MetricTone }) {
  return <MetricItem icon={<Icon size={18} />} label={label} value={value} helper={helper} tone={tone} />
}

export function AdminDataPanel({ title, subtitle, extra, children }: { title: string; subtitle: string; extra?: ReactNode; children: ReactNode }) {
  return <section className="admin-data-panel">
    <header><div><h2>{title}</h2><p>{subtitle}</p></div>{extra}</header>
    <div className="admin-data-panel__body">{children}</div>
  </section>
}

export function AdminLoading({ rows = 7 }: { rows?: number }) {
  return <div className="admin-loading"><Skeleton active paragraph={{ rows }} /></div>
}

export function AdminEmpty({ description, actionLabel, onAction }: { description: string; actionLabel?: string; onAction?: () => void }) {
  return <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description={description}>{actionLabel && onAction ? <Button type="primary" onClick={onAction}>{actionLabel}</Button> : null}</Empty>
}

export function AdminStatus({ status }: { status: string }) {
  const normalized = status.toLowerCase().replaceAll('_', '-')
  const label = status.replaceAll('_', ' ').replace(/^./, (character) => character.toUpperCase())
  return <span className={`admin-status admin-status--${normalized}`}><i />{label}</span>
}
