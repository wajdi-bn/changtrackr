import { Button, Collapse, Tag } from 'antd'
import type { LucideIcon } from 'lucide-react'
import {
  BellRing,
  BookOpenCheck,
  Building2,
  Cable,
  CircleDollarSign,
  ClipboardCheck,
  FileText,
  LifeBuoy,
  MapPinned,
  PlayCircle,
  PlugZap,
  ReceiptText,
  ShieldCheck,
  Users,
  Wrench,
} from 'lucide-react'
import { useNavigate } from 'react-router-dom'
import { MountainBanner } from '../components/MountainBanner'
import { useAuth } from '../features/auth/useAuth'
import type { UserRole } from '../types/auth'

interface HelpTopic {
  title: string
  description: string
  path: string
  action: string
  icon: LucideIcon
}

const topicsByRole: Record<UserRole, HelpTopic[]> = {
  super_admin: [
    { title: 'Provision an organization', description: 'Review a demo request, create the tenant, then issue the administrator invitation.', path: '/demo-requests', action: 'Open demo requests', icon: Building2 },
    { title: 'Control platform access', description: 'Review fixed roles, server-enforced permissions and global account assignments.', path: '/roles-permissions', action: 'Review permissions', icon: ShieldCheck },
    { title: 'Check integrations', description: 'Inspect Google OAuth, email, payment sandbox and OCPP gateway readiness.', path: '/integrations', action: 'View integrations', icon: PlugZap },
    { title: 'Audit sensitive actions', description: 'Trace platform changes by actor, organization, action and date.', path: '/audit-logs', action: 'Open audit trail', icon: FileText },
  ],
  admin: [
    { title: 'Build your organization team', description: 'Invite operators and technicians, then follow their activation status.', path: '/users/employees', action: 'Manage employees', icon: Users },
    { title: 'Prepare the charging network', description: 'Create stations, connectors, pricing and the documents technicians need.', path: '/stations', action: 'Open stations', icon: PlugZap },
    { title: 'Control business rules', description: 'Configure tariffs, plans and organization subscription limits.', path: '/tariffs', action: 'Review pricing', icon: CircleDollarSign },
    { title: 'Review organization reports', description: 'Analyze business, workforce and network performance and exchange reports.', path: '/analytics-reports', action: 'Open reports', icon: ReceiptText },
  ],
  operator: [
    { title: 'Supervise live stations', description: 'Read calculated availability, OCPP connectivity and connector state.', path: '/stations', action: 'Open station inventory', icon: Cable },
    { title: 'Resolve operational alerts', description: 'Qualify alerts, assign technicians and follow SLA-sensitive incidents.', path: '/alerts', action: 'Review alerts', icon: BellRing },
    { title: 'Coordinate maintenance', description: 'Plan recurring maintenance and monitor current work orders.', path: '/maintenance', action: 'Open maintenance', icon: Wrench },
    { title: 'Send a shift handover', description: 'Compose a protected report with supporting documents for another employee.', path: '/reports', action: 'Open handovers', icon: ClipboardCheck },
  ],
  technician: [
    { title: 'Review assigned work', description: 'Open your assigned interventions and understand the station context before travel.', path: '/my-interventions', action: 'View interventions', icon: Wrench },
    { title: 'Inspect station context', description: 'Consult connectors, OCPP history, maintenance records and station documents.', path: '/stations', action: 'Browse stations', icon: PlugZap },
    { title: 'Complete field evidence', description: 'Record diagnosis, work performed, before/after photos and supporting files.', path: '/my-interventions', action: 'Open field workflow', icon: ClipboardCheck },
    { title: 'Exchange field reports', description: 'Send detailed findings and attachments to operators or administrators.', path: '/maintenance-reports', action: 'Open reports', icon: FileText },
  ],
  client: [
    { title: 'Find a compatible station', description: 'Use map, cards or table view and filter by distance, connector and power.', path: '/find-station', action: 'Find a station', icon: MapPinned },
    { title: 'Start charging safely', description: 'Choose a connector, plug in physically, authorize payment and follow the live session.', path: '/find-station', action: 'Start the workflow', icon: PlayCircle },
    { title: 'Manage payments', description: 'Settle completed sessions and preview or download traceable PDF receipts.', path: '/payments', action: 'Open payments', icon: ReceiptText },
    { title: 'Choose a charging plan', description: 'Compare organization plans, discounts and current memberships.', path: '/subscriptions', action: 'Review plans', icon: CircleDollarSign },
  ],
}

