import type { ReactNode } from 'react'
import { Button, Select, Skeleton } from 'antd'
import { CalendarDays, RefreshCw } from 'lucide-react'
import { ExportDropdown, type ExportFormat } from '../ExportDropdown'
import type { ReportPeriodKey, ReportingPeriod } from '../../types/reporting'

export function ReportPeriodToolbar({
  period,
  value,
  onChange,
  onExport,
  exporting,
  refreshing,
  onRefresh,
  extra,
}: {
  period?: ReportingPeriod
  value: ReportPeriodKey
  onChange: (value: ReportPeriodKey) => void
  onExport: (format: ExportFormat) => void
  exporting?: boolean
  refreshing?: boolean
  onRefresh: () => void
  extra?: ReactNode
}) {
  return <div className="report-toolbar">
    <div className="report-period-summary"><CalendarDays size={19} /><span><strong>{period?.label ?? 'Reporting period'}</strong><small>{period ? `${period.from} - ${period.to}` : 'Loading verified dates'}</small></span></div>
    <div className="report-toolbar__actions">
      {extra}
      <Select<ReportPeriodKey> value={value} onChange={onChange} options={[{ value: '7d', label: 'Last 7 days' }, { value: '30d', label: 'Last 30 days' }, { value: '90d', label: 'Last 90 days' }]} />
      <Button aria-label="Refresh reports" icon={<RefreshCw size={15} />} loading={refreshing} onClick={onRefresh}>Refresh</Button>
      <ExportDropdown loading={exporting} onExport={onExport} label="Export report" />
    </div>
  </div>
}

export function ReportPanel({ title, subtitle, extra, children, className = '' }: { title: string; subtitle?: string; extra?: ReactNode; children: ReactNode; className?: string }) {
  return <section className={`report-panel ${className}`.trim()}><header><div><h2>{title}</h2>{subtitle && <p>{subtitle}</p>}</div>{extra}</header><div className="report-panel__body">{children}</div></section>
}

export function ReportLoading() {
  return <div className="report-loading"><Skeleton active paragraph={{ rows: 11 }} /></div>
}
