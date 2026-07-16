import { Alert, Button, Card, Form, Input } from 'antd'
import { motion } from 'framer-motion'
import { LockKeyhole, Mail } from 'lucide-react'
import { useState } from 'react'
import { Link, Navigate, useLocation, useNavigate } from 'react-router-dom'
import { backendUrl } from '../api/httpClient'
import { getRoleConfig } from '../features/auth/roleConfig'
import { useAuth } from '../features/auth/useAuth'

interface LoginFormValues {
  email: string
  password: string
}

const oauthErrors: Record<string, string> = {
  account_conflict: 'This email is already linked to another Google account.',
  account_inactive: 'This account is inactive. Contact your administrator.',
  email_not_verified: 'Google did not provide a verified email address.',
  invalid_organization: 'This employee account is not linked to an active organization.',
  missing_identity: 'Google did not provide the information required to sign in.',
  provider_error: 'Google sign in could not be completed. Please try again.',
  session_not_created: 'The sign-in session could not be created. Please try again.',
}

export function LoginPage() {
  const { isAuthenticated, login, primaryRole } = useAuth()
  const navigate = useNavigate()
  const location = useLocation()
  const [errorMessage, setErrorMessage] = useState<string | null>(null)
  const [isSubmitting, setIsSubmitting] = useState(false)
  const oauthErrorCode = new URLSearchParams(location.search).get('oauth_error')
  const oauthErrorMessage = oauthErrorCode
    ? (oauthErrors[oauthErrorCode] ?? oauthErrors.provider_error)
    : null

  if (isAuthenticated) {
    return <Navigate to={getRoleConfig(primaryRole).defaultPath} replace />
  }

  const from =
    (location.state as { from?: { pathname?: string } } | null)?.from?.pathname ??
    getRoleConfig(primaryRole).defaultPath

  async function handleSubmit(values: LoginFormValues) {
    setErrorMessage(null)
    setIsSubmitting(true)

    try {
      const user = await login(values.email, values.password)
      navigate(from === '/login' ? getRoleConfig(user.roles[0] ?? null).defaultPath : from, {
        replace: true,
      })
    } catch {
      setErrorMessage('Email or password is incorrect, or the account is inactive.')
    } finally {
      setIsSubmitting(false)
    }
  }

  function handleGoogleLogin() {
    setErrorMessage(null)
    window.location.assign(`${backendUrl}/auth/oauth/google/redirect`)
  }

  function handleForgotPassword() {
    setErrorMessage('Password recovery is not available yet. Contact your administrator.')
  }

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
            <p className="prototype-login-badge">EV network supervision</p>
            <h1>Operate your EV charging network with clear availability data.</h1>
            <p>
              Monitor station availability, charging sessions and operational activity from one
              secure workspace.
            </p>
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

            <header className="prototype-login-card-heading">
              <h1>Sign in</h1>
              <p>Sign in with your account or continue with Google.</p>
            </header>

            <Button className="prototype-google-button" block onClick={handleGoogleLogin}>
              <img src="/assets/google.png" alt="Google" />
              Continue with Google
            </Button>

            <div className="prototype-login-divider" aria-hidden="true">
              <span />
              <small>or continue with email</small>
              <span />
            </div>

            <Form<LoginFormValues>
              className="prototype-login-form"
              layout="vertical"
              requiredMark={false}
              onFinish={handleSubmit}
            >
              <Form.Item
                label="Email"
                name="email"
                rules={[
                  { required: true, message: 'Email is required' },
                  { type: 'email', message: 'Enter a valid email address' },
                ]}
              >
                <Input
                  prefix={<Mail size={16} aria-hidden="true" />}
                  placeholder="operator@chargetrackr.local"
                  autoComplete="email"
                />
              </Form.Item>

              <Form.Item
                label={
                  <span className="prototype-password-label">
                    <span>Password</span>
                    <button type="button" onClick={handleForgotPassword}>
                      I forgot my password
                    </button>
                  </span>
                }
                name="password"
                rules={[{ required: true, message: 'Password is required' }]}
              >
                <Input
                  prefix={<LockKeyhole size={16} aria-hidden="true" />}
                  placeholder="Password"
                  type="password"
                  autoComplete="current-password"
                />
              </Form.Item>

              {(errorMessage || oauthErrorMessage) && (
                <Alert
                  className="prototype-login-alert"
                  type="error"
                  title={errorMessage ?? oauthErrorMessage}
                  showIcon
                />
              )}

              <Button
                className="prototype-login-submit"
                type="primary"
                htmlType="submit"
                loading={isSubmitting}
                block
              >
                Sign in to dashboard
              </Button>
            </Form>

            <p className="prototype-login-security-note">
              Secure authentication managed by the ChargeTrackr server.
            </p>
          </Card>
        </motion.div>
      </section>
    </main>
  )
}
