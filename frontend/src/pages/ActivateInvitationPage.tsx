import { useQuery } from '@tanstack/react-query'
import { Alert, Button, Form, Input, Spin } from 'antd'
import { ArrowLeft, LockKeyhole } from 'lucide-react'
import { useRef, useState } from 'react'
import { Link, useLocation } from 'react-router-dom'
import { AuthPageShell } from '../features/auth/AuthPageShell'
import { getAuthErrorMessage } from '../features/auth/authApi'
import { acceptInvitation, inspectInvitation } from '../features/invitations/invitationApi'

interface ActivationValues {
  password: string
  password_confirmation: string
}

export function ActivateInvitationPage() {
  const location = useLocation()
  const params = new URLSearchParams(location.search)
  const token = params.get('token') ?? ''
  const email = params.get('email') ?? ''
  const [isComplete, setIsComplete] = useState(false)
  const [isSubmitting, setIsSubmitting] = useState(false)
  const submittingRef = useRef(false)
  const [errorMessage, setErrorMessage] = useState<string | null>(null)
  const invitationQuery = useQuery({
    queryKey: ['account-invitation', email, token],
    queryFn: () => inspectInvitation(email, token),
    enabled: Boolean(email && token),
    retry: false,
    refetchOnWindowFocus: false,
    staleTime: 60_000,
  })

  async function handleSubmit(values: ActivationValues) {
    if (submittingRef.current) return
    submittingRef.current = true
    setErrorMessage(null)
    setIsSubmitting(true)
    try {
      await acceptInvitation({ email, token, ...values })
      setIsComplete(true)
    } catch (error) {
      setErrorMessage(getAuthErrorMessage(error, 'This invitation is invalid, expired, or already used.'))
    } finally {
      submittingRef.current = false
      setIsSubmitting(false)
    }
  }

  const invitation = invitationQuery.data?.invitation
  const invalid = !email || !token || invitationQuery.isError || invitationQuery.data?.valid === false

  return <AuthPageShell eyebrow="Organization onboarding" title="Activate your secure ChargeTrackr workspace." description="Choose your password to access the organization prepared by the platform administrator.">
    <header className="prototype-login-card-heading"><h1>Activate your account</h1><p>{invitation ? `${invitation.organization} invited you as ${invitation.role}.` : `Complete the invitation for ${email || 'your work email'}.`}</p></header>
    {invitationQuery.isLoading ? <div className="prototype-auth-state"><Spin size="large" /></div> : invalid ? <div className="prototype-auth-state"><Alert type="error" showIcon title="Invitation unavailable" description="The link is incomplete, expired, revoked, or has already been used." /><Link className="prototype-auth-primary-link" to="/">Return to ChargeTrackr</Link></div> : isComplete ? <div className="prototype-auth-state"><Alert type="success" showIcon title="Account activated" description="Your password is set and your organization workspace is ready." /><Link className="prototype-auth-primary-link" to="/login">Continue to sign in</Link></div> : <Form<ActivationValues> className="prototype-login-form" layout="vertical" requiredMark={false} onFinish={handleSubmit}>
      <Form.Item label="New password" name="password" extra="At least 8 characters with uppercase, lowercase and a number." rules={[{ required: true, message: 'Password is required' }, { pattern: /^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).{8,}$/, message: 'Use at least 8 characters with uppercase, lowercase and a number' }]}><Input.Password prefix={<LockKeyhole size={16} />} autoComplete="new-password" placeholder="Create a strong password" /></Form.Item>
      <Form.Item label="Confirm password" name="password_confirmation" dependencies={['password']} rules={[{ required: true, message: 'Confirm your password' }, ({ getFieldValue }) => ({ validator(_, value) { return !value || getFieldValue('password') === value ? Promise.resolve() : Promise.reject(new Error('The passwords do not match')) } })]}><Input.Password prefix={<LockKeyhole size={16} />} autoComplete="new-password" placeholder="Repeat your password" /></Form.Item>
      {errorMessage && <Alert className="prototype-login-alert" type="error" showIcon title={errorMessage} />}
      <Button className="prototype-login-submit" type="primary" htmlType="submit" loading={isSubmitting} block>Activate account</Button>
      <Link className="prototype-auth-back-link" to="/"><ArrowLeft size={15} />Return to ChargeTrackr</Link>
    </Form>}
  </AuthPageShell>
}
