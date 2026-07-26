import { Alert, Button, Form, Input } from 'antd'
import { ArrowLeft, LockKeyhole } from 'lucide-react'
import { useState } from 'react'
import { Link, useLocation } from 'react-router-dom'
import { AuthPageShell } from '../features/auth/AuthPageShell'
import { getAuthErrorMessage, resetPasswordRequest } from '../features/auth/authApi'

interface ResetFormValues {
  password: string
  password_confirmation: string
}

export function ResetPasswordPage() {
  const location = useLocation()
  const params = new URLSearchParams(location.search)
  const token = params.get('token')
  const email = params.get('email')
  const [isComplete, setIsComplete] = useState(false)
  const [errorMessage, setErrorMessage] = useState<string | null>(null)
  const [isSubmitting, setIsSubmitting] = useState(false)

  async function handleSubmit(values: ResetFormValues) {
    if (!token || !email) return
    setErrorMessage(null)
    setIsSubmitting(true)

    try {
      await resetPasswordRequest({ token, email, ...values })
      setIsComplete(true)
    } catch (error) {
      setErrorMessage(getAuthErrorMessage(error, 'The reset link is invalid or has expired.'))
    } finally {
      setIsSubmitting(false)
    }
  }

  return (
    <AuthPageShell
      eyebrow="Secure account recovery"
      title="Choose a new password and continue charging."
      description="Reset links are short-lived and can only be used once to protect access to your client account."
    >
      <header className="prototype-login-card-heading">
        <h1>Create a new password</h1>
        <p>{email ? `Resetting access for ${email}.` : 'The reset link is incomplete.'}</p>
      </header>

      {!token || !email ? (
        <div className="prototype-auth-state">
          <Alert
            type="error"
            showIcon
            title="Invalid reset link"
            description="Request a new password reset email to continue."
          />
          <Link className="prototype-auth-primary-link" to="/forgot-password">Request a new link</Link>
        </div>
      ) : isComplete ? (
        <div className="prototype-auth-state">
          <Alert
            type="success"
            showIcon
            title="Password updated"
            description="Your new password is active and the reset link can no longer be used."
          />
          <Link className="prototype-auth-primary-link" to="/login">Continue to sign in</Link>
        </div>
      ) : (
        <Form<ResetFormValues>
          className="prototype-login-form"
          layout="vertical"
          validateTrigger="onBlur"
          requiredMark={false}
          onFinish={handleSubmit}
        >
          <Form.Item
            label="New password"
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
            Update password
          </Button>

          <Link className="prototype-auth-back-link" to="/login">
            <ArrowLeft size={15} /> Return to sign in
          </Link>
        </Form>
      )}
    </AuthPageShell>
  )
}
