import { useEffect, useRef, useState } from 'react'
import { App, Button, Card, Drawer, Form, Input, Select } from 'antd'
import {
  Activity,
  ArrowUpRight,
  BatteryCharging,
  Gauge,
  Menu as MenuIcon,
  ShieldCheck,
  X,
} from 'lucide-react'
import gsap from 'gsap'
import { ScrollTrigger } from 'gsap/ScrollTrigger'
import { Link, useNavigate } from 'react-router-dom'

const navLinks = [
  { label: 'Network', href: '#network' },
  { label: 'Operations', href: '#operations' },
  { label: 'Reports', href: '#reports' },
  { label: 'Updates', href: '#updates' },
  { label: 'Contact', href: '#demo' },
]

const collageTiles = [
  { src: '/assets/charge-hero.png', alt: 'Electric vehicle charging beside a fast charger', className: 'collage-one' },
  { src: '/assets/ev-charging-hub.png', alt: 'Fast EV charging hub with green charger lights', className: 'collage-two' },
  { src: '/assets/ev-operations-desk.png', alt: 'EV charging network operations dashboard', className: 'collage-three' },
  { src: '/assets/ev-route-corridor.png', alt: 'EV charging corridor at dusk', className: 'collage-four' },
  { src: '/assets/ev-technician.png', alt: 'Technician inspecting an EV fast charger', className: 'collage-five' },
  { src: '/assets/ev-charging-hub.png', alt: 'Mediterranean EV charging station', className: 'collage-six' },
]

const operationCards = [
  {
    icon: Activity,
    label: 'Live supervision',
    copy: 'Track heartbeat health, connector state, charging load, and uptime from a single operational view.',
  },
  {
    icon: ShieldCheck,
    label: 'Faster intervention',
    copy: 'Prioritize faulted connectors, offline stations, maintenance windows, and assigned technician actions.',
  },
  {
    icon: Gauge,
    label: 'Network insight',
    copy: 'Turn sessions, utilization, revenue, and avoided CO2 into export-ready reports for operators.',
  },
]

const updates = [
  {
    image: '/assets/ev-charging-hub.png',
    label: 'Network operations',
    title: 'Lac 1 Fast Hub keeps 99.4% uptime across morning commuter demand.',
  },
  {
    image: '/assets/ev-route-corridor.png',
    label: 'Energy impact',
    title: 'Delivered energy reaches 24.5 MWh while station availability stays above target.',
  },
  {
    image: '/assets/ev-operations-desk.png',
    label: 'City rollout',
    title: 'Tunisia coverage dashboard expands live views across coastal and airport stations.',
  },
]

const footerLinks = ['Overview', 'Stations', 'Map', 'Alerts', 'Sessions', 'Reports']

interface DemoFormValues {
  name: string
  email: string
  company: string
  phone?: string
  topic: string
  message: string
}

