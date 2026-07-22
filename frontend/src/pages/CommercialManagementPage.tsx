import { useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { App, Button, Drawer, Form, Input, InputNumber, Modal, Popconfirm, Select, Space, Switch, Table, Timeline } from 'antd'
import type { ColumnsType } from 'antd/es/table'
import { BadgeDollarSign, Building2, CalendarClock, Check, CircleDollarSign, Download, Eye, FileText, PencilLine, Plus, RotateCcw, ShieldAlert, Sparkles } from 'lucide-react'
import dayjs from 'dayjs'
import { MountainBanner } from '../components/MountainBanner'
import { AdminDataPanel, AdminEmpty, AdminLoading, AdminMetric, AdminMetricGrid, AdminStatus } from '../components/admin/AdminSurface'
import { createSaasPlan, downloadOrganizationInvoice, extendOrganizationTrial, getCommercialPortfolio, getSaasPlans, restoreOrganizationSubscription, settleOrganizationInvoice, suspendOrganizationSubscription, updateSaasPlan, voidOrganizationInvoice } from '../features/commercial/commercialApi'
import type { SaasPlanPayload } from '../features/commercial/commercialApi'
import type { OrganizationCommercialSubscription, OrganizationInvoice, SaasPlan } from '../types/commercial'
import { downloadBlob } from '../utils/downloadBlob'

interface PlanFormValues { name: string; code: string; description?: string; monthly_price_tnd: number; annual_price_tnd: number; max_stations?: number | null; max_employees?: number | null; features_text?: string; is_featured: boolean; status: 'active' | 'archived'; sort_order: number }

export function CommercialManagementPage() {
  const [selectedSubscription, setSelectedSubscription] = useState<OrganizationCommercialSubscription | null>(null)
  const [planEditor, setPlanEditor] = useState<SaasPlan | null | undefined>(undefined)
  const [trialExtension, setTrialExtension] = useState<OrganizationCommercialSubscription | null>(null)
  const queryClient = useQueryClient()
  const { message } = App.useApp()
  const portfolioQuery = useQuery({ queryKey: ['commercial-portfolio'], queryFn: getCommercialPortfolio })
  const plansQuery = useQuery({ queryKey: ['saas-plans'], queryFn: getSaasPlans })
  const refresh = async () => {
    await Promise.all([
      queryClient.invalidateQueries({ queryKey: ['commercial-portfolio'] }),
      queryClient.invalidateQueries({ queryKey: ['saas-plans'] }),
      queryClient.invalidateQueries({ queryKey: ['dashboard'] }),
    ])
  }
  const planMutation = useMutation({
    mutationFn: ({ plan, values }: { plan: SaasPlan | null; values: PlanFormValues }) => {
      const payload = planPayload(values)
      return plan ? updateSaasPlan(plan.id, payload) : createSaasPlan(payload)
    },
    onSuccess: async () => { await refresh(); setPlanEditor(undefined); void message.success('SaaS plan saved.') },
    onError: () => void message.error('The SaaS plan could not be saved.'),
  })
  const extendMutation = useMutation({
    mutationFn: ({ id, days, note }: { id: number; days: number; note?: string }) => extendOrganizationTrial(id, days, note),
    onSuccess: async () => { await refresh(); setTrialExtension(null); setSelectedSubscription(null); void message.success('Trial extended.') },
    onError: () => void message.error('The trial could not be extended.'),
  })
  const lifecycleMutation = useMutation({
    mutationFn: ({ subscription, action }: { subscription: OrganizationCommercialSubscription; action: 'suspend' | 'restore' }) => action === 'suspend' ? suspendOrganizationSubscription(subscription.id) : restoreOrganizationSubscription(subscription.id),
    onSuccess: async (_, variables) => { await refresh(); setSelectedSubscription(null); void message.success(variables.action === 'suspend' ? 'Operational access suspended.' : 'Commercial access restored.') },
    onError: () => void message.error('The subscription lifecycle could not be updated.'),
  })
  const invoiceMutation = useMutation({
    mutationFn: ({ invoice, action }: { invoice: OrganizationInvoice; action: 'settle' | 'void' }) => action === 'settle' ? settleOrganizationInvoice(invoice.id) : voidOrganizationInvoice(invoice.id),
    onSuccess: async (_, variables) => { await refresh(); void message.success(variables.action === 'settle' ? 'Simulated payment recorded.' : 'Invoice voided.') },
    onError: () => void message.error('The invoice could not be updated.'),
  })
  const downloadMutation = useMutation({ mutationFn: downloadOrganizationInvoice, onSuccess: (blob, id) => downloadBlob(blob, `organization-invoice-${id}.pdf`), onError: () => void message.error('The invoice PDF could not be generated.') })
  const portfolio = portfolioQuery.data
  const openInvoices = useMemo(() => (portfolio?.invoices ?? []).filter((invoice) => ['open', 'overdue'].includes(invoice.status)), [portfolio])

  const subscriptionColumns: ColumnsType<OrganizationCommercialSubscription> = [
    { title: 'Organization', key: 'organization', render: (_, item) => <div className="commercial-organization"><span><Building2 size={17} /></span><div><strong>{item.organization.name}</strong><small>{item.organization.contact_email ?? 'No contact email'}</small></div></div> },
    { title: 'Plan', key: 'plan', render: (_, item) => <div className="admin-stack-cell"><strong>{item.plan.name}</strong><small>{item.current_period_ends_at ? item.billing_cycle : 'Evaluation period'}</small></div> },
    { title: 'Lifecycle', dataIndex: 'status', render: (status: string) => <AdminStatus status={status} /> },
    { title: 'Next milestone', key: 'milestone', render: (_, item) => <div className="admin-stack-cell"><span>{nextMilestone(item).label}</span><small>{nextMilestone(item).date}</small></div> },
    { title: 'Open invoices', dataIndex: 'open_invoices_count', align: 'center', width: 110 },
    { title: '', width: 52, align: 'right', render: (_, item) => <Button type="text" aria-label={`Inspect ${item.organization.name}`} icon={<Eye size={16} />} onClick={() => setSelectedSubscription(item)} /> },
  ]
  const invoiceColumns: ColumnsType<OrganizationInvoice> = [
    { title: 'Invoice', key: 'invoice', render: (_, item) => <div className="admin-stack-cell"><strong>{item.number}</strong><small>{item.organization.name}</small></div> },
    { title: 'Plan', key: 'plan', render: (_, item) => <div className="admin-stack-cell"><span>{item.plan.name}</span><small>{item.billing_cycle}</small></div> },
    { title: 'Amount', dataIndex: 'amount_millimes', render: (value: number) => <strong>{formatMoney(value)}</strong> },
    { title: 'Due', dataIndex: 'due_at', render: formatDate },
    { title: 'Status', dataIndex: 'status', render: (status: string) => <AdminStatus status={status} /> },
    { title: '', width: 210, align: 'right', render: (_, invoice) => <Space size={4}><Button type="text" icon={<Download size={15} />} onClick={() => downloadMutation.mutate(invoice.id)}>PDF</Button><Popconfirm title="Record the simulated payment?" description="This activates the selected plan and paid period." okText="Record payment" onConfirm={() => invoiceMutation.mutate({ invoice, action: 'settle' })}><Button type="primary" size="small" icon={<Check size={14} />}>Settle</Button></Popconfirm><Popconfirm title="Void this invoice?" okText="Void" okButtonProps={{ danger: true }} onConfirm={() => invoiceMutation.mutate({ invoice, action: 'void' })}><Button danger size="small">Void</Button></Popconfirm></Space> },
  ]

  return <div className="super-admin-page commercial-page">
    <MountainBanner color="gold" breadcrumb={['Super Admin', 'Commercial', 'Organization billing']} title="Commercial control center" count={portfolio?.summary.organizations ?? 0} subtitle="Manage organization trials, plans, invoices, renewals and operational access from a traceable workspace." />
    <AdminMetricGrid>
      <AdminMetric icon={CircleDollarSign} label="Monthly recurring revenue" value={formatMoney(portfolio?.summary.monthly_recurring_millimes ?? 0)} helper="Normalized active subscriptions" />
      <AdminMetric icon={Sparkles} label="Organizations in trial" value={portfolio?.summary.trialing ?? 0} helper="Evaluation workspaces" tone="purple" />
      <AdminMetric icon={BadgeDollarSign} label="Collected" value={formatMoney(portfolio?.summary.collected_millimes ?? 0)} helper="Paid commercial invoices" tone="blue" />
      <AdminMetric icon={ShieldAlert} label="Requires attention" value={portfolio?.summary.attention ?? 0} helper={`${portfolio?.summary.open_invoices ?? 0} open invoices`} tone="orange" />
    </AdminMetricGrid>

    <AdminDataPanel title="SaaS plan catalog" subtitle="Commercial limits and capabilities are enforced by the backend." extra={<Button type="primary" icon={<Plus size={15} />} onClick={() => setPlanEditor(null)}>New plan</Button>}>
      {plansQuery.isLoading ? <AdminLoading rows={4} /> : <div className="saas-plan-grid">{plansQuery.data?.map((plan) => <article className={plan.is_featured ? 'saas-plan-card featured' : 'saas-plan-card'} key={plan.id}><header><div><small>{plan.code}</small><h3>{plan.name}</h3></div><AdminStatus status={plan.status} /></header><p>{plan.description}</p><div className="saas-plan-price"><strong>{formatMoney(plan.monthly_price_millimes)}</strong><span>/ month</span><small>{formatMoney(plan.annual_price_millimes)} billed annually</small></div><div className="saas-plan-limits"><span><Building2 size={14} />{plan.max_stations ?? 'Unlimited'} stations</span><span><FileText size={14} />{plan.max_employees ?? 'Unlimited'} employees</span></div><ul>{plan.features.map((feature) => <li key={feature}><Check size={13} />{feature}</li>)}</ul><Button icon={<PencilLine size={14} />} onClick={() => setPlanEditor(plan)}>Edit plan</Button></article>)}</div>}
    </AdminDataPanel>

    <AdminDataPanel title="Organization portfolio" subtitle="Live commercial status, plan and next lifecycle milestone for every managed tenant.">
      {portfolioQuery.isLoading ? <AdminLoading /> : !portfolio?.subscriptions.length ? <AdminEmpty description="No organization subscription has been configured" /> : <Table className="admin-data-table" rowKey="id" columns={subscriptionColumns} dataSource={portfolio.subscriptions} pagination={{ pageSize: 8, showSizeChanger: false }} onRow={(record) => ({ onDoubleClick: () => setSelectedSubscription(record) })} scroll={{ x: 900 }} />}
    </AdminDataPanel>

    <AdminDataPanel title="Payment validation queue" subtitle="Organization subscription payments are simulated and remain separate from driver charging transactions.">
      {portfolioQuery.isLoading ? <AdminLoading rows={4} /> : openInvoices.length === 0 ? <AdminEmpty description="No commercial invoice is awaiting validation" /> : <Table className="admin-data-table" rowKey="id" columns={invoiceColumns} dataSource={openInvoices} pagination={false} scroll={{ x: 940 }} />}
    </AdminDataPanel>

    <SubscriptionDrawer subscription={selectedSubscription} loading={lifecycleMutation.isPending} onClose={() => setSelectedSubscription(null)} onExtend={setTrialExtension} onLifecycle={(subscription, action) => lifecycleMutation.mutate({ subscription, action })} />
    {planEditor !== undefined && <PlanEditor plan={planEditor} loading={planMutation.isPending} onClose={() => setPlanEditor(undefined)} onSubmit={(values) => planMutation.mutate({ plan: planEditor, values })} />}
    {trialExtension && <TrialExtensionModal subscription={trialExtension} loading={extendMutation.isPending} onClose={() => setTrialExtension(null)} onSubmit={(values) => extendMutation.mutate({ id: trialExtension.id, ...values })} />}
  </div>
}

function SubscriptionDrawer({ subscription, loading, onClose, onExtend, onLifecycle }: { subscription: OrganizationCommercialSubscription | null; loading: boolean; onClose: () => void; onExtend: (subscription: OrganizationCommercialSubscription) => void; onLifecycle: (subscription: OrganizationCommercialSubscription, action: 'suspend' | 'restore') => void }) {
  return <Drawer open={Boolean(subscription)} onClose={onClose} size={590} title="Commercial account" className="commercial-drawer">{subscription && <div className="commercial-drawer-content"><header><span><Building2 size={24} /></span><div><small>ORGANIZATION</small><h2>{subscription.organization.name}</h2><p>{subscription.organization.contact_email}</p></div><AdminStatus status={subscription.status} /></header><section className="commercial-facts"><article><small>Current plan</small><strong>{subscription.plan.name}</strong><span>{subscription.current_period_ends_at ? subscription.billing_cycle : 'Business trial'}</span></article><article><small>Next milestone</small><strong>{nextMilestone(subscription).date}</strong><span>{nextMilestone(subscription).label}</span></article><article><small>Station limit</small><strong>{subscription.plan.max_stations ?? 'Unlimited'}</strong><span>Plan entitlement</span></article><article><small>Employee limit</small><strong>{subscription.plan.max_employees ?? 'Unlimited'}</strong><span>Admin excluded</span></article></section><section><h3>Lifecycle history</h3><Timeline items={(subscription.events ?? []).map((event) => ({ color: event.to_status === 'suspended' ? 'red' : 'green', children: <div className="commercial-event"><strong>{humanize(event.event)}</strong><p>{event.note}</p><small>{event.actor} · {formatDateTime(event.created_at)}</small></div> }))} /></section><footer>{subscription.current_period_ends_at === null && ['trialing', 'grace_period'].includes(subscription.status) && <Button icon={<CalendarClock size={15} />} onClick={() => onExtend(subscription)}>Extend trial</Button>}{subscription.status === 'suspended' ? <Button type="primary" icon={<RotateCcw size={15} />} loading={loading} onClick={() => onLifecycle(subscription, 'restore')}>Restore access</Button> : <Popconfirm title="Suspend operational access?" description="Billing, profile and settings remain available." okText="Suspend access" okButtonProps={{ danger: true }} onConfirm={() => onLifecycle(subscription, 'suspend')}><Button danger loading={loading}>Suspend access</Button></Popconfirm>}</footer></div>}</Drawer>
}

function PlanEditor({ plan, loading, onClose, onSubmit }: { plan: SaasPlan | null; loading: boolean; onClose: () => void; onSubmit: (values: PlanFormValues) => void }) {
  const initial: Partial<PlanFormValues> = plan ? {
    name: plan.name,
    code: plan.code,
    description: plan.description ?? undefined,
    monthly_price_tnd: plan.monthly_price_millimes / 1000,
    annual_price_tnd: plan.annual_price_millimes / 1000,
    max_stations: plan.max_stations,
    max_employees: plan.max_employees,
    features_text: plan.features.join('\n'),
    is_featured: plan.is_featured,
    status: plan.status,
    sort_order: plan.sort_order,
  } : { status: 'active', is_featured: false, sort_order: 40 }
  return <Modal open title={plan ? 'Edit SaaS plan' : 'Create SaaS plan'} footer={null} width={720} destroyOnHidden onCancel={onClose}><Form<PlanFormValues> layout="vertical" initialValues={initial} onFinish={onSubmit}><div className="commercial-plan-form"><Form.Item name="name" label="Plan name" rules={[{ required: true }]}><Input /></Form.Item><Form.Item name="code" label="Code" rules={[{ required: true }]}><Input disabled={Boolean(plan)} /></Form.Item><Form.Item className="wide" name="description" label="Description"><Input.TextArea rows={2} maxLength={1000} showCount /></Form.Item><Form.Item name="monthly_price_tnd" label="Monthly price (TND)" rules={[{ required: true }]}><InputNumber min={0} precision={3} /></Form.Item><Form.Item name="annual_price_tnd" label="Annual price (TND)" rules={[{ required: true }]}><InputNumber min={0} precision={3} /></Form.Item><Form.Item name="max_stations" label="Station limit" extra="Leave empty for unlimited"><InputNumber min={1} /></Form.Item><Form.Item name="max_employees" label="Employee limit" extra="Administrators are excluded"><InputNumber min={1} /></Form.Item><Form.Item className="wide" name="features_text" label="Features" extra="One feature per line"><Input.TextArea rows={5} /></Form.Item><Form.Item name="status" label="Status"><Select options={[{ value: 'active', label: 'Active' }, { value: 'archived', label: 'Archived' }]} /></Form.Item><Form.Item name="sort_order" label="Display order"><InputNumber min={0} /></Form.Item><Form.Item className="wide commercial-featured-control" name="is_featured" valuePropName="checked"><Switch checkedChildren="Recommended" unCheckedChildren="Standard" /> <span>Highlight this plan in the administrator plan comparison.</span></Form.Item></div><div className="admin-modal-actions"><Button onClick={onClose}>Cancel</Button><Button type="primary" htmlType="submit" loading={loading}>Save plan</Button></div></Form></Modal>
}

function TrialExtensionModal({ subscription, loading, onClose, onSubmit }: { subscription: OrganizationCommercialSubscription; loading: boolean; onClose: () => void; onSubmit: (values: { days: number; note?: string }) => void }) {
  return <Modal open title={`Extend ${subscription.organization.name} trial`} footer={null} destroyOnHidden onCancel={onClose}><Form layout="vertical" initialValues={{ days: 7 }} onFinish={onSubmit}><Form.Item name="days" label="Additional days" rules={[{ required: true }]}><InputNumber min={1} max={90} /></Form.Item><Form.Item name="note" label="Internal reason"><Input.TextArea rows={3} maxLength={1000} /></Form.Item><div className="admin-modal-actions"><Button onClick={onClose}>Cancel</Button><Button type="primary" htmlType="submit" loading={loading}>Extend trial</Button></div></Form></Modal>
}

function planPayload(values: PlanFormValues): SaasPlanPayload { return { name: values.name, code: values.code.toUpperCase(), description: values.description ?? null, monthly_price_millimes: Math.round(values.monthly_price_tnd * 1000), annual_price_millimes: Math.round(values.annual_price_tnd * 1000), max_stations: values.max_stations || null, max_employees: values.max_employees || null, features: (values.features_text ?? '').split('\n').map((item) => item.trim()).filter(Boolean), is_featured: values.is_featured, status: values.status, sort_order: values.sort_order } }
function nextMilestone(subscription: OrganizationCommercialSubscription): { label: string; date: string } { const value = subscription.status === 'trialing' ? subscription.trial_ends_at : subscription.status === 'grace_period' ? subscription.grace_ends_at : subscription.current_period_ends_at; return { label: subscription.status === 'trialing' ? 'Trial ends' : subscription.status === 'grace_period' ? 'Grace period ends' : subscription.status === 'active' ? 'Renews' : 'Access review', date: value ? formatDate(value) : 'Manual review' } }
function formatMoney(value: number): string { return new Intl.NumberFormat('en-TN', { style: 'currency', currency: 'TND', minimumFractionDigits: 3 }).format(value / 1000) }
function formatDate(value: string): string { return dayjs(value).format('DD MMM YYYY') }
function formatDateTime(value: string): string { return dayjs(value).format('DD MMM YYYY, HH:mm') }
function humanize(value: string): string { return value.replaceAll('_', ' ').replace(/^./, (character) => character.toUpperCase()) }
