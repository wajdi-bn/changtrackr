import { useQuery } from '@tanstack/react-query'
import { Alert, Button, Form, Input, Spin, Upload } from 'antd'
import type { UploadFile } from 'antd'
import { ArrowLeft, BriefcaseBusiness, Building2, LockKeyhole, Phone, UploadCloud } from 'lucide-react'
import { useRef, useState } from 'react'
import { Link, useLocation } from 'react-router-dom'
import { getApiErrorMessage } from '../api/apiErrors'
import { AuthPageShell } from '../features/auth/AuthPageShell'
import { acceptInvitation, inspectInvitation } from '../features/invitations/invitationApi'

interface ActivationValues {
  password: string
  password_confirmation: string
  phone?: string
  job_title?: string
}

export function ActivateInvitationPage() {
  const location = useLocation()
  const params = new URLSearchParams(location.search)
  const token = params.get('token') ?? ''
  const email = params.get('email') ?? ''
  const [isComplete, setIsComplete] = useState(false)
  const [isSubmitting, setIsSubmitting] = useState(false)
  const [logoFiles, setLogoFiles] = useState<UploadFile[]>([])
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
      const logoFile = logoFiles[0]?.originFileObj ?? (logoFiles[0] as unknown as File | undefined)
      await acceptInvitation({
        email,
        token,
        ...values,
        organization_logo: logoFile,
      })
      setIsComplete(true)
    } catch (error) {
      setErrorMessage(getApiErrorMessage(error, 'This invitation is invalid, expired, or already used.'))
    } finally {
      submittingRef.current = false
      setIsSubmitting(false)
    }
  }

  const invitation = invitationQuery.data?.invitation
  const invalid = !email || !token || invitationQuery.isError || invitationQuery.data?.valid === false

  const isOrganizationAdministrator = invitation?.role === 'admin'

  return <AuthPageShell eyebrow="Secure account invitation" title="Activate your ChargeTrackr account." description="Set your password first, then add the optional details that make your workspace immediately recognizable.">
    <header className="prototype-login-card-heading"><h1>Activate your account</h1><p>{invitation ? `${invitation.organization} invited you as ${invitation.role}.` : `Complete the invitation for ${email || 'your work email'}.`}</p></header>
    {invitationQuery.isLoading ? <div className="prototype-auth-state"><Spin size="large" /></div> : invalid ? <div className="prototype-auth-state"><Alert type="error" showIcon title="Invitation unavailable" description="The link is incomplete, expired, cancelled, or has already been used." /><Link className="prototype-auth-primary-link" to="/">Return to ChargeTrackr</Link></div> : isComplete ? <div className="prototype-auth-state"><Alert type="success" showIcon title="Account activated" description="Your password is set. Your guided workspace setup will start after your first sign in." /><Link className="prototype-auth-primary-link" to="/login">Continue to sign in</Link></div> : <Form<ActivationValues> className="prototype-login-form" layout="vertical" validateTrigger="onBlur" requiredMark={false} onFinish={handleSubmit}>
      <Form.Item label="New password" name="password" extra="At least 8 characters with uppercase, lowercase and a number." rules={[{ required: true, message: 'Password is required' }, { pattern: /^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).{8,}$/, message: 'Use at least 8 characters with uppercase, lowercase and a number' }]}><Input.Password prefix={<LockKeyhole size={16} />} autoComplete="new-password" placeholder="Create a strong password" /></Form.Item>
      <Form.Item label="Confirm password" name="password_confirmation" dependencies={['password']} rules={[{ required: true, message: 'Confirm your password' }, ({ getFieldValue }) => ({ validator(_, value) { return !value || getFieldValue('password') === value ? Promise.resolve() : Promise.reject(new Error('The passwords do not match')) } })]}><Input.Password prefix={<LockKeyhole size={16} />} autoComplete="new-password" placeholder="Repeat your password" /></Form.Item>
      <section className="activation-optional-section">
        <header><span><BriefcaseBusiness size={17} /></span><div><strong>Optional profile details</strong><small>You can change these later from your profile.</small></div></header>
        <div className="activation-optional-grid">
          <Form.Item label="Phone number" name="phone"><Input prefix={<Phone size={15} />} autoComplete="tel" placeholder="+216 00 000 000" /></Form.Item>
          <Form.Item label="Professional title" name="job_title"><Input prefix={<BriefcaseBusiness size={15} />} autoComplete="organization-title" placeholder="e.g. Network operations manager" /></Form.Item>
        </div>
        {isOrganizationAdministrator && <div className="activation-logo-upload">
          <span><Building2 size={18} /></span>
          <div><strong>Organization logo</strong><small>PNG, JPG or WebP, up to 2 MB. It will appear across your organization workspace.</small></div>
          <Upload
            accept="image/png,image/jpeg,image/webp"
            beforeUpload={(file) => {
              setLogoFiles([file])
              return false
            }}
            fileList={logoFiles}
            maxCount={1}
            onRemove={() => {
              setLogoFiles([])
              return true
            }}
          >
            <Button icon={<UploadCloud size={15} />}>Choose logo</Button>
          </Upload>
        </div>}
      </section>
      {errorMessage && <Alert className="prototype-login-alert" type="error" showIcon title={errorMessage} />}
      <Button className="prototype-login-submit" type="primary" htmlType="submit" loading={isSubmitting} block>Activate account</Button>
      <Link className="prototype-auth-back-link" to="/"><ArrowLeft size={15} />Return to ChargeTrackr</Link>
    </Form>}
  </AuthPageShell>
}