export function LandingPage() {
  const rootRef = useRef<HTMLDivElement>(null)
  const [compactNav, setCompactNav] = useState(false)
  const [pastHero, setPastHero] = useState(false)
  const [mobileMenuOpen, setMobileMenuOpen] = useState(false)
  const navigate = useNavigate()
  const { message } = App.useApp()

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

      gsap.utils.toArray<HTMLElement>('.landing-collage-image').forEach((element, index) => {
        gsap.from(element, {
          opacity: 0,
          y: 75,
          duration: 0.7,
          delay: index * 0.06,
          ease: 'power3.out',
          scrollTrigger: { trigger: '.landing-collage', start: 'top 72%', once: true },
        })
      })
    }, rootRef)

    return () => context.revert()
  }, [])

  function submitDemo(values: DemoFormValues) {
    void message.success(`Thank you ${values.name}. Your demo request has been recorded.`)
  }

  function openDashboard() {
    navigate('/login')
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
            <Button type="primary" shape="round" onClick={() => document.querySelector('#demo')?.scrollIntoView({ behavior: 'smooth' })}>
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
          <Button type="primary" onClick={openDashboard}>Open dashboard</Button>
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

        <section id="network" className="landing-section landing-about">
          <div data-reveal>
            <p className="landing-section-label">About ChargeTrackr</p>
            <img src="/assets/ev-technician.png" alt="Technician inspecting an EV charging station" />
          </div>
          <div data-reveal>
            <h2>We design visibility for charging networks that have to stay available, profitable, and ready for the next driver.</h2>
            <p>From heartbeat monitoring to payment follow-up, ChargeTrackr turns daily station operations into a clear, executive-ready operating picture.</p>
            <Button onClick={openDashboard}>Learn more about us <ArrowUpRight size={15} /></Button>
          </div>
        </section>

        <section className="landing-section landing-collage" data-reveal>
          <h2>A network built for every route.</h2>
          <div className="landing-collage-canvas">
            {collageTiles.map((tile) => (
              <img key={`${tile.src}-${tile.className}`} src={tile.src} alt={tile.alt} className={`landing-collage-image ${tile.className}`} />
            ))}
            <Button onClick={() => navigate('/login')}>Explore stations <ArrowUpRight size={15} /></Button>
          </div>
        </section>

        <section id="reports" className="landing-report-section">
          <div className="landing-report-panel" data-reveal>
            <img src="/assets/ev-technician.png" alt="Technician inspecting an EV fast charger" />
            <div className="landing-report-body">
              <div>
                <p>Operational intelligence</p>
                <h2>2026<br />Network Report</h2>
              </div>
              <div className="landing-report-bottom">
                <p>A complete view of station availability, delivered energy, revenue, incidents, and field actions across the ChargeTrackr network.</p>
                <button type="button" onClick={openDashboard}>
                  <small>.PDF</small>
                  <span>Read the report <ArrowUpRight size={18} /></span>
                </button>
              </div>
            </div>
          </div>
        </section>

        <section id="operations" className="landing-section landing-operations">
          <h2 data-reveal>One network, shared visibility, faster resolution.</h2>
          <div data-reveal>
            <p>Our operating layer helps teams see what changed, what needs attention, and where the next charging session can start.</p>
            <Button onClick={openDashboard}>Explore active operations <ArrowUpRight size={15} /></Button>
          </div>
        </section>

        <section className="landing-snapshot">
          <div className="landing-section landing-snapshot-grid" data-reveal>
            <div className="landing-snapshot-intro">
              <div>
                <h2>Network Snapshot</h2>
                <p>Live prototype numbers drawn from the same operating dataset used in the dashboard.</p>
              </div>
              <img src="/assets/ev-operations-desk.png" alt="EV charging network dashboard" />
              <Button onClick={openDashboard}>Open overview <ArrowUpRight size={15} /></Button>
            </div>
            <div className="landing-snapshot-score">
              <div className="landing-snapshot-heading"><span>ChargeTrackr network</span><span>Tunisia</span></div>
              <strong>98.7%</strong>
              <div className="landing-metrics">
                <Metric label="Stations" value="1,248" />
                <Metric label="Active sites" value="956" />
                <Metric label="Energy" value="24.5 MWh" />
                <Metric label="Critical alerts" value="5" />
              </div>
            </div>
          </div>
        </section>

        <section className="landing-section landing-modules">
          <div className="landing-section-heading" data-reveal>
            <div><p className="landing-section-label">Platform modules</p><h2>Built for EV station operations</h2></div>
            <Button type="link" onClick={openDashboard}>View all <ArrowUpRight size={15} /></Button>
          </div>
          <div className="landing-module-grid">
            {operationCards.map((card) => (
              <Card key={card.label} className="landing-module-card" data-reveal>
                <card.icon size={22} />
                <h3>{card.label}</h3>
                <p>{card.copy}</p>
              </Card>
            ))}
          </div>
        </section>

        <section id="updates" className="landing-section landing-updates">
          <div className="landing-section-heading" data-reveal>
            <h2>Latest Updates</h2>
            <Button type="link" onClick={openDashboard}>View all <ArrowUpRight size={15} /></Button>
          </div>
          <div className="landing-updates-grid">
            {updates.map((item) => (
              <article key={item.title} data-reveal>
                <img src={item.image} alt="" />
                <small>{item.label}</small>
                <h3>{item.title}</h3>
              </article>
            ))}
          </div>
        </section>

        <section id="demo" className="landing-demo">
          <div className="landing-section landing-demo-grid" data-reveal>
            <div>
              <p className="landing-section-label">Contact</p>
              <h2>Request a ChargeTrackr demo</h2>
              <p>Tell us about your charging network and we will prepare a guided walkthrough of the operator, technician, administrator, and client workflows.</p>
              <Card size="small" title="Sales contact">demo@chargetrackr.tn</Card>
              <Card size="small" title="Typical response">One business day for prototype demo requests.</Card>
            </div>

            <Card className="landing-demo-card">
              <Form<DemoFormValues> layout="vertical" onFinish={submitDemo} requiredMark={false}>
                <div className="landing-form-grid">
                  <Form.Item label="Full name" name="name" rules={[{ required: true, message: 'Please enter your name' }]}>
                    <Input placeholder="Your name" />
                  </Form.Item>
                  <Form.Item label="Work email" name="email" rules={[{ required: true, type: 'email', message: 'Enter a valid work email' }]}>
                    <Input placeholder="name@company.com" />
                  </Form.Item>
                  <Form.Item label="Company" name="company" rules={[{ required: true, message: 'Please enter your company' }]}>
                    <Input placeholder="Charging operator or fleet" />
                  </Form.Item>
                  <Form.Item label="Phone" name="phone"><Input placeholder="+216 ..." /></Form.Item>
                  <Form.Item className="landing-form-wide" label="What do you want to demo?" name="topic" initialValue="platform">
                    <Select options={[
                      { value: 'platform', label: 'Full ChargeTrackr platform' },
                      { value: 'operator', label: 'Operator supervision' },
                      { value: 'technician', label: 'Technician workflows' },
                      { value: 'client', label: 'Client charging experience' },
                      { value: 'admin', label: 'Administrator controls' },
                    ]} />
                  </Form.Item>
                  <Form.Item className="landing-form-wide" label="Message" name="message" rules={[{ required: true, message: 'Tell us what you would like to see' }]}>
                    <Input.TextArea rows={4} placeholder="Tell us about your stations, users, or demo goals." />
                  </Form.Item>
                </div>
                <Button type="primary" htmlType="submit">Send demo request <ArrowUpRight size={15} /></Button>
              </Form>
            </Card>
          </div>
        </section>

        <section className="landing-final-cta">
          <img src="/assets/ev-route-corridor.png" alt="EV charging corridor at dusk" />
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
                <button key={link} type="button" onClick={openDashboard}>{link}<ArrowUpRight size={18} /></button>
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

function Metric({ label, value }: { label: string; value: string }) {
  return <div><small>{label}</small><strong>{value}</strong></div>
}

function FooterColumn({ title, links }: { title: string; links: string[] }) {
  return <div><strong>{title}</strong>{links.map((link) => <span key={link}>{link}</span>)}</div>
}
