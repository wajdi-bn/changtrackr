import { Alert, Button, Checkbox, Form, Input } from 'antd'
import { LockKeyhole, Mail, UserRound } from 'lucide-react'
import { useState } from 'react'
import { Link, Navigate, useLocation, useNavigate } from 'react-router-dom'
import { backendUrl } from '../api/httpClient'
import { AuthPageShell } from '../features/auth/AuthPageShell'
import {
  getAuthErrorMessage,
  registerClientRequest,
  type RegisterClientPayload,
} from '../features/auth/authApi'
import { getRoleConfig } from '../features/auth/roleConfig'
import { useAuth } from '../features/auth/useAuth'

interface RegisterFormValues {
  name: string
  email: string
  password: string
  password_confirmation: string
  terms_accepted: boolean
}

export function RegisterPage() {
  const { isAuthenticated, primaryRole } = useAuth()
  const location = useLocation()
  const navigate = useNavigate()
  const [errorMessage, setErrorMessage] = useState<string | null>(null)
  const [isSubmitting, setIsSubmitting] = useState(false)
  const redirect = safeRedirectPath(new URLSearchParams(location.search).get('redirect'))

  if (isAuthenticated) {
    return <Navigate to={getRoleConfig(primaryRole).defaultPath} replace />
  }

  async function handleSubmit(values: RegisterFormValues) {
    setErrorMessage(null)
    setIsSubmitting(true)

    try {
      const payload: RegisterClientPayload = values
      const response = await registerClientRequest(payload)
      const params = new URLSearchParams({ status: 'sent', email: response.email })
      if (redirect) params.set('redirect', redirect)
      navigate(`/verify-email?${params.toString()}`, { replace: true })
    } catch (error) {
      setErrorMessage(getAuthErrorMessage(error, 'Your account could not be created.'))
    } finally {
      setIsSubmitting(false)
    }
  }

  return (
    <AuthPageShell
      eyebrow="Client charging access"
      title="Create one account for every charging journey."
      description="Find compatible stations, follow charging sessions and keep payments together in one secure client space."
    >
      <header className="prototype-login-card-heading">
        <h1>Create your account</h1>
        <p>Public registration is reserved for EV charging clients.</p>
      </header>

      <Button
        className="prototype-google-button"
        block
        onClick={() => window.location.assign(`${backendUrl}/auth/oauth/google/redirect`)}
      >
        <img src="/assets/google.png" alt="Google" />
        Continue with Google
      </Button>

      <div className="prototype-login-divider" aria-hidden="true">
        <span />
        <small>or register with email</small>
        <span />
      </div>

      <Form<RegisterFormValues>
        className="prototype-login-form"
        layout="vertical"
        requiredMark={false}
        initialValues={{ terms_accepted: false }}
        onFinish={handleSubmit}
      >
        <Form.Item label="Full name" name="name" rules={[{ required: true, min: 2, message: 'Enter your full name' }]}>
          <Input prefix={<UserRound size={16} />} placeholder="Your full name" autoComplete="name" />
        </Form.Item>

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

        <Form.Item
          label="Password"
          name="password"
          extra="At least 8 characters with uppercase, lowercase and a number."
          rules={[{ required: true, message: 'Password is required' }]}
        >
          <Input.Password
            prefix={<LockKeyhole size={16} />}
            placeholder="Create a strong password"
            autoComplete="new-password"
          />
        </Form.Item>

        <Form.Item
          label="Confirm password"
          name="password_confirmation"
          dependencies={['password']}
          rules={[
            { required: true, message: 'Confirm your password' },
            ({ getFieldValue }) => ({
              validator(_, value) {
                return !value || getFieldValue('password') === value
                  ? Promise.resolve()
                  : Promise.reject(new Error('The passwords do not match'))
              },
            }),
          ]}
        >
          <Input.Password
            prefix={<LockKeyhole size={16} />}
            placeholder="Repeat your password"
            autoComplete="new-password"
          />
        </Form.Item>

        <Form.Item
          className="prototype-terms-item"
          name="terms_accepted"
          valuePropName="checked"
          rules={[{ validator: (_, value) => value ? Promise.resolve() : Promise.reject(new Error('Accept the terms to continue')) }]}
        >
          <Checkbox>I agree to the Terms of Service and Privacy Policy.</Checkbox>
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
          Create client account
        </Button>
      </Form>

      <p className="prototype-login-security-note">
        Already have an account?{' '}
        <Link to={`/login?redirect=${encodeURIComponent(redirect ?? '/overview')}`}>Sign in</Link>
      </p>
    </AuthPageShell>
  )
}

function safeRedirectPath(value: string | null): string | null {
  return value?.startsWith('/') && !value.startsWith('//') ? value : null
}
