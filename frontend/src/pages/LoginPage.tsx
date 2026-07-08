import { Alert, Button, Card, Divider, Form, Input, Space, Tag, Typography } from 'antd'
import { Navigate, useLocation, useNavigate } from 'react-router-dom'
import { useState } from 'react'
import { Zap } from 'lucide-react'
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

export function LoginPage() {
  const { isAuthenticated, login, primaryRole } = useAuth()
  const navigate = useNavigate()
  const location = useLocation()
  const [form] = Form.useForm<LoginFormValues>()
  const [errorMessage, setErrorMessage] = useState<string | null>(null)
  const [isSubmitting, setIsSubmitting] = useState(false)

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
          Use one of the seeded local accounts while the real OAuth flow is prepared.
        </Typography.Paragraph>

        {errorMessage && (
          <Alert className="login-alert" type="error" message={errorMessage} showIcon />
        )}

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
