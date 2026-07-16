import { Alert, Button, Form, Input } from 'antd'
import { LockKeyhole, Mail } from 'lucide-react'
import { useState } from 'react'
import { Link, Navigate, useLocation, useNavigate } from 'react-router-dom'
import { backendUrl } from '../api/httpClient'
import { AuthPageShell } from '../features/auth/AuthPageShell'
import { getAuthErrorCode } from '../features/auth/authApi'
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

  const requestedPath =
    (location.state as { from?: { pathname?: string } } | null)?.from?.pathname ??
    new URLSearchParams(location.search).get('redirect')
  const from = safeRedirectPath(requestedPath) ?? getRoleConfig(primaryRole).defaultPath

  async function handleSubmit(values: LoginFormValues) {
    setErrorMessage(null)
    setIsSubmitting(true)

    try {
      const user = await login(values.email, values.password)
      navigate(from === '/login' ? getRoleConfig(user.roles[0] ?? null).defaultPath : from, {
        replace: true,
      })
    } catch (error) {
      if (getAuthErrorCode(error) === 'email_unverified') {
        navigate(`/verify-email?status=sent&email=${encodeURIComponent(values.email)}`)
        return
      }
      setErrorMessage('Email or password is incorrect, or the account is inactive.')
    } finally {
      setIsSubmitting(false)
    }
  }

  function handleGoogleLogin() {
    setErrorMessage(null)
    window.location.assign(`${backendUrl}/auth/oauth/google/redirect`)
  }

  return (
    <AuthPageShell>
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
                    <Link to="/forgot-password">
                      I forgot my password
                    </Link>
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
              New to ChargeTrackr?{' '}
              <Link to={`/register?redirect=${encodeURIComponent(from)}`}>Create a client account</Link>
            </p>
    </AuthPageShell>
  )
}

function safeRedirectPath(value: string | null | undefined): string | null {
  return value?.startsWith('/') && !value.startsWith('//') ? value : null
}
