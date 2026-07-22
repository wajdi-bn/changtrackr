import { Alert, Card, Tag } from 'antd'
import type { ReactNode } from 'react'
import { CheckCircle2, KeyRound, Mail, PlugZap, Settings2, ShieldCheck, UsersRound } from 'lucide-react'
import { MountainBanner } from '../components/MountainBanner'

type Section = 'roles' | 'integrations' | 'settings'

const roleRows = [
  ['Super Administrator', 'Platform governance, organizations, demo provisioning and audit history', 'Platform-wide'],
  ['Organization Administrator', 'Users, tariffs, assets and organization reporting', 'One organization'],
  ['Operator', 'Station supervision, sessions and operational alerts', 'One organization'],
  ['Technician', 'Assigned alerts, interventions and maintenance reporting', 'One organization'],
  ['Client', 'Public stations, charging sessions, vehicles and subscriptions', 'Personal account'],
]

export function PlatformControlPage({ section }: { section: Section }) {
  if (section === 'roles') return <div className="platform-control-page"><MountainBanner color="purple" breadcrumb={['Super Admin', 'Governance', 'Roles']} title="Roles & permissions" subtitle="A clear access model, separated by organization and platform scope." /><Card className="platform-control-card" title="Access matrix" extra={<Tag color="purple">Least privilege</Tag>}><div className="platform-role-list">{roleRows.map(([role, scope, boundary]) => <article key={role}><span><ShieldCheck size={19} /></span><div><strong>{role}</strong><p>{scope}</p></div><Tag color={boundary === 'Platform-wide' ? 'purple' : 'green'}>{boundary}</Tag></article>)}</div><Alert type="info" showIcon title="Role changes are controlled" description="Organization employees are assigned to exactly one active organization. Super administrators do not belong to an organization." /></Card></div>
  if (section === 'integrations') return <div className="platform-control-page"><MountainBanner color="teal" breadcrumb={['Super Admin', 'Platform', 'Integrations']} title="Integrations" subtitle="Technical connections used by authentication, notifications, simulated payments and charging stations." /><div className="platform-integration-grid"><Integration icon={<KeyRound size={20} />} name="Google OAuth" detail="Sign-in provider" state="Configured" /><Integration icon={<Mail size={20} />} name="Transactional email" detail="Resend delivery channel" state="Configured" /><Integration icon={<PlugZap size={20} />} name="OCPP gateway" detail="OCPP 1.6 JSON simulator" state="Local environment" /><Integration icon={<CheckCircle2 size={20} />} name="Payment adapter" detail="Webhook-compatible simulation" state="Sandbox" /></div><Alert type="warning" showIcon title="Credentials are never displayed here" description="Client secrets, API keys and callback signatures remain in environment variables and are not returned by the application." /></div>
  return <div className="platform-control-page"><MountainBanner color="gold" breadcrumb={['Super Admin', 'Platform', 'System settings']} title="System settings" subtitle="Read-only platform controls and environment safeguards for the current MVP." /><Card className="platform-control-card" title="Platform safeguards"><div className="platform-safeguard-grid"><Safeguard icon={<UsersRound size={18} />} title="Tenant isolation" description="Organization employees and assets remain scoped to one organization." /><Safeguard icon={<ShieldCheck size={18} />} title="Authorization" description="Routes enforce role and permission checks server-side." /><Safeguard icon={<Settings2 size={18} />} title="Background processing" description="Queues process notifications, OCPP events and scheduled checks." /></div><Alert type="info" showIcon title="Configuration ownership" description="Runtime variables are managed from the deployment environment. This page deliberately does not edit .env values from the browser." /></Card></div>
}

function Integration({ icon, name, detail, state }: { icon: ReactNode; name: string; detail: string; state: string }) { return <Card><span className="platform-integration-icon">{icon}</span><strong>{name}</strong><p>{detail}</p><Tag color="green">{state}</Tag></Card> }
function Safeguard({ icon, title, description }: { icon: ReactNode; title: string; description: string }) { return <article><span>{icon}</span><strong>{title}</strong><p>{description}</p></article> }
