import { Alert, Button, Form, Input } from 'antd'
import { ArrowLeft, Mail } from 'lucide-react'
import { useState } from 'react'
import { Link, useLocation } from 'react-router-dom'
import { AuthPageShell } from '../features/auth/AuthPageShell'
import { getAuthErrorMessage, resendVerificationRequest } from '../features/auth/authApi'

export function VerifyEmailPage() {
  const location = useLocation()
  const params = new URLSearchParams(location.search)
  const status = params.get('status')
  const initialEmail = params.get('email') ?? ''
  const redirect = safeRedirectPath(params.get('redirect'))
  const [sentEmail, setSentEmail] = useState(status === 'sent' ? initialEmail : '')
  const [errorMessage, setErrorMessage] = useState<string | null>(null)
  const [isSubmitting, setIsSubmitting] = useState(false)

  async function handleResend(values: { email: string }) {
    setErrorMessage(null)
    setIsSubmitting(true)

    try {
      await resendVerificationRequest(values.email)
      setSentEmail(values.email)
    } catch (error) {
      setErrorMessage(getAuthErrorMessage(error, 'The verification email could not be sent.'))
    } finally {
      setIsSubmitting(false)
    }
  }

  const loginPath = `/login?redirect=${encodeURIComponent(redirect ?? '/overview')}`

  return (
    <AuthPageShell
      eyebrow="Verified client identity"
      title="Keep every charging account trusted and recoverable."
      description="Email verification protects charging history, subscriptions and payment activity."
    >
      <header className="prototype-login-card-heading">
        <h1>{status === 'verified' ? 'Email verified' : 'Verify your email'}</h1>
        <p>Complete this step before signing in with your local password.</p>
      </header>

      {status === 'verified' ? (
        <div className="prototype-auth-state">
          <Alert
            type="success"
            showIcon
            title="Your email address is verified"
            description="Your client account is ready. You can now sign in securely."
          />
          <Link className="prototype-auth-primary-link" to={loginPath}>Continue to sign in</Link>
        </div>
      ) : (
        <>
          <Alert
            className="prototype-login-alert"
            type={status === 'invalid' ? 'error' : 'info'}
            showIcon
            title={status === 'invalid' ? 'This verification link is invalid or expired' : 'Check your inbox'}
            description={sentEmail
              ? `A verification link was requested for ${sentEmail}.`
              : 'Enter your email address to request a new verification link.'}
          />

          <Form<{ email: string }>
            className="prototype-login-form"
            layout="vertical"
            validateTrigger="onBlur"
            requiredMark={false}
            initialValues={{ email: initialEmail }}
            onFinish={handleResend}
          >
            <Form.Item
              label="Email"
              name="email"
              rules={[
                { required: true, message: 'Email is required' },
                { type: 'email', message: 'Enter a valid email address' },
              ]}
            >
              <Input prefix={<Mail size={16} />} placeholder="name@example.com" autoComplete="email" />
            </Form.Item>

            {errorMessage && (
              <Alert className="prototype-login-alert" type="error" title={errorMessage} showIcon />
            )}

            <Button
              className="prototype-login-submit"
              type="primary"
              htmlType="submit"
              loading={isSubmitting}
              block
            >
              Send verification email
            </Button>
          </Form>

          <Link className="prototype-auth-back-link" to={loginPath}>
            <ArrowLeft size={15} /> Return to sign in
          </Link>
        </>
      )}
    </AuthPageShell>
  )
}

function safeRedirectPath(value: string | null): string | null {
  return value?.startsWith('/') && !value.startsWith('//') ? value : null
}
