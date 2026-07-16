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
          src="/assets/charge-hero.png"
          alt="Electric vehicle connected to a charging station"
          className="prototype-login-hero-image"
        />
        <div className="prototype-login-overlay" />
        <div className="prototype-login-visual-content">
          <Link to="/" className="prototype-login-brand prototype-login-brand-light">
            <img src="/assets/Logo.png" alt="ChargeTrackr logo" />
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
        <motion.div
          initial={{ opacity: 0, y: 18 }}
          animate={{ opacity: 1, y: 0 }}
          transition={{ duration: 0.35, ease: 'easeOut' }}
          className="prototype-login-card-shell"
        >
          <Card className="prototype-login-card">
            <Link to="/" className="prototype-login-brand prototype-login-mobile-brand">
              <img src="/assets/Logo.png" alt="ChargeTrackr logo" />
              <span>ChargeTrackr</span>
            </Link>
            {children}
          </Card>
        </motion.div>
      </section>
    </main>
  )
}