const roleTitles: Record<UserRole, { title: string; subtitle: string; color: 'green' | 'purple' | 'orange' | 'blue' }> = {
  super_admin: { title: 'Platform administration help', subtitle: 'Provision, govern and audit the ChargeTrackr platform with clear operational controls.', color: 'orange' },
  admin: { title: 'Organization administration help', subtitle: 'Set up your workforce, charging assets, pricing and business reporting.', color: 'green' },
  operator: { title: 'Operations help center', subtitle: 'Supervise station availability, incidents, maintenance and shift coordination.', color: 'blue' },
  technician: { title: 'Field technician help', subtitle: 'Prepare interventions, capture reliable evidence and submit complete field reports.', color: 'orange' },
  client: { title: 'Driver help center', subtitle: 'Find a station, complete the physical charging workflow and manage your receipts.', color: 'purple' },
}

export function HelpPage() {
  const { primaryRole, user } = useAuth()
  const navigate = useNavigate()
  const role = primaryRole ?? 'client'
  const page = roleTitles[role]
  const topics = topicsByRole[role]

  return (
    <div className="help-page">
      <MountainBanner color={page.color} breadcrumb={['Workspace', 'Help']} title={page.title} subtitle={page.subtitle} />
      <section className="help-welcome">
        <div>
          <span><LifeBuoy size={22} /></span>
          <div><small>PERSONALIZED FOR {role.replace('_', ' ').toUpperCase()}</small><h2>What do you need to accomplish, {firstName(user?.name)}?</h2><p>Start from a real task. Each destination keeps your organization scope and permissions enforced.</p></div>
        </div>
        <Button icon={<BookOpenCheck size={16} />} onClick={() => navigate('/welcome')}>Open workspace guide</Button>
      </section>

      <section className="help-topic-grid">
        {topics.map(({ title, description, path, action, icon: Icon }, index) => <article key={title}>
          <header><span><Icon size={20} /></span><Tag>{String(index + 1).padStart(2, '0')}</Tag></header>
          <h3>{title}</h3>
          <p>{description}</p>
          <button onClick={() => navigate(path)}>{action}<span>→</span></button>
        </article>)}
      </section>

      <div className="help-lower-grid">
        <section className="help-faq">
          <header><div><BookOpenCheck size={19} /><span><h2>Common questions</h2><p>Short answers for the workflows that require physical or financial context.</p></span></div></header>
          <Collapse
            ghost
            items={faqForRole(role).map((item, index) => ({ key: index, label: item.question, children: <p>{item.answer}</p> }))}
          />
        </section>
        <aside className="help-support">
          <span><ShieldCheck size={24} /></span>
          <strong className="help-support-badge"><ShieldCheck size={13} />Protected workspace</strong>
          <h2>Still blocked?</h2>
          <p>Include the page, record reference, expected action and visible error. Never send passwords, OAuth secrets, payment credentials or OCPP station secrets.</p>
          <div><strong>Recommended evidence</strong><span>Screenshot · timestamp · station or report reference</span></div>
          <Button type="primary" href="mailto:support@chargetrackr.local?subject=ChargeTrackr%20support%20request">Contact support</Button>
        </aside>
      </div>
    </div>
  )
}

function firstName(name?: string): string {
  return name?.trim().split(/\s+/)[0] ?? 'there'
}

function faqForRole(role: UserRole): Array<{ question: string; answer: string }> {
  const shared = [
    { question: 'Why can I see a record but not modify it?', answer: 'Viewing and management are separate server permissions. Organization scope, assigned technician rules, report ownership and record state can make a page read-only.' },
    { question: 'Where are uploaded documents stored?', answer: 'Operational documents are stored privately. The application checks your current authorization before every preview or download; no permanent public file URL is exposed.' },
  ]
  const specific: Record<UserRole, Array<{ question: string; answer: string }>> = {
    super_admin: [{ question: 'Can a Super Admin access every organization?', answer: 'Yes for platform governance, but each action remains logged. Organization administrators and employees remain restricted to their own tenant.' }],
    admin: [{ question: 'Who can upload station documents?', answer: 'Organization administrators and operators with station update permission can upload and remove them. A document may optionally be marked visible to clients.' }],
    operator: [{ question: 'What makes a station unavailable?', answer: 'Availability is projected from OCPP status, recent heartbeats, connector state and explicit maintenance or disabled overrides. It is not only a manually stored label.' }],
    technician: [{ question: 'What is the difference between evidence photos and attachments?', answer: 'Before and after photos prove field execution and are required by the guided report. Attachments are supporting files such as diagnostics, work orders or supplier documents.' }],
    client: [{ question: 'When does charging actually start?', answer: 'After the cable is physically detected, payment is authorized and the station accepts the remote OCPP start command. The live session begins only after the station confirms it.' }],
  }

  return [...specific[role], ...shared]
}
