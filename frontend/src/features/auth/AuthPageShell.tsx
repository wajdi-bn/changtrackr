import { Card } from 'antd'
import { motion } from 'framer-motion'
import type { ReactNode } from 'react'
import { Link } from 'react-router-dom'

interface AuthPageShellProps {
  children: ReactNode
  eyebrow?: string
  title?: string
  description?: string
}

export function AuthPageShell({
  children,
  eyebrow = 'EV network supervision',
  title = 'Operate your EV charging network with clear availability data.',
  description = 'Monitor station availability, charging sessions and operational activity from one secure workspace.',
}: AuthPageShellProps) {
  return (
    <main className="prototype-login-page">
      <section className="prototype-login-visual" aria-label="Electric vehicle charging">
        <img
          src="/assets/landing/charging-network-hero.webp"
          alt="Electric vehicle connected to a charging station"
          className="prototype-login-hero-image"
          width={1600}
          height={800}
          fetchPriority="high"
        />
        <div className="prototype-login-overlay" />
        <div className="prototype-login-visual-content">
          <Link to="/" className="prototype-login-brand prototype-login-brand-light">
            <img src="/assets/branding/charge-trackr-logo.webp" alt="ChargeTrackr logo" width={384} height={384} />
            <span>ChargeTrackr</span>
          </Link>

          <div className="prototype-login-copy">
            <p className="prototype-login-badge">{eyebrow}</p>
            <h1>{title}</h1>
            <p>{description}</p>
          </div>
        </div>
      </section>

      <section className="prototype-login-panel">
        <svg
          className="prototype-login-panel-landscape"
          viewBox="0 0 900 260"
          preserveAspectRatio="none"
          aria-hidden="true"
        >
          <path d="M0 260V166C118 98 220 198 338 132C463 62 566 181 684 112C767 64 835 78 900 116V260Z" />
          <path d="M0 260V202C106 142 223 222 341 168C462 112 558 210 681 158C772 120 838 126 900 158V260Z" />
          <path d="M0 260V226C133 184 235 242 369 204C489 170 582 238 704 202C780 180 844 184 900 205V260Z" />
        </svg>
        <motion.div
          initial={{ opacity: 0, y: 18 }}
          animate={{ opacity: 1, y: 0 }}
          transition={{ duration: 0.35, ease: 'easeOut' }}
          className="prototype-login-card-shell"
        >
          <Card className="prototype-login-card">
            <Link to="/" className="prototype-login-brand prototype-login-mobile-brand">
              <img src="/assets/branding/charge-trackr-logo.webp" alt="ChargeTrackr logo" width={384} height={384} />
              <span>ChargeTrackr</span>
            </Link>
            {children}
          </Card>
        </motion.div>
      </section>
    </main>
  )
}
