import type { ReactNode } from 'react'

export type IconSurfaceTone = 'green' | 'yellow' | 'red'
export type IconSurfaceSize = 'small' | 'medium' | 'large'

export function IconSurface({
  children,
  tone = 'green',
  size = 'medium',
  className = '',
}: {
  children: ReactNode
  tone?: IconSurfaceTone
  size?: IconSurfaceSize
  className?: string
}) {
  return (
    <span
      aria-hidden="true"
      className={`icon-surface icon-surface--${tone} icon-surface--${size} ${className}`.trim()}
    >
      {children}
    </span>
  )
}
