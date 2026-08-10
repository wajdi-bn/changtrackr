import { Children, type ReactNode } from 'react'
import { IconSurface, type IconSurfaceTone } from './IconSurface'

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
  const iconTone: IconSurfaceTone = {
    green: 'brand',
    blue: 'blue',
    purple: 'teal',
    orange: 'amber',
    amber: 'amber',
    red: 'red',
    gray: 'graphite',
  }[tone] as IconSurfaceTone

  return <article className={`metric-strip__item metric-strip__item--${tone}`}>
    <IconSurface className="metric-strip__icon" tone={iconTone}>{icon}</IconSurface>
    <div className="metric-strip__content">
      <small>{label}</small>
      <strong>{value}</strong>
      {helper !== undefined && <p>{helper}</p>}
    </div>
    {badge !== undefined && <span className="metric-strip__badge">{badge}</span>}
  </article>
}
