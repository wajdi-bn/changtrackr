import type { ReactNode } from 'react'
import { motion, useReducedMotion } from 'framer-motion'

interface AnimatedSidebarIconProps {
  active: boolean
  children: ReactNode
}

export function AnimatedSidebarIcon({ active, children }: AnimatedSidebarIconProps) {
  const reducedMotion = useReducedMotion()

  return (
    <motion.span
      className="animated-sidebar-icon"
      initial={false}
      animate={reducedMotion ? undefined : { scale: active ? 1.08 : 1, y: active ? -0.5 : 0 }}
      whileHover={reducedMotion ? undefined : { scale: 1.12, rotate: active ? 0 : -5 }}
      transition={{ type: 'spring', stiffness: 420, damping: 24 }}
    >
      {children}
    </motion.span>
  )
}

interface AnimatedBellIconProps {
  signal: number
  children: ReactNode
}

export function AnimatedBellIcon({ signal, children }: AnimatedBellIconProps) {
  const reducedMotion = useReducedMotion()

  return (
    <motion.span
      className="animated-bell-icon"
      key={signal}
      initial={false}
      animate={reducedMotion || signal === 0 ? undefined : { rotate: [0, -12, 10, -7, 4, 0] }}
      whileHover={reducedMotion ? undefined : { rotate: [0, -8, 8, 0] }}
      transition={{ duration: 0.5, ease: 'easeInOut' }}
    >
      {children}
    </motion.span>
  )
}
