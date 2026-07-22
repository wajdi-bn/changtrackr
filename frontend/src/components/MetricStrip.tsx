import { Children, type ReactNode } from 'react'

export type MetricTone = 'green' | 'blue' | 'purple' | 'orange' | 'amber' | 'red' | 'gray'

export function MetricStrip({ children, className = '' }: { children: ReactNode; className?: string }) {
  return <section className={`metric-strip ${className}`.trim()} data-count={Children.count(children)}>{children}</section>
}

export function MetricItem({
  icon,
  label,
  value,
  helper,
  tone = 'green',
  badge,
}: {
  icon: ReactNode
  label: string
  value: ReactNode
  helper?: ReactNode
  tone?: MetricTone
  badge?: ReactNode
}) {
  return <article className={`metric-strip__item metric-strip__item--${tone}`}>
    <span className="metric-strip__icon">{icon}</span>
    <div className="metric-strip__content">
      <small>{label}</small>
      <strong>{value}</strong>
      {helper !== undefined && <p>{helper}</p>}
    </div>
    {badge !== undefined && <span className="metric-strip__badge">{badge}</span>}
  </article>
}
