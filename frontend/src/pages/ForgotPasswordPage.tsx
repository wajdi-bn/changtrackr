import { Alert, Button, Form, Input } from 'antd'
import { ArrowLeft, Mail } from 'lucide-react'
import { useState } from 'react'
import { Link } from 'react-router-dom'
import { AuthPageShell } from '../features/auth/AuthPageShell'
import { forgotPasswordRequest, getAuthErrorMessage } from '../features/auth/authApi'

export function ForgotPasswordPage() {
  const [submittedEmail, setSubmittedEmail] = useState<string | null>(null)
  const [errorMessage, setErrorMessage] = useState<string | null>(null)
  const [isSubmitting, setIsSubmitting] = useState(false)

  async function handleSubmit(values: { email: string }) {
    setErrorMessage(null)
    setIsSubmitting(true)

    try {
      await forgotPasswordRequest(values.email)
      setSubmittedEmail(values.email)
    } catch (error) {
      setErrorMessage(getAuthErrorMessage(error, 'The reset request could not be completed.'))
    } finally {
      setIsSubmitting(false)
    }
  }

  return (
    <AuthPageShell
      eyebrow="Secure account recovery"
      title="Recover access without interrupting your journey."
      description="Use a short-lived reset link to restore access to your charging sessions and payments."
    >
      <header className="prototype-login-card-heading">
        <h1>Reset your password</h1>
        <p>Enter the email address associated with your account.</p>
      </header>

      {submittedEmail ? (
        <div className="prototype-auth-state">
          <Alert
            type="success"
            showIcon
            title="Check your inbox"
            description={`If an account exists for ${submittedEmail}, a password reset link has been sent.`}
          />
          <Link className="prototype-auth-back-link" to="/login">
            <ArrowLeft size={15} /> Return to sign in
          </Link>
        </div>
      ) : (
        <Form<{ email: string }>
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
            Send reset link
          </Button>

          <Link className="prototype-auth-back-link" to="/login">
            <ArrowLeft size={15} /> Return to sign in
          </Link>
        </Form>
      )}
    </AuthPageShell>
  )
}
