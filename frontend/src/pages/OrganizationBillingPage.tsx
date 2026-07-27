import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { App, Button, Progress, Radio, Table, Timeline } from 'antd'
import type { ColumnsType } from 'antd/es/table'
import { Building2, CalendarClock, Check, CircleDollarSign, Eye, FileClock, ReceiptText, ShieldCheck, Sparkles, Users, Zap } from 'lucide-react'
import dayjs from 'dayjs'
import { MountainBanner } from '../components/MountainBanner'
import { AdminDataPanel, AdminEmpty, AdminLoading, AdminMetric, AdminMetricGrid, AdminStatus } from '../components/admin/AdminSurface'
import { PdfDocumentPreviewModal, type PdfPreviewTarget } from '../features/documents/PdfDocumentPreviewModal'
import { downloadOrganizationInvoice, getOrganizationBilling, requestOrganizationPlan } from '../features/commercial/commercialApi'
import type { BillingCycle, OrganizationInvoice, SaasPlan } from '../types/commercial'

export function OrganizationBillingPage() {
  const [cycle, setCycle] = useState<BillingCycle>('monthly')
  const [invoicePreview, setInvoicePreview] = useState<PdfPreviewTarget | null>(null)
  const queryClient = useQueryClient()
  const { message, modal } = App.useApp()
  const billingQuery = useQuery({ queryKey: ['organization-billing'], queryFn: getOrganizationBilling })
  const requestMutation = useMutation({
    mutationFn: ({ plan, billingCycle }: { plan: SaasPlan; billingCycle: BillingCycle }) => requestOrganizationPlan(plan.id, billingCycle),
    onSuccess: async () => { await queryClient.invalidateQueries({ queryKey: ['organization-billing'] }); void message.success('Plan request created. The platform team can now validate the simulated payment.') },
    onError: () => void message.error('The plan request could not be created. Resolve any existing open invoice first.'),
  })
  const data = billingQuery.data
  const subscription = data?.subscription
  const deadline = subscription?.status === 'trialing' ? subscription.trial_ends_at : subscription?.status === 'grace_period' ? subscription.grace_ends_at : subscription?.current_period_ends_at
  const invoiceColumns: ColumnsType<OrganizationInvoice> = [
    { title: 'Invoice', key: 'number', render: (_, invoice) => <div className="billing-invoice-cell"><span><ReceiptText size={16} /></span><div><strong>{invoice.number}</strong><small>{invoice.plan.name} · {invoice.billing_cycle}</small></div></div> },
    { title: 'Period', key: 'period', render: (_, invoice) => <div className="admin-stack-cell"><span>{dayjs(invoice.period_starts_at).format('DD MMM YYYY')}</span><small>to {dayjs(invoice.period_ends_at).format('DD MMM YYYY')}</small></div> },
    { title: 'Amount', dataIndex: 'amount_millimes', render: (value: number) => <strong>{formatMoney(value)}</strong> },
    { title: 'Due', dataIndex: 'due_at', render: (value: string) => dayjs(value).format('DD MMM YYYY') },
    { title: 'Status', dataIndex: 'status', render: (value: string) => <AdminStatus status={value} /> },
    { title: '', align: 'right', width: 110, render: (_, invoice) => <Button type="text" icon={<Eye size={15} />} onClick={() => setInvoicePreview({ title: `Organization invoice ${invoice.number}`, filename: `organization-invoice-${invoice.number}.pdf`, load: () => downloadOrganizationInvoice(invoice.id) })}>View PDF</Button> },
  ]

  if (billingQuery.isLoading) return <div className="page-stack"><AdminLoading rows={14} /></div>
  if (billingQuery.isError || !data) return <div className="page-stack"><AdminEmpty description="Organization billing could not be loaded" actionLabel="Try again" onAction={() => void billingQuery.refetch()} /></div>

  const requestPlan = (plan: SaasPlan) => modal.confirm({
    title: `Request the ${plan.name} plan?`,
    content: <div className="billing-confirm"><p>A commercial invoice for <strong>{formatMoney(cycle === 'annual' ? plan.annual_price_millimes : plan.monthly_price_millimes)}</strong> will be created.</p><span>No real card or bank transaction occurs in this MVP.</span></div>,
    okText: 'Create request',
    onOk: () => requestMutation.mutateAsync({ plan, billingCycle: cycle }),
  })

  return <div className="page-stack organization-billing-page">
    <MountainBanner color="purple" breadcrumb={['Administrator', 'Organization', 'Subscription & billing']} title="Subscription & billing" subtitle="Follow your evaluation period, capacity, plan requests and organization invoices without mixing them with driver payments." />
    <AdminMetricGrid>
      <AdminMetric icon={Sparkles} label="Commercial status" value={subscription ? humanize(subscription.status) : 'Not configured'} helper={subscription?.plan.name ?? 'Contact platform administration'} />
      <AdminMetric icon={CalendarClock} label="Next milestone" value={deadline ? dayjs(deadline).format('DD MMM YYYY') : 'Manual review'} helper={subscription?.status === 'trialing' ? 'Trial expiration' : 'Renewal or access review'} tone="purple" />
      <AdminMetric icon={Zap} label="Stations in use" value={`${data.usage.stations} / ${data.usage.limits.stations ?? '∞'}`} helper="Organization charging assets" tone="blue" />
      <AdminMetric icon={Users} label="Employees in use" value={`${data.usage.employees} / ${data.usage.limits.employees ?? '∞'}`} helper="Operators and technicians" tone="orange" />
    </AdminMetricGrid>

    <section className="billing-overview-grid">
      <article className="billing-plan-overview"><header><span><ShieldCheck size={23} /></span><div><small>CURRENT COMMERCIAL PLAN</small><h2>{subscription?.plan.name ?? 'No plan assigned'}</h2></div>{subscription && <AdminStatus status={subscription.status} />}</header><p>{subscription?.plan.description ?? 'The platform team must configure an organization subscription.'}</p><div className="billing-cycle-facts"><span><small>Billing cycle</small><strong>{subscription?.current_period_ends_at ? humanize(subscription.billing_cycle) : 'Trial'}</strong></span><span><small>Renewal</small><strong>{subscription?.auto_renew ? 'Automatic' : 'Manual'}</strong></span><span><small>Source</small><strong>{humanize(subscription?.source ?? 'Not configured')}</strong></span></div>{subscription?.status === 'trialing' && <div className="trial-callout"><Sparkles size={18} /><div><strong>Your evaluation is active</strong><p>Up to four invited or active operators and technicians, plus the organization administrator.</p></div><b>{daysRemaining(subscription.trial_ends_at)} days left</b></div>}</article>
      <article className="billing-usage-panel"><header><div><small>PLAN CAPACITY</small><h2>Current usage</h2></div><Building2 size={21} /></header><UsageRow label="Stations" value={data.usage.stations} limit={data.usage.limits.stations} /><UsageRow label="Operators and technicians" value={data.usage.employees} limit={data.usage.limits.employees} /><div className="billing-usage-note"><ShieldCheck size={15} />The administrator account is excluded from the employee quota. Pending invitations count until cancelled.</div></article>
    </section>

    <AdminDataPanel title="Choose the next plan" subtitle="Compare real operational limits. Annual billing includes two months of savings." extra={<Radio.Group value={cycle} optionType="button" buttonStyle="solid" options={[{ value: 'monthly', label: 'Monthly' }, { value: 'annual', label: 'Annual' }]} onChange={(event) => setCycle(event.target.value)} />}>
      <div className="billing-plan-grid">{data.plans.map((plan) => <article key={plan.id} className={plan.is_featured ? 'billing-plan-card featured' : 'billing-plan-card'}>{plan.is_featured && <span className="billing-plan-badge">Recommended</span>}<small>{plan.code}</small><h3>{plan.name}</h3><p>{plan.description}</p><div className="billing-plan-price"><strong>{formatMoney(cycle === 'annual' ? plan.annual_price_millimes : plan.monthly_price_millimes)}</strong><span>/{cycle === 'annual' ? 'year' : 'month'}</span></div><div className="billing-plan-capacity"><span>{plan.max_stations ?? 'Unlimited'} stations</span><span>{plan.max_employees ?? 'Unlimited'} employees</span></div><ul>{plan.features.map((feature) => <li key={feature}><Check size={13} />{feature}</li>)}</ul><Button block type={plan.is_featured ? 'primary' : 'default'} disabled={Boolean(data.invoices.find((invoice) => ['open', 'overdue'].includes(invoice.status)))} loading={requestMutation.isPending && requestMutation.variables?.plan.id === plan.id} onClick={() => requestPlan(plan)}>{subscription?.plan.id === plan.id ? 'Renew this plan' : `Request ${plan.name}`}</Button></article>)}</div>
    </AdminDataPanel>

    <AdminDataPanel title="Commercial invoices" subtitle="Download polished PDF invoices and follow payment validation independently from charging-session receipts.">
      {data.invoices.length === 0 ? <AdminEmpty description="No organization invoice has been issued yet" /> : <Table className="admin-data-table" rowKey="id" columns={invoiceColumns} dataSource={data.invoices} pagination={{ pageSize: 8, showSizeChanger: false }} scroll={{ x: 850 }} />}
    </AdminDataPanel>

    {subscription?.events.length ? <AdminDataPanel title="Commercial history" subtitle="Traceable lifecycle decisions for this organization."><Timeline className="billing-history" items={subscription.events.slice(0, 8).map((event) => ({ color: event.to_status === 'suspended' ? 'red' : 'green', dot: event.event.includes('invoice') ? <CircleDollarSign size={15} /> : event.event.includes('trial') ? <FileClock size={15} /> : undefined, children: <div><strong>{humanize(event.event)}</strong><p>{event.note}</p><small>{event.actor} · {dayjs(event.created_at).format('DD MMM YYYY, HH:mm')}</small></div> }))} /></AdminDataPanel> : null}
    <PdfDocumentPreviewModal target={invoicePreview} onClose={() => setInvoicePreview(null)} />
  </div>
}

function UsageRow({ label, value, limit }: { label: string; value: number; limit: number | null }) { const percent = limit ? Math.min(100, Math.round((value / limit) * 100)) : 0; return <div className="billing-usage-row"><div><strong>{label}</strong><span>{value} of {limit ?? 'unlimited'}</span></div><Progress percent={limit ? percent : 100} showInfo={false} strokeColor={percent >= 90 ? '#e98a23' : '#16a36a'} trailColor="#e8efeb" /></div> }
function formatMoney(value: number): string { return new Intl.NumberFormat('en-TN', { style: 'currency', currency: 'TND', minimumFractionDigits: 3 }).format(value / 1000) }
function humanize(value: string): string { return value.replaceAll('_', ' ').replace(/^./, (character) => character.toUpperCase()) }
function daysRemaining(value: string | null): number { return value ? Math.max(0, dayjs(value).startOf('day').diff(dayjs().startOf('day'), 'day')) : 0 }
