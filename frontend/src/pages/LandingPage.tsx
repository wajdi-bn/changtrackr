import { useEffect, useRef, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { App, Button, Card, Checkbox, Drawer, Form, Input, InputNumber, Segmented, Select, Skeleton } from 'antd'
import {
  Activity,
  ArrowUpRight,
  BatteryCharging,
  BellRing,
  Building2,
  CarFront,
  Check,
  CircleDollarSign,
  ClipboardCheck,
  CreditCard,
  FileText,
  Gauge,
  MapPinned,
  Menu as MenuIcon,
  RadioTower,
  ReceiptText,
  ShieldCheck,
  Users,
  Wrench,
  X,
  Zap,
} from 'lucide-react'
import gsap from 'gsap'
import { ScrollTrigger } from 'gsap/ScrollTrigger'
import { Link, useNavigate } from 'react-router-dom'
import { getApiErrorMessage, getApiErrorStatus, getApiValidationErrors } from '../api/apiErrors'
import { IconSurface, type IconSurfaceTone } from '../components/IconSurface'
import { submitDemoRequest } from '../features/demoRequests/demoRequestApi'
import { demoObjectiveOptions } from '../features/demoRequests/demoRequestOptions'
import { getPublicSaasPlans, type PublicSaasPlan } from '../features/commercial/publicCommercialApi'
import type { PublicDemoRequestPayload } from '../types/demoRequest'
import { formatLandingPlanLimit, getLandingPlanPrice, type LandingBillingCycle } from './landingPlanPricing'

const navLinks = [
  { label: 'Product', href: '#product' },
  { label: 'Workflow', href: '#workflow' },
  { label: 'Workspaces', href: '#workspaces' },
  { label: 'Pricing', href: '#pricing' },
  { label: 'Demo', href: '#demo' },
]

const platformFacts = [
  { value: 'OCPP 1.6J', label: 'Gateway and simulated station fleet' },
  { value: 'Live rules', label: 'Availability derived from station signals' },
  { value: '5 workspaces', label: 'Access tailored to each responsibility' },
  { value: 'Traceable', label: 'Payments, actions, reports and audit history' },
]

const productStories = [
  {
    icon: RadioTower,
    eyebrow: 'Network supervision',
    title: 'From station signal to a trustworthy availability state.',
    copy: 'The OCPP gateway receives boot, heartbeat, connector, transaction and meter events. ChargeTrackr applies business rules before updating maps, dashboards and alerts.',
    image: '/assets/landing/ocpp-supervision.webp',
    alt: 'Charging network operations team monitoring station status',
    points: ['Heartbeat and connectivity monitoring', 'Connector-level status and fault context', 'Remote commands with a complete history'],
  },
  {
    icon: Wrench,
    eyebrow: 'Field operations',
    title: 'Turn operational attention into an assigned, documented response.',
    copy: 'Operators qualify alerts and coordinate work. Technicians receive focused assignments, attach evidence, record actions and hand a verified report back to the organization.',
    image: '/assets/ev-technician.png',
    alt: 'Technician inspecting an electric vehicle charging station',
    points: ['Priorities, assignments and SLA follow-up', 'Maintenance calendar and intervention evidence', 'Role-specific handovers and internal reports'],
  },
  {
    icon: CreditCard,
    eyebrow: 'Driver experience',
    title: 'Guide every charging session from discovery to receipt.',
    copy: 'Drivers find an available station, select a compatible connector, follow the physical connection steps, authorize payment and monitor the live OCPP session until completion.',
    image: '/assets/landing/driver-checkout.webp',
    alt: 'Electric vehicle charging at a modern public hub',
    points: ['Map, route and connector compatibility', 'Guided charging target and payment authorization', 'Live energy, cost, completion and PDF receipt'],
  },
]

const roleWorkspaces = [
  {
    key: 'platform',
    label: 'Platform',
    eyebrow: 'Super Administrator',
    title: 'Govern organizations and platform-wide controls.',
    copy: 'Review demo requests, provision organization administrators, manage commercial plans, inspect integrations, permissions, audit trails and global settings.',
    image: '/assets/landing/platform-governance.webp',
    icon: Building2,
    features: ['Organization lifecycle', 'Commercial catalog', 'Platform audit and integrations'],
  },
  {
    key: 'admin',
    label: 'Administrator',
    eyebrow: 'Organization Administrator',
    title: 'Control one organization, its people and charging assets.',
    copy: 'Manage employees and customers within the organization boundary, commission stations, define tariffs, follow billing and turn operational data into business decisions.',
    image: '/assets/charge-hero.png',
    icon: Users,
    features: ['Organization workforce', 'Stations and pricing', 'Business reports and billing'],
  },
  {
    key: 'operator',
    label: 'Operator',
    eyebrow: 'Network Operator',
    title: 'Keep the charging network visible and actionable.',
    copy: 'Watch the live map, diagnose alerts, use authorized OCPP commands, assign interventions and prepare clear shift handovers without crossing organization boundaries.',
    image: '/assets/ev-route-corridor.png',
    icon: Activity,
    features: ['Live station map', 'Alerts and remote actions', 'Shift reporting'],
  },
  {
    key: 'technician',
    label: 'Technician',
    eyebrow: 'Field Technician',
    title: 'Work from an assigned field queue, not a generic dashboard.',
    copy: 'Consult station context, execute interventions and maintenance tasks, document before-and-after evidence and submit a structured technical outcome.',
    image: '/assets/landing/field-technician.webp',
    icon: Wrench,
    features: ['Assigned interventions', 'Maintenance execution', 'Evidence and field reports'],
  },
  {
    key: 'driver',
    label: 'Driver',
    eyebrow: 'Client / Driver',
    title: 'Find, charge, pay and keep every receipt in one place.',
    copy: 'Use the public station map, scan a connector QR code, follow a guided charging workflow, monitor the current session and manage network memberships.',
    image: '/assets/landing/driver-station-finder.webp',
    icon: CarFront,
    features: ['Station discovery', 'Guided live charging', 'Payments and memberships'],
  },
]

const operationalFlow = [
  { icon: RadioTower, title: 'Station signal', copy: 'Boot, heartbeat, status and meter events arrive through OCPP.' },
  { icon: Gauge, title: 'Availability', copy: 'Rules calculate a usable state instead of trusting a stale database value.' },
  { icon: BellRing, title: 'Response', copy: 'Alerts, interventions and maintenance coordinate human action.' },
  { icon: Zap, title: 'Charging', copy: 'The driver journey links connector, target, authorization and live session.' },
  { icon: ReceiptText, title: 'Evidence', copy: 'Receipts, reports and audit history preserve the operational result.' },
]

const capabilityCards: Array<{
  icon: typeof MapPinned
  title: string
  copy: string
  tone: IconSurfaceTone
}> = [
  { icon: MapPinned, tone: 'teal', title: 'Map and station catalog', copy: 'Filter availability, inspect connectors, open directions and commission new charging assets.' },
  { icon: Activity, tone: 'brand', title: 'Live OCPP supervision', copy: 'Follow heartbeats, connector events, transactions and authorized remote commands.' },
  { icon: ClipboardCheck, tone: 'amber', title: 'Alerts and field work', copy: 'Move from operational alert to assigned intervention, maintenance and verified closure.' },
  { icon: CircleDollarSign, tone: 'graphite', title: 'Tariffs and payments', copy: 'Apply station pricing, charging plans, payment authorization and traceable settlement.' },
  { icon: FileText, tone: 'blue', title: 'Role-specific reporting', copy: 'Use focused analytics, internal report exchange and branded CSV, JSON or PDF exports.' },
  { icon: ShieldCheck, tone: 'red', title: 'Scoped access', copy: 'Separate platform governance, organization assets, field duties and driver data.' },
]

const fallbackPlans: PublicSaasPlan[] = [
  { name: 'Starter', code: 'STARTER', description: 'Essential supervision for a small charging network.', monthly_price_millimes: 149000, annual_price_millimes: 1490000, max_stations: 5, max_employees: 5, features: ['Live station monitoring', 'Alerts and interventions', 'Standard reports'], is_featured: false },
  { name: 'Business', code: 'BUSINESS', description: 'Operations, maintenance and analytics for a growing network.', monthly_price_millimes: 399000, annual_price_millimes: 3990000, max_stations: 50, max_employees: 25, features: ['Everything in Starter', 'Remote OCPP operations', 'Advanced analytics and exports', 'Priority support'], is_featured: true },
  { name: 'Enterprise', code: 'ENTERPRISE', description: 'Governance and unlimited scale for large charging portfolios.', monthly_price_millimes: 999000, annual_price_millimes: 9990000, max_stations: null, max_employees: null, features: ['Everything in Business', 'Unlimited stations and employees', 'Custom onboarding', 'Dedicated support'], is_featured: false },
]

const footerLinks = [
  { label: 'Product', href: '#product' },
  { label: 'Workflow', href: '#workflow' },
  { label: 'Workspaces', href: '#workspaces' },
  { label: 'Pricing', href: '#pricing' },
  { label: 'Request a demo', href: '#demo' },
]

const demoRequestFieldNames = new Set<keyof PublicDemoRequestPayload>([
  'full_name',
  'email',
  'company_name',
  'phone',
  'objectives',
  'estimated_stations',
  'message',
  'consent_accepted',
])

export function LandingPage() {
  const rootRef = useRef<HTMLDivElement>(null)
  const workspaceRef = useRef<HTMLElement>(null)
  const [compactNav, setCompactNav] = useState(false)
  const [pastHero, setPastHero] = useState(false)
  const [mobileMenuOpen, setMobileMenuOpen] = useState(false)
  const [demoSubmitting, setDemoSubmitting] = useState(false)
  const [workspaceKey, setWorkspaceKey] = useState('operator')
  const [billingCycle, setBillingCycle] = useState<LandingBillingCycle>('monthly')
  const demoSubmittingRef = useRef(false)
  const [demoForm] = Form.useForm<PublicDemoRequestPayload>()
  const navigate = useNavigate()
  const { message } = App.useApp()
  const plansQuery = useQuery({
    queryKey: ['public-commercial-plans'],
    queryFn: getPublicSaasPlans,
    staleTime: 10 * 60 * 1000,
    retry: 1,
  })
  const plans = plansQuery.data?.length ? plansQuery.data : fallbackPlans
  const activeWorkspace = roleWorkspaces.find((workspace) => workspace.key === workspaceKey) ?? roleWorkspaces[2]!

  useEffect(() => {
    const handleScroll = () => {
      const scrollY = window.scrollY
      setCompactNav(scrollY > 40)
      setPastHero(scrollY > Math.max(420, window.innerHeight - 120))
    }

    handleScroll()
    window.addEventListener('scroll', handleScroll, { passive: true })
    window.addEventListener('resize', handleScroll)

    return () => {
      window.removeEventListener('scroll', handleScroll)
      window.removeEventListener('resize', handleScroll)
    }
  }, [])

  useEffect(() => {
    if (!rootRef.current) return

    gsap.registerPlugin(ScrollTrigger)
    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches
    if (reduceMotion) return

    const context = gsap.context(() => {
      gsap.from('.landing-hero-copy > *', {
        opacity: 0,
        y: 34,
        duration: 0.8,
        stagger: 0.11,
        ease: 'power3.out',
      })

      gsap.from('.landing-report-float', {
        opacity: 0,
        x: 36,
        duration: 0.9,
        delay: 0.25,
        ease: 'power3.out',
      })

      gsap.utils.toArray<HTMLElement>('[data-reveal]').forEach((element) => {
        gsap.from(element, {
          opacity: 0,
          y: 42,
          duration: 0.75,
          ease: 'power3.out',
          scrollTrigger: { trigger: element, start: 'top 86%', once: true },
        })
      })

      gsap.utils.toArray<HTMLElement>('[data-reveal-group]').forEach((group) => {
        gsap.from(Array.from(group.children), {
          opacity: 0,
          y: 28,
          duration: 0.65,
          stagger: 0.09,
          ease: 'power3.out',
          scrollTrigger: { trigger: group, start: 'top 84%', once: true },
        })
      })

      gsap.utils.toArray<HTMLElement>('[data-parallax]').forEach((element) => {
        gsap.fromTo(element, { yPercent: -3 }, {
          yPercent: 3,
          ease: 'none',
          scrollTrigger: { trigger: element, start: 'top bottom', end: 'bottom top', scrub: 0.8 },
        })
      })

      gsap.from('.landing-flow-progress', {
        scaleX: 0,
        transformOrigin: 'left center',
        ease: 'none',
        scrollTrigger: { trigger: '.landing-flow-list', start: 'top 82%', end: 'bottom 65%', scrub: 0.6 },
      })
    }, rootRef)

    return () => context.revert()
  }, [])

  useEffect(() => {
    if (!workspaceRef.current || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return

    const targets = workspaceRef.current.querySelectorAll('[data-workspace-content]')
    gsap.fromTo(targets, { opacity: 0, y: 14 }, { opacity: 1, y: 0, duration: 0.42, stagger: 0.05, ease: 'power2.out' })
  }, [workspaceKey])

  async function submitDemo(values: PublicDemoRequestPayload) {
    if (demoSubmittingRef.current) return
    demoSubmittingRef.current = true
    setDemoSubmitting(true)
    try {
      const result = await submitDemoRequest(values)
      demoForm.resetFields()
      void message.success(`Request ${result.reference} was recorded. Our platform team will contact you shortly.`)
    } catch (error) {
      if (getApiErrorStatus(error) === 422) {
        const errors = getApiValidationErrors(error)
        const fields = Object.entries(errors).flatMap(([path, messages]) => {
          const name = path.split('.')[0] as keyof PublicDemoRequestPayload
          return demoRequestFieldNames.has(name) ? [{ name, errors: messages }] : []
        })

        demoForm.setFields(fields)
        void message.error(getApiErrorMessage(error, 'Check the highlighted fields and try again.'))
      } else {
        void message.error(getApiErrorMessage(error, 'The demo request could not be submitted. Please try again later.'))
      }
    } finally {
      demoSubmittingRef.current = false
      setDemoSubmitting(false)
    }
  }

  function openDashboard() {
    navigate('/login')
  }

  function scrollToDemo() {
    document.querySelector('#demo')?.scrollIntoView({ behavior: 'smooth' })
  }

  return (
    <div ref={rootRef} className="landing-page">
      <header className={`landing-header ${compactNav ? 'is-compact' : ''} ${pastHero ? 'is-green' : ''}`}>
        <div className="landing-nav-shell">
          <Link to="/" className="landing-brand" aria-label="ChargeTrackr home">
            <img src="/assets/Logo.png" alt="" />
            <span>ChargeTrackr</span>
          </Link>

          <nav className="landing-desktop-nav" aria-label="Main navigation">
            {navLinks.map((item) => (
              <a key={item.href} href={item.href}>{item.label}</a>
            ))}
          </nav>

          <div className="landing-nav-actions">
            <Button type="text" className="landing-sign-in" onClick={() => navigate('/login')}>Sign in</Button>
            <Button className="landing-register" onClick={() => navigate('/register')}>Create account</Button>
            <Button type="primary" shape="round" onClick={scrollToDemo}>
              Request a demo <ArrowUpRight size={14} />
            </Button>
            <Button
              className="landing-menu-button"
              type="text"
              icon={<MenuIcon size={19} />}
              aria-label="Open navigation"
              onClick={() => setMobileMenuOpen(true)}
            />
          </div>
        </div>
      </header>

      <Drawer
        open={mobileMenuOpen}
        onClose={() => setMobileMenuOpen(false)}
        size={360}
        closeIcon={<X size={20} />}
        title="ChargeTrackr"
      >
        <nav className="landing-mobile-nav" aria-label="Mobile navigation">
          {navLinks.map((item) => (
            <a key={item.href} href={item.href} onClick={() => setMobileMenuOpen(false)}>{item.label}</a>
          ))}
          <Button onClick={() => navigate('/login')}>Sign in</Button>
          <Button type="primary" onClick={() => navigate('/register')}>Create client account</Button>
          <Button onClick={() => { setMobileMenuOpen(false); scrollToDemo() }}>Request an organization demo</Button>
        </nav>
      </Drawer>

      <main>
        <section className="landing-hero">
          <div className="landing-hero-media">
            <img src="/assets/ev-charging-hub.png" alt="Electric vehicle connected to a modern charging station" />
            <div className="landing-hero-overlay" />
            <div className="landing-hero-content">
              <div className="landing-hero-copy">
                <p className="landing-eyebrow"><BatteryCharging size={15} /> Real-time EV station supervision</p>
                <h1>Make.<br />Every Day.<br />Better.</h1>
                <p className="landing-hero-description">
                  ChargeTrackr gives operators one calm place to watch station availability, connector health, sessions, payments, and field response across Tunisia.
                </p>
              </div>

              <button className="landing-report-float" type="button" onClick={openDashboard}>
                <img src="/assets/ev-operations-desk.png" alt="EV charging operations dashboard" />
                <span className="landing-report-copy">
                  <span><small>Latest network brief</small><strong>2026 Operations Report</strong></span>
                  <span className="landing-circle-arrow"><ArrowUpRight size={16} /></span>
                </span>
              </button>
            </div>
          </div>
        </section>

        <section id="product" className="landing-section landing-about">
          <div data-reveal>
            <p className="landing-section-label">One operating system</p>
            <img src="/assets/landing/team-coordination.webp" alt="Charging network team coordinating field operations" />
          </div>
          <div data-reveal>
            <h2>See the station, coordinate the team, and guide the driver from the same source of truth.</h2>
            <p>ChargeTrackr connects charging-station signals to availability rules, operational response, driver sessions, payment records and decision-ready reporting. Each person sees the tools that match their responsibility.</p>
            <div className="landing-about-actions">
              <Button onClick={() => document.querySelector('#workflow')?.scrollIntoView({ behavior: 'smooth' })}>See the operating flow <ArrowUpRight size={15} /></Button>
              <Button type="text" onClick={openDashboard}>Sign in to a workspace</Button>
            </div>
          </div>
        </section>

        <section className="landing-proof" aria-label="Platform foundations">
          <div className="landing-section landing-proof-grid" data-reveal-group>
            {platformFacts.map((fact) => (
              <div key={fact.value}>
                <strong>{fact.value}</strong>
                <span>{fact.label}</span>
              </div>
            ))}
          </div>
        </section>

        <section className="landing-section landing-product-stories">
          <header className="landing-narrative-heading" data-reveal>
            <p className="landing-section-label">Connected workflows</p>
            <h2>Not another static dashboard.</h2>
            <p>The platform carries verified information from the charging station to the person who must act on it.</p>
          </header>
          <div className="landing-product-story-list">
            {productStories.map((story, index) => (
              <article key={story.title} className={index % 2 === 1 ? 'is-reversed' : ''} data-reveal>
                <figure className="landing-product-visual">
                  <img src={story.image} alt={story.alt} data-parallax />
                  <span><story.icon size={19} />{story.eyebrow}</span>
                </figure>
                <div className="landing-product-copy">
                  <small>0{index + 1}</small>
                  <h3>{story.title}</h3>
                  <p>{story.copy}</p>
                  <ul>{story.points.map((point) => <li key={point}><Check size={15} />{point}</li>)}</ul>
                </div>
              </article>
            ))}
          </div>
        </section>

        <section id="workflow" className="landing-flow-section">
          <div className="landing-section">
            <header className="landing-narrative-heading landing-narrative-heading--light" data-reveal>
              <p className="landing-section-label">A complete operational chain</p>
              <h2>Every event should lead somewhere useful.</h2>
              <p>ChargeTrackr preserves the connection between machine state, human response, charging activity and business evidence.</p>
            </header>
            <div className="landing-flow-list" data-reveal-group>
              <span className="landing-flow-progress" aria-hidden="true" />
              {operationalFlow.map((step, index) => (
                <article key={step.title}>
                  <span><step.icon size={19} /></span>
                  <small>0{index + 1}</small>
                  <h3>{step.title}</h3>
                  <p>{step.copy}</p>
                </article>
              ))}
            </div>
          </div>
        </section>

        <section id="workspaces" ref={workspaceRef} className="landing-section landing-workspaces">
          <header className="landing-narrative-heading" data-reveal>
            <p className="landing-section-label">Built around responsibility</p>
            <h2>One platform. Five focused workspaces.</h2>
            <p>Navigation, metrics and actions change with the role. Data access remains scoped to the platform or organization boundary.</p>
          </header>
          <Segmented
            block
            className="landing-role-selector"
            value={workspaceKey}
            onChange={(value) => setWorkspaceKey(String(value))}
            options={roleWorkspaces.map((workspace) => ({ label: workspace.label, value: workspace.key }))}
          />
          <Select
            className="landing-role-select"
            aria-label="Choose a workspace"
            value={workspaceKey}
            onChange={(value) => setWorkspaceKey(value)}
            options={roleWorkspaces.map((workspace) => ({ label: workspace.label, value: workspace.key }))}
          />
          <div className="landing-workspace-stage">
            <div className="landing-workspace-copy" data-workspace-content>
              <span><activeWorkspace.icon size={19} />{activeWorkspace.eyebrow}</span>
              <h3>{activeWorkspace.title}</h3>
              <p>{activeWorkspace.copy}</p>
              <ul>{activeWorkspace.features.map((feature) => <li key={feature}><Check size={15} />{feature}</li>)}</ul>
              <Button onClick={openDashboard}>Open workspace <ArrowUpRight size={15} /></Button>
            </div>
            <figure data-workspace-content>
              <img src={activeWorkspace.image} alt={`${activeWorkspace.label} ChargeTrackr workspace context`} />
              <figcaption><span>ROLE</span><strong>{activeWorkspace.label}</strong><small>Scoped access and role-specific decisions</small></figcaption>
            </figure>
          </div>
        </section>

        <section id="reports" className="landing-report-section">
          <div className="landing-report-panel" data-reveal>
            <img src="/assets/landing/operations-reporting.webp" alt="Role-specific charging network reports" />
            <div className="landing-report-body">
              <div>
                <p>Operational intelligence</p>
                <h2>Evidence,<br />not noise.</h2>
              </div>
              <div className="landing-report-bottom">
                <p>Administrators, operators and technicians receive different analytics and report templates. Teams can exchange reports with attachments and export readable CSV, JSON or branded PDF documents.</p>
                <button type="button" onClick={openDashboard}>
                  <small>REPORTING</small>
                  <span>Open reports <ArrowUpRight size={18} /></span>
                </button>
              </div>
            </div>
          </div>
        </section>

        <section className="landing-section landing-capabilities">
          <header className="landing-narrative-heading" data-reveal>
            <p className="landing-section-label">Platform capabilities</p>
            <h2>The workflows teams expect are already connected.</h2>
          </header>
          <div className="landing-capability-grid" data-reveal-group>
            {capabilityCards.map((capability) => (
              <article key={capability.title}>
                <IconSurface tone={capability.tone} size="large"><capability.icon size={20} /></IconSurface>
                <h3>{capability.title}</h3>
                <p>{capability.copy}</p>
              </article>
            ))}
          </div>
        </section>

        <section id="pricing" className="landing-pricing-section">
          <div className="landing-section">
            <header className="landing-pricing-heading" data-reveal>
              <div>
                <p className="landing-section-label">Organization plans</p>
                <h2>Start with a 14-day evaluation workspace.</h2>
                <p>After evaluation, the organization administrator requests the capacity that fits the network. Driver charging memberships remain separate and are configured by each organization.</p>
              </div>
              <Segmented
                value={billingCycle}
                onChange={(value) => setBillingCycle(value as LandingBillingCycle)}
                options={[{ label: 'Monthly', value: 'monthly' }, { label: 'Annual', value: 'annual' }]}
              />
            </header>
            {plansQuery.isLoading && !plansQuery.data ? (
              <div className="landing-plan-grid">{Array.from({ length: 3 }, (_, index) => <Skeleton key={index} active />)}</div>
            ) : (
              <div className="landing-plan-grid" data-reveal-group>
                {plans.map((plan) => {
                  const price = getLandingPlanPrice(plan, billingCycle)
                  return (
                    <article key={plan.code} className={plan.is_featured ? 'is-featured' : ''}>
                      <header>
                        <span>{plan.code}</span>
                        {plan.is_featured && <b>Most popular</b>}
                      </header>
                      <h3>{plan.name}</h3>
                      <p>{plan.description}</p>
                      <div className="landing-plan-price">
                        <strong>{price.amount.toLocaleString('en-US', { maximumFractionDigits: 3 })}</strong>
                        <span>TND / {price.period}</span>
                        {price.monthlyEquivalent !== null && <small>{price.monthlyEquivalent.toLocaleString('en-US', { maximumFractionDigits: 3 })} TND monthly equivalent</small>}
                      </div>
                      <div className="landing-plan-capacity">
                        <span><RadioTower size={14} />{formatLandingPlanLimit(plan.max_stations, 'stations')}</span>
                        <span><Users size={14} />{formatLandingPlanLimit(plan.max_employees, 'employees')}</span>
                      </div>
                      <ul>{plan.features.map((feature) => <li key={feature}><Check size={14} />{feature}</li>)}</ul>
                      <Button type={plan.is_featured ? 'primary' : 'default'} onClick={scrollToDemo}>Request this plan <ArrowUpRight size={14} /></Button>
                    </article>
                  )
                })}
              </div>
            )}
            {plansQuery.isError && <p className="landing-pricing-fallback">The standard catalog is shown while live pricing reconnects.</p>}
          </div>
        </section>

        <section id="demo" className="landing-demo">
          <div className="landing-section landing-demo-grid" data-reveal>
            <div>
              <p className="landing-section-label">Contact</p>
              <h2>Request an organization demo workspace</h2>
              <p>Tell us what your charging network needs. After review, the organization administrator receives a secure invitation and can later create operator and technician accounts.</p>
              <Card size="small" title="Sales contact">demo@chargetrackr.tn</Card>
              <Card size="small" title="Typical response">One business day for prototype demo requests.</Card>
            </div>

            <Card className="landing-demo-card">
              <Form<PublicDemoRequestPayload>
                form={demoForm}
                layout="vertical"
                validateTrigger="onBlur"
                onFinish={submitDemo}
                requiredMark={false}
                initialValues={{ objectives: ['availability_monitoring'], consent_accepted: false }}
              >
                <div className="landing-form-grid">
                  <Form.Item label="Full name" name="full_name" rules={[{ required: true, message: 'Please enter your name' }, { min: 2, message: 'Enter at least 2 characters' }, { max: 120, message: 'Use no more than 120 characters' }]}>
                    <Input maxLength={120} placeholder="Your name" />
                  </Form.Item>
                  <Form.Item label="Work email" name="email" rules={[{ required: true, type: 'email', message: 'Enter a valid work email' }, { max: 255, message: 'Use no more than 255 characters' }]}>
                    <Input maxLength={255} placeholder="name@company.com" />
                  </Form.Item>
                  <Form.Item label="Company" name="company_name" rules={[{ required: true, message: 'Please enter your company' }, { min: 2, message: 'Enter at least 2 characters' }, { max: 160, message: 'Use no more than 160 characters' }]}>
                    <Input maxLength={160} placeholder="Charging operator or fleet" />
                  </Form.Item>
                  <Form.Item label="Phone" name="phone" rules={[{ max: 40, message: 'Use no more than 40 characters' }]}><Input maxLength={40} placeholder="+216 ..." /></Form.Item>
                  <Form.Item label="Estimated stations" name="estimated_stations">
                    <InputNumber min={1} max={100000} placeholder="24" />
                  </Form.Item>
                  <Form.Item className="landing-form-wide" label="Main objectives (up to 3)" name="objectives" rules={[{ required: true, type: 'array', min: 1, max: 3, message: 'Select between one and three objectives' }]}>
                    <Select mode="multiple" maxCount={3} showSearch optionFilterProp="label" placeholder="Select the problems you want to solve" options={demoObjectiveOptions} />
                  </Form.Item>
                  <Form.Item className="landing-form-wide" label="Message" name="message" rules={[{ required: true, message: 'Tell us what you would like to see' }, { min: 20, message: 'Describe your needs in at least 20 characters' }, { max: 5000, message: 'Use no more than 5,000 characters' }]}>
                    <Input.TextArea rows={4} maxLength={5000} showCount placeholder="Tell us about your stations, users, or demo goals." />
                  </Form.Item>
                  <Form.Item className="landing-form-wide landing-demo-consent" name="consent_accepted" valuePropName="checked" rules={[{ validator: (_, value) => value ? Promise.resolve() : Promise.reject(new Error('Consent is required')) }]}>
                    <Checkbox>I agree to be contacted about this demo request.</Checkbox>
                  </Form.Item>
                  <Form.Item className="landing-honeypot" name="website"><Input tabIndex={-1} autoComplete="off" /></Form.Item>
                </div>
                <Button type="primary" htmlType="submit" loading={demoSubmitting}>Send demo request <ArrowUpRight size={15} /></Button>
              </Form>
            </Card>
          </div>
        </section>

        <section className="landing-final-cta">
          <img src="/assets/landing/coastal-charging-plaza.webp" alt="Public electric vehicle charging plaza on the Tunisian coast" />
          <div data-reveal>
            <h2>Operate Your Charging Future With Us</h2>
            <p>Monitor stations, resolve incidents, follow payments, and report impact from one focused EV operations workspace.</p>
            <Button onClick={openDashboard}>Work in ChargeTrackr <ArrowUpRight size={15} /></Button>
          </div>
        </section>
      </main>

      <footer className="landing-footer">
        <div className="landing-section landing-footer-grid">
          <div>
            <div className="landing-footer-brand"><img src="/assets/Logo.png" alt="" /><span>ChargeTrackr</span></div>
            <p>EV station supervision for operators who need every connector, session, alert, and report in one reliable workspace.</p>
          </div>
          <div>
            <nav>
              {footerLinks.map((link) => (
                <a key={link.href} href={link.href}>{link.label}<ArrowUpRight size={18} /></a>
              ))}
            </nav>
            <div className="landing-footer-columns">
              <FooterColumn title="Network" links={['Tunis', 'La Marsa', 'Sousse', 'Sfax']} />
              <FooterColumn title="Operations" links={['Alerts', 'Interventions', 'Payments', 'Users']} />
              <FooterColumn title="Platform" links={['Internship MVP', 'Dashboard', 'Reports', 'Settings']} />
            </div>
          </div>
        </div>
      </footer>
    </div>
  )
}

function FooterColumn({ title, links }: { title: string; links: string[] }) {
  return <div><strong>{title}</strong>{links.map((link) => <span key={link}>{link}</span>)}</div>
}
