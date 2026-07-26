import { useMutation } from '@tanstack/react-query'
import {
  App,
  Avatar,
  Button,
  Form,
  Input,
  Progress,
  Steps,
  Upload,
} from 'antd'
import {
  AlertTriangle,
  ArrowLeft,
  ArrowRight,
  BadgePercent,
  BarChart3,
  Building2,
  Check,
  CircleHelp,
  ClipboardCheck,
  CreditCard,
  LayoutDashboard,
  MapPinned,
  PlugZap,
  ShieldCheck,
  Sparkles,
  UploadCloud,
  Users,
  Wrench,
  Zap,
} from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import { useEffect, useMemo, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { getAuthErrorMessage } from '../features/auth/authApi'
import { getRoleConfig } from '../features/auth/roleConfig'
import { useAuth } from '../features/auth/useAuth'
import {
  updateOnboarding,
  updateOnboardingOrganization,
  uploadOnboardingOrganizationLogo,
  type OrganizationOnboardingPayload,
} from '../features/onboarding/onboardingApi'
import type { UserRole } from '../types/auth'

interface GuideItem {
  icon: LucideIcon
  title: string
  description: string
}

interface RoleGuide {
  welcome: string
  mission: string
  essentials: GuideItem[]
  firstActions: GuideItem[]
}

const roleGuides: Record<UserRole, RoleGuide> = {
  super_admin: {
    welcome: 'Your platform control room is ready.',
    mission: 'Govern organizations, commercial access and platform-wide security without entering tenant operations.',
    essentials: [
      { icon: Building2, title: 'Organizations', description: 'Provision, suspend and inspect isolated customer workspaces.' },
      { icon: BadgePercent, title: 'Commercial lifecycle', description: 'Follow trials, subscriptions, limits and invoices.' },
      { icon: ShieldCheck, title: 'Governance', description: 'Review permissions, audit activity and integration posture.' },
    ],
    firstActions: [
      { icon: ClipboardCheck, title: 'Review demo requests', description: 'Qualify a request before provisioning its administrator.' },
      { icon: Users, title: 'Check platform access', description: 'Confirm roles and organization assignments.' },
      { icon: BarChart3, title: 'Read platform health', description: 'Use reports to compare adoption and operational risk.' },
    ],
  },
  admin: {
    welcome: 'Let us prepare your organization workspace.',
    mission: 'Configure the network, invite the right team and define how charging activity will be priced and reported.',
    essentials: [
      { icon: Users, title: 'Employees and customers', description: 'Invite operators and technicians while keeping customers separate.' },
      { icon: PlugZap, title: 'Stations and connectors', description: 'Register organization assets and supervise their OCPP status.' },
      { icon: BadgePercent, title: 'Tariffs and billing', description: 'Define pricing rules and follow the organization subscription.' },
    ],
    firstActions: [
      { icon: Building2, title: 'Confirm organization identity', description: 'Add reliable contact details and a recognizable logo.' },
      { icon: Users, title: 'Invite your operations team', description: 'Create operator and technician invitations with scoped access.' },
      { icon: PlugZap, title: 'Onboard the first station', description: 'Add the station identity before connecting its OCPP simulator.' },
    ],
  },
  operator: {
    welcome: 'Your live operations workspace is ready.',
    mission: 'Keep stations available by combining network signals, alerts and coordinated field response.',
    essentials: [
      { icon: LayoutDashboard, title: 'Live overview', description: 'Track availability, active sessions and incidents at a glance.' },
      { icon: MapPinned, title: 'Station map', description: 'Locate affected assets and inspect connectors without changing tenant ownership.' },
      { icon: AlertTriangle, title: 'Alerts', description: 'Acknowledge issues and assign field intervention when needed.' },
    ],
    firstActions: [
      { icon: PlugZap, title: 'Check station connectivity', description: 'Review heartbeat freshness and current connector states.' },
      { icon: AlertTriangle, title: 'Triage open alerts', description: 'Prioritize critical events and add operational context.' },
      { icon: BarChart3, title: 'Prepare a handover', description: 'Send a concise shift report to the next operator or administrator.' },
    ],
  },
  technician: {
    welcome: 'Your field workspace is ready.',
    mission: 'Turn assigned alerts into traceable interventions with evidence, status updates and clear handovers.',
    essentials: [
      { icon: AlertTriangle, title: 'Assigned alerts', description: 'See only the issues that require your attention.' },
      { icon: Wrench, title: 'Interventions', description: 'Follow the expected workflow from assignment to resolution.' },
      { icon: ClipboardCheck, title: 'Maintenance reports', description: 'Record diagnosis, actions, photos and completion evidence.' },
    ],
    firstActions: [
      { icon: Wrench, title: 'Open your assignment queue', description: 'Confirm priority, station and SLA before travelling.' },
      { icon: MapPinned, title: 'Locate the station', description: 'Use station details and directions without editing the asset.' },
      { icon: ClipboardCheck, title: 'Document the result', description: 'Add before/after evidence and submit the field report.' },
    ],
  },
  client: {
    welcome: 'Your charging companion is ready.',
    mission: 'Find a compatible available connector, follow the session and keep payments in one secure account.',
    essentials: [
      { icon: MapPinned, title: 'Find a station', description: 'Compare nearby locations, availability and connector compatibility.' },
      { icon: Zap, title: 'Start charging', description: 'Follow the guided physical and digital charging steps.' },
      { icon: CreditCard, title: 'Sessions and payments', description: 'Monitor live consumption and retrieve payment receipts.' },
    ],
    firstActions: [
      { icon: MapPinned, title: 'Choose your search radius', description: 'Tune nearby results to your usual driving area in Settings.' },
      { icon: BadgePercent, title: 'Compare plans', description: 'Review organization plans before subscribing.' },
      { icon: CircleHelp, title: 'Know the charging flow', description: 'Use the guide whenever a connector or payment needs attention.' },
    ],
  },
}

const stepIds = ['welcome', 'workspace', 'setup', 'ready']

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
    onError: (error) => void message.error(getAuthErrorMessage(error, 'Your setup progress could not be saved.')),
  })
  const organizationMutation = useMutation({
    mutationFn: updateOnboardingOrganization,
    onSuccess: (nextUser) => {
      updateCurrentUser(nextUser)
      setOrganizationSaved(true)
      void message.success('Organization details saved.')
    },
    onError: (error) => void message.error(getAuthErrorMessage(error, 'Organization details could not be saved.')),
  })
  const logoMutation = useMutation({
    mutationFn: uploadOnboardingOrganizationLogo,
    onSuccess: (nextUser) => {
      updateCurrentUser(nextUser)
      void message.success('Organization logo updated.')
    },
    onError: (error) => void message.error(getAuthErrorMessage(error, 'Organization logo could not be uploaded.')),
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
            { title: 'Welcome', description: 'Your access and mission' },
            { title: 'Workspace', description: 'The tools that matter' },
            { title: role === 'admin' ? 'Organization' : 'First actions', description: 'Prepare a useful first session' },
            { title: 'Ready', description: 'Enter your workspace' },
          ]}
        />
        <button
          type="button"
          className="onboarding-later"
          onClick={reviewingCompletedGuide ? () => navigate(defaultPath) : dismiss}
          disabled={progressMutation.isPending}
        >
          {reviewingCompletedGuide ? 'Return to workspace' : 'Set up later'}
        </button>
      </aside>

      <section className="onboarding-content">
        <header className="onboarding-topbar">
          <div>
            <span>Workspace setup</span>
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
              <h2>Start with the workflow designed for your role.</h2>
              <p>ChargeTrackr keeps platform governance, organization operations, field work and driver activity clearly separated. This guide introduces only the actions available to you.</p>
              <div className="onboarding-access-summary">
                <span><ShieldCheck size={18} /></span>
                <div><strong>{getRoleConfig(role).label}</strong><small>{user?.organization?.name ?? (role === 'client' ? 'Independent driver account' : 'Platform scope')}</small></div>
                <Check size={18} />
              </div>
            </section>
          )}

          {currentStep === 1 && (
            <GuideGrid
              eyebrow="Your workspace"
              title="Three areas to understand first"
              description="These modules form the shortest path from information to action for your role."
              items={guide.essentials}
            />
          )}

          {currentStep === 2 && role !== 'admin' && (
            <GuideGrid
              eyebrow="Your first session"
              title="A practical order for your first actions"
              description="Follow this sequence once you enter the workspace. The Help center remains available from your account menu."
              items={guide.firstActions}
              numbered
            />
          )}

          {currentStep === 2 && role === 'admin' && user?.organization && (
            <section className="onboarding-organization">
              <div className="onboarding-section-heading">
                <p className="onboarding-kicker">Organization identity</p>
                <h2>Make the workspace recognizable to your team.</h2>
                <p>These business details appear in organization-facing views. They can be updated later.</p>
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
                  <small>PNG, JPG or WebP. 2 MB maximum.</small>
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

          {currentStep === 3 && (
            <section className="onboarding-ready">
              <span className="onboarding-ready-mark"><Check size={34} /></span>
              <p className="onboarding-kicker">Setup complete</p>
              <h2>Your {getRoleConfig(role).shortLabel.toLowerCase()} workspace is ready.</h2>
              <p>You can reopen this guide from your account menu. Your permissions and organization scope remain enforced by the server.</p>
              <div className="onboarding-ready-list">
                <span><Check size={16} /> Role-specific navigation prepared</span>
                <span><Check size={16} /> Secure organization scope confirmed</span>
                <span><Check size={16} /> Guided first actions available</span>
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
            <Button type="primary" loading={progressMutation.isPending} onClick={complete}>
              Enter my workspace <ArrowRight size={16} />
            </Button>
          )}
        </footer>
      </section>
    </main>
  )
}

function GuideGrid({
  eyebrow,
  title,
  description,
  items,
  numbered = false,
}: {
  eyebrow: string
  title: string
  description: string
  items: GuideItem[]
  numbered?: boolean
}) {
  return (
    <section>
      <div className="onboarding-section-heading">
        <p className="onboarding-kicker">{eyebrow}</p>
        <h2>{title}</h2>
        <p>{description}</p>
      </div>
      <div className="onboarding-guide-grid">
        {items.map((item, index) => (
          <article key={item.title}>
            <span>{numbered ? index + 1 : <item.icon size={22} />}</span>
            <div><h3>{item.title}</h3><p>{item.description}</p></div>
          </article>
        ))}
      </div>
    </section>
  )
}
