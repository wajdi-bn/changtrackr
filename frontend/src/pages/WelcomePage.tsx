import { useMutation } from '@tanstack/react-query'
import { App, Avatar, Button, Form, Input, Progress, Steps, Upload } from 'antd'
import {
  AlertTriangle,
  ArrowLeft,
  ArrowRight,
  Building2,
  Check,
  Clock3,
  MapPinned,
  PlugZap,
  ShieldCheck,
  Sparkles,
  UploadCloud,
  Wrench,
} from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import { useEffect, useMemo, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { getApiErrorMessage } from '../api/apiErrors'
import { getRoleConfig } from '../features/auth/roleConfig'
import { useAuth } from '../features/auth/useAuth'
import {
  updateOnboarding,
  updateOnboardingOrganization,
  uploadOnboardingOrganizationLogo,
  type OrganizationOnboardingPayload,
} from '../features/onboarding/onboardingApi'
import type { UserRole } from '../types/auth'

interface RoleGuide {
  welcome: string
  mission: string
  firstWinTitle: string
  firstWinDescription: string
  firstWinIcon: LucideIcon
  successSignals: string[]
  nextAction: string
}

const roleGuides: Record<UserRole, RoleGuide> = {
  super_admin: {
    welcome: 'Your platform control room is ready.',
    mission: 'Govern organization access and platform health without entering tenant operations.',
    firstWinTitle: 'Provision one organization correctly',
    firstWinDescription: 'Start from a qualified demo request, verify the commercial plan, then issue one secure administrator invitation.',
    firstWinIcon: Building2,
    successSignals: ['The organization has an active commercial lifecycle.', 'Its administrator invitation is valid and auditable.'],
    nextAction: 'Open Organizations and verify the first tenant workspace.',
  },
  admin: {
    welcome: 'Let us prepare your organization workspace.',
    mission: 'Give your team a recognizable workspace before adding people and charging assets.',
    firstWinTitle: 'Prepare the organization for its first station',
    firstWinDescription: 'Confirm the business identity now. Inside the workspace, the product tour will point to employee invitations and station onboarding.',
    firstWinIcon: PlugZap,
    successSignals: ['Your team recognizes the organization identity.', 'The next action is inviting an operator or technician.'],
    nextAction: 'Invite the first employee, then register the first station.',
  },
  operator: {
    welcome: 'Your live operations workspace is ready.',
    mission: 'Use verified station signals to identify and coordinate the next operational action.',
    firstWinTitle: 'Identify the station that needs attention',
    firstWinDescription: 'Begin with current connectivity and availability, then open the related alert instead of scanning every module.',
    firstWinIcon: AlertTriangle,
    successSignals: ['You know which station changed state.', 'The issue is acknowledged or assigned with context.'],
    nextAction: 'Open Stations, then follow the contextual alert when action is required.',
  },
  technician: {
    welcome: 'Your field workspace is ready.',
    mission: 'Turn assigned work into a traceable intervention with clear evidence.',
    firstWinTitle: 'Complete one assigned intervention',
    firstWinDescription: 'Confirm the station, priority and SLA before travelling, then record diagnosis and before/after evidence.',
    firstWinIcon: Wrench,
    successSignals: ['The intervention follows its expected status sequence.', 'The report contains enough evidence for review.'],
    nextAction: 'Open My Alerts and select the highest-priority assignment.',
  },
  client: {
    welcome: 'Your charging companion is ready.',
    mission: 'Reach an available compatible connector and follow the session with confidence.',
    firstWinTitle: 'Start one charging session successfully',
    firstWinDescription: 'Find a nearby station, choose a compatible available connector and follow the guided connection steps.',
    firstWinIcon: MapPinned,
    successSignals: ['The chosen connector is available and compatible.', 'The live session shows energy, time and estimated cost.'],
    nextAction: 'Open Find Station and choose the most practical available connector.',
  },
}

const stepIds = ['welcome', 'first-win', 'ready']

export function WelcomePage() {
  const { message } = App.useApp()
  const { user, primaryRole, updateCurrentUser } = useAuth()
  const navigate = useNavigate()
  const role = primaryRole ?? 'client'
  const guide = roleGuides[role]
  const savedStep = Math.min(user?.onboarding.progress.current_step ?? 0, stepIds.length - 1)
  const [currentStep, setCurrentStep] = useState(savedStep)
  const [organizationForm] = Form.useForm<OrganizationOnboardingPayload>()
  const [organizationSaved, setOrganizationSaved] = useState(false)
  const defaultPath = getRoleConfig(role).defaultPath
  const reviewingCompletedGuide = Boolean(user?.onboarding.completed)

  useEffect(() => {
    window.scrollTo({ top: 0, behavior: 'smooth' })
  }, [currentStep])

  const progressMutation = useMutation({
    mutationFn: updateOnboarding,
    onSuccess: updateCurrentUser,
    onError: (error) => void message.error(getApiErrorMessage(error, 'Your setup progress could not be saved.')),
  })
  const organizationMutation = useMutation({
    mutationFn: updateOnboardingOrganization,
    onSuccess: (nextUser) => {
      updateCurrentUser(nextUser)
      setOrganizationSaved(true)
      void message.success('Organization details saved.')
    },
    onError: (error) => void message.error(getApiErrorMessage(error, 'Organization details could not be saved.')),
  })
  const logoMutation = useMutation({
    mutationFn: uploadOnboardingOrganizationLogo,
    onSuccess: (nextUser) => {
      updateCurrentUser(nextUser)
      void message.success('Organization logo updated.')
    },
    onError: (error) => void message.error(getApiErrorMessage(error, 'Organization logo could not be uploaded.')),
  })

  const completedSteps = useMemo(
    () => stepIds.slice(0, Math.max(currentStep, 0)),
    [currentStep],
  )

  function saveProgress(nextStep: number) {
    setCurrentStep(nextStep)
    progressMutation.mutate({
      action: 'progress',
      current_step: nextStep,
      completed_steps: stepIds.slice(0, nextStep),
    })
  }

  function dismiss() {
    progressMutation.mutate({
      action: 'dismiss',
      current_step: currentStep,
      completed_steps: completedSteps,
    }, {
      onSuccess: (nextUser) => {
        updateCurrentUser(nextUser)
        navigate(defaultPath, { replace: true })
      },
    })
  }

  function complete() {
    progressMutation.mutate({
      action: 'complete',
      current_step: stepIds.length - 1,
      completed_steps: stepIds,
      tour_completed: false,
    }, {
      onSuccess: (nextUser) => {
        updateCurrentUser(nextUser)
        void message.success('Welcome to your ChargeTrackr workspace.')
        navigate(defaultPath, { replace: true })
      },
    })
  }

  return (
    <main className="onboarding-page">
      <aside className="onboarding-rail">
        <Link className="onboarding-brand" to={defaultPath} aria-label="ChargeTrackr">
          <img src="/assets/Logo.png" alt="" />
          <span>ChargeTrackr</span>
        </Link>

        <div className="onboarding-rail-copy">
          <span>{getRoleConfig(role).label}</span>
          <h1>{guide.welcome}</h1>
          <p>{guide.mission}</p>
        </div>

        <Steps
          direction="vertical"
          current={currentStep}
          items={[
            { title: 'Welcome', description: 'Your goal and access' },
            { title: 'First win', description: role === 'admin' ? 'Confirm organization identity' : 'Focus on one useful result' },
            { title: 'Enter workspace', description: 'Continue with contextual guidance' },
          ]}
        />

        <button
          type="button"
          className="onboarding-later"
          onClick={reviewingCompletedGuide ? () => navigate(defaultPath) : dismiss}
          disabled={progressMutation.isPending}
        >
          {reviewingCompletedGuide ? 'Return to workspace' : 'Skip for now'}
        </button>
      </aside>

      <section className="onboarding-content">
        <header className="onboarding-topbar">
          <div>
            <span>Personalized setup</span>
            <strong>Step {currentStep + 1} of {stepIds.length}</strong>
          </div>
          <Progress percent={Math.round(((currentStep + 1) / stepIds.length) * 100)} showInfo={false} />
          <span>{user?.name}</span>
        </header>

        <div className="onboarding-stage">
          {currentStep === 0 && (
            <section className="onboarding-welcome">
              <span className="onboarding-hero-icon"><Sparkles size={28} /></span>
              <p className="onboarding-kicker">Welcome, {user?.name?.split(' ')[0]}</p>
              <h2>Reach your first useful result, not a tour of every feature.</h2>
              <p>This short setup is tailored to your role. After it, three contextual tips will appear inside the real workspace exactly when you need them.</p>
              <div className="onboarding-duration"><Clock3 size={16} /><span>About 2 minutes</span><i />You can skip and return from your account menu</div>
              <div className="onboarding-access-summary">
                <span><ShieldCheck size={18} /></span>
                <div><strong>{getRoleConfig(role).label}</strong><small>{user?.organization?.name ?? (role === 'client' ? 'Independent driver account' : 'Platform scope')}</small></div>
                <Check size={18} />
              </div>
            </section>
          )}

          {currentStep === 1 && role !== 'admin' && <FirstWinPanel guide={guide} />}

          {currentStep === 1 && role === 'admin' && user?.organization && (
            <section className="onboarding-organization">
              <div className="onboarding-section-heading">
                <p className="onboarding-kicker">One useful setup action</p>
                <h2>Make the workspace recognizable to your team.</h2>
                <p>Only the organization identity is requested now. Employees, stations and tariffs remain contextual actions inside the application.</p>
              </div>
              <div className="onboarding-organization-layout">
                <div className="onboarding-logo-field">
                  <Avatar size={92} shape="square" src={user.organization.logo_url ?? undefined}>
                    {user.organization.name.slice(0, 2).toUpperCase()}
                  </Avatar>
                  <Upload
                    accept="image/jpeg,image/png,image/webp"
                    showUploadList={false}
                    beforeUpload={(file) => {
                      if (file.size > 2 * 1024 * 1024) {
                        void message.error('Choose an image smaller than 2 MB.')
                        return Upload.LIST_IGNORE
                      }
                      logoMutation.mutate(file)
                      return Upload.LIST_IGNORE
                    }}
                  >
                    <Button icon={<UploadCloud size={16} />} loading={logoMutation.isPending}>Upload logo</Button>
                  </Upload>
                  <small>Optional. PNG, JPG or WebP, up to 2 MB.</small>
                </div>
                <Form<OrganizationOnboardingPayload>
                  form={organizationForm}
                  layout="vertical"
                  validateTrigger="onBlur"
                  initialValues={{
                    name: user.organization.name,
                    contact_email: user.organization.contact_email ?? user.email,
                    contact_phone: user.organization.contact_phone ?? '',
                  }}
                  onFinish={(values) => organizationMutation.mutate(values)}
                >
                  <Form.Item name="name" label="Organization name" rules={[{ required: true, min: 2, message: 'Enter the organization name.' }]}>
                    <Input />
                  </Form.Item>
                  <Form.Item name="contact_email" label="Business contact email" rules={[{ type: 'email', message: 'Enter a valid email address.' }]}>
                    <Input type="email" />
                  </Form.Item>
                  <Form.Item name="contact_phone" label="Business phone">
                    <Input type="tel" placeholder="+216 ..." />
                  </Form.Item>
                  <Button type="primary" htmlType="submit" loading={organizationMutation.isPending}>
                    {organizationSaved ? 'Details saved' : 'Save organization details'}
                  </Button>
                </Form>
              </div>
            </section>
          )}

          {currentStep === 2 && (
            <section className="onboarding-ready">
              <span className="onboarding-ready-mark"><Check size={34} /></span>
              <p className="onboarding-kicker">Ready for the first win</p>
              <h2>Continue inside the actual workspace.</h2>
              <p>The guide will now highlight only three relevant controls. It will not block the rest of the application, and you can close it at any time.</p>
              <div className="onboarding-next-action">
                <span><guide.firstWinIcon size={21} /></span>
                <div><small>Recommended next action</small><strong>{guide.nextAction}</strong></div>
              </div>
            </section>
          )}
        </div>

        <footer className="onboarding-actions">
          <Button
            icon={<ArrowLeft size={16} />}
            disabled={currentStep === 0 || progressMutation.isPending}
            onClick={() => saveProgress(currentStep - 1)}
          >
            Back
          </Button>
          {currentStep < stepIds.length - 1 ? (
            <Button type="primary" onClick={() => saveProgress(currentStep + 1)}>
              Continue <ArrowRight size={16} />
            </Button>
          ) : (
            <Button
              type="primary"
              loading={progressMutation.isPending}
              onClick={reviewingCompletedGuide ? () => navigate(defaultPath) : complete}
            >
              {reviewingCompletedGuide ? 'Return to workspace' : 'Show me in the workspace'} <ArrowRight size={16} />
            </Button>
          )}
        </footer>
      </section>
    </main>
  )
}

function FirstWinPanel({ guide }: { guide: RoleGuide }) {
  return (
    <section className="onboarding-first-win">
      <div className="onboarding-first-win-icon"><guide.firstWinIcon size={28} /></div>
      <p className="onboarding-kicker">Your first win</p>
      <h2>{guide.firstWinTitle}</h2>
      <p>{guide.firstWinDescription}</p>
      <div className="onboarding-success-signals">
        <span>Success means</span>
        {guide.successSignals.map((signal) => <p key={signal}><Check size={16} />{signal}</p>)}
      </div>
    </section>
  )
}
