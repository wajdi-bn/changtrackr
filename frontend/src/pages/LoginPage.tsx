import { Alert, Button, Card, Divider, Form, Input, Space, Tag, Typography } from 'antd'
import { Navigate, useLocation, useNavigate } from 'react-router-dom'
import { useState } from 'react'
import { Zap } from 'lucide-react'
import { backendUrl } from '../api/httpClient'
import { useAuth } from '../features/auth/useAuth'
import { getRoleConfig } from '../features/auth/roleConfig'

interface LoginFormValues {
  email: string
  password: string
}

const demoAccounts = [
  { label: 'Super Admin', email: 'superadmin@chargetrackr.local' },
  { label: 'Admin', email: 'admin@chargetrackr.local' },
  { label: 'Operator', email: 'operator@chargetrackr.local' },
  { label: 'Technician', email: 'technician@chargetrackr.local' },
  { label: 'Client', email: 'client@chargetrackr.local' },
]

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
  const [form] = Form.useForm<LoginFormValues>()
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

  function fillDemoAccount(email: string) {
    form.setFieldsValue({ email, password: 'password' })
  }

  function handleGoogleLogin() {
    setErrorMessage(null)
    window.location.assign(`${backendUrl}/auth/oauth/google/redirect`)
  }

  return (
    <main className="login-page">
      <section className="login-hero">
        <div className="brand-lockup">
          <div className="brand-symbol">
            <Zap size={26} />
          </div>
          <div>
            <Typography.Title level={3}>ChargeTrackr</Typography.Title>
            <Typography.Text>EV charging availability supervision</Typography.Text>
          </div>
        </div>

        <Typography.Title level={1}>Monitor. Manage. Power the future.</Typography.Title>
        <Typography.Paragraph>
          Connect operators, technicians, administrators and clients around the same charging
          network workspace.
        </Typography.Paragraph>
      </section>

      <Card className="login-card">
        <Typography.Title level={3}>Sign in</Typography.Title>
        <Typography.Paragraph type="secondary">
          Access your ChargeTrackr workspace securely.
        </Typography.Paragraph>

        {(errorMessage || oauthErrorMessage) && (
          <Alert
            className="login-alert"
            type="error"
            message={errorMessage ?? oauthErrorMessage}
            showIcon
          />
        )}

        <Button className="google-sign-in-button" block onClick={handleGoogleLogin}>
          <span className="google-logo-frame" aria-hidden="true">
            <img src="/assets/google.png" alt="" />
          </span>
          Continue with Google
        </Button>

        <Divider plain>or use your email</Divider>

        <Form form={form} layout="vertical" onFinish={handleSubmit} initialValues={{ password: 'password' }}>
          <Form.Item
            label="Email"
            name="email"
            rules={[{ required: true, message: 'Email is required' }, { type: 'email' }]}
          >
            <Input placeholder="operator@chargetrackr.local" autoComplete="email" />
          </Form.Item>

          <Form.Item
            label="Password"
            name="password"
            rules={[{ required: true, message: 'Password is required' }]}
          >
            <Input.Password placeholder="password" autoComplete="current-password" />
          </Form.Item>

          <Button type="primary" htmlType="submit" loading={isSubmitting} block>
            Sign in
          </Button>
        </Form>

        <Divider>Demo accounts</Divider>

        <Space wrap>
          {demoAccounts.map((account) => (
            <Tag.CheckableTag
              key={account.email}
              checked={form.getFieldValue('email') === account.email}
              onChange={() => fillDemoAccount(account.email)}
            >
              {account.label}
            </Tag.CheckableTag>
          ))}
        </Space>
      </Card>
    </main>
  )
}
