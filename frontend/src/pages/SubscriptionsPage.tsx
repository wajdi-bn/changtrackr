import { useDeferredValue, useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Alert, App, Avatar, Button, Empty, Input, Modal, Popconfirm, Radio, Segmented, Select, Skeleton, Switch, Table, Tag } from 'antd'
import type { ColumnsType } from 'antd/es/table'
import { getApiErrorMessage } from '../api/apiErrors'
import dayjs from 'dayjs'
import {
  BadgePercent,
  Building2,
  CalendarClock,
  CreditCard,
  FileText,
  Landmark,
  ListFilter,
  ReceiptText,
  RotateCcw,
  Search,
  ShieldCheck,
  Smartphone,
  TriangleAlert,
  X,
  Zap,
} from 'lucide-react'
import { MountainBanner } from '../components/MountainBanner'
import { MetricItem, MetricStrip } from '../components/MetricStrip'
import { PdfDocumentPreviewModal, type PdfPreviewTarget } from '../features/documents/PdfDocumentPreviewModal'
import {
  cancelSubscription,
  getSubscriptionInvoices,
  getSubscriptionPlans,
  getSubscriptions,
  loadSubscriptionInvoice,
  resumeSubscription,
  retrySubscriptionPayment,
  subscribeToPlan,
  updateSubscription,
} from '../features/subscriptions/subscriptionApi'
import { createIdempotencyKey } from '../lib/idempotency'
import type {
  PlanSubscription,
  PlanSubscriptionInvoice,
  SubscriptionPaymentMethod,
  SubscriptionPlan,
} from '../types/subscription'

type WorkspaceView = 'networks' | 'memberships' | 'billing'
type PlanSort = 'price_low' | 'discount_high' | 'popular'

export function SubscriptionsPage() {
  const [view, setView] = useState<WorkspaceView>('networks')
  const [search, setSearch] = useState('')
  const [organizationId, setOrganizationId] = useState<number | undefined>()
  const [sort, setSort] = useState<PlanSort>('price_low')
  const [selectedPlan, setSelectedPlan] = useState<SubscriptionPlan | null>(null)
  const [retrySubscription, setRetrySubscription] = useState<PlanSubscription | null>(null)
  const [autoRenew, setAutoRenew] = useState(true)
  const [paymentMethod, setPaymentMethod] = useState<SubscriptionPaymentMethod>('simulated_card')
  const [simulationOutcome, setSimulationOutcome] = useState<'success' | 'declined'>('success')
  const [invoicePreview, setInvoicePreview] = useState<PdfPreviewTarget | null>(null)
  const deferredSearch = useDeferredValue(search)
  const queryClient = useQueryClient()
  const { message } = App.useApp()
  const plansQuery = useQuery({ queryKey: ['subscription-plans'], queryFn: getSubscriptionPlans })
  const subscriptionsQuery = useQuery({ queryKey: ['subscriptions'], queryFn: getSubscriptions })
  const invoicesQuery = useQuery({ queryKey: ['subscription-invoices'], queryFn: () => getSubscriptionInvoices(1) })
  const plans = useMemo(() => plansQuery.data ?? [], [plansQuery.data])
  const subscriptions = useMemo(() => subscriptionsQuery.data ?? [], [subscriptionsQuery.data])
  const currentSubscriptions = subscriptions.filter((subscription) => ['active', 'past_due'].includes(subscription.status))
  const previousSubscriptions = subscriptions.filter((subscription) => !['active', 'past_due'].includes(subscription.status))
  const activeByOrganization = useMemo(
    () => new Map(currentSubscriptions.map((subscription) => [subscription.organization.id, subscription])),
    [currentSubscriptions],
  )
  const organizations = useMemo(
    () => Array.from(new Map(plans.map((plan) => [plan.organization.id, plan.organization])).values()),
    [plans],
  )
  const filteredPlans = useMemo(() => {
    const needle = deferredSearch.trim().toLowerCase()
    return plans
      .filter((plan) => (!organizationId || plan.organization.id === organizationId)
        && (!needle || `${plan.name} ${plan.code} ${plan.description ?? ''} ${plan.audience} ${plan.organization.name}`.toLowerCase().includes(needle)))
      .sort((left, right) => sort === 'discount_high'
        ? right.discount_basis_points - left.discount_basis_points
        : sort === 'popular'
          ? right.member_count - left.member_count
          : left.monthly_fee_millimes - right.monthly_fee_millimes)
  }, [deferredSearch, organizationId, plans, sort])

  const refreshSubscriptions = async () => {
    await Promise.all([
      queryClient.invalidateQueries({ queryKey: ['subscription-plans'] }),
      queryClient.invalidateQueries({ queryKey: ['subscriptions'] }),
      queryClient.invalidateQueries({ queryKey: ['subscription-invoices'] }),
      queryClient.invalidateQueries({ queryKey: ['effective-pricing'] }),
    ])
  }
  const subscribeMutation = useMutation({
    mutationFn: (plan: SubscriptionPlan) => subscribeToPlan({
      charging_plan_id: plan.id,
      auto_renew: autoRenew,
      payment_method: paymentMethod,
      idempotency_key: createIdempotencyKey(),
      simulation_outcome: simulationOutcome,
    }),
    onSuccess: async (subscription) => {
      await refreshSubscriptions()
      setSelectedPlan(null)
      setView('memberships')
      void message.success(`${subscription.plan.name} is active for ${subscription.organization.name}.`)
    },
    onError: (error) => void message.error(getApiErrorMessage(error, 'The payment was not completed. No plan was activated.')),
  })
  const renewalMutation = useMutation({
    mutationFn: ({ subscriptionId, renew }: { subscriptionId: number; renew: boolean }) => updateSubscription(subscriptionId, renew),
    onSuccess: async () => { await refreshSubscriptions(); void message.success('Renewal preference updated.') },
    onError: (error) => void message.error(getApiErrorMessage(error, 'The renewal preference could not be updated.')),
  })
  const cancelMutation = useMutation({
    mutationFn: cancelSubscription,
    onSuccess: async (subscription) => {
      await refreshSubscriptions()
      void message.success(`Cancellation scheduled for ${dayjs(subscription.current_period_ends_at).format('DD MMM YYYY')}.`)
    },
    onError: (error) => void message.error(getApiErrorMessage(error, 'The cancellation could not be scheduled.')),
  })
  const resumeMutation = useMutation({
    mutationFn: resumeSubscription,
    onSuccess: async () => { await refreshSubscriptions(); void message.success('Automatic renewal restored.') },
    onError: (error) => void message.error(getApiErrorMessage(error, 'The subscription could not be resumed.')),
  })
  const retryMutation = useMutation({
    mutationFn: (subscription: PlanSubscription) => retrySubscriptionPayment(subscription.id, {
      payment_method: paymentMethod,
      idempotency_key: createIdempotencyKey(),
      simulation_outcome: simulationOutcome,
    }),
    onSuccess: async () => {
      await refreshSubscriptions()
      setRetrySubscription(null)
      void message.success('Payment completed. The plan is active again.')
    },
    onError: (error) => void message.error(getApiErrorMessage(error, 'The payment retry was declined.')),
  })

  const monthlyTotal = currentSubscriptions.reduce((total, subscription) => total + subscription.monthly_fee_millimes, 0)
  const bestDiscount = currentSubscriptions.reduce((maximum, subscription) => Math.max(maximum, subscription.discount_basis_points), 0)
  const invoices = invoicesQuery.data?.data ?? []
  const openCheckout = (plan: SubscriptionPlan) => {
    setSelectedPlan(plan)
    setRetrySubscription(null)
    setAutoRenew(true)
    setPaymentMethod('simulated_card')
    setSimulationOutcome('success')
  }
  const openInvoice = (invoice: PlanSubscriptionInvoice) => setInvoicePreview({
    title: `${invoice.plan.name} - ${invoice.reference}`,
    filename: `plan-invoice-${invoice.reference}.pdf`,
    load: () => loadSubscriptionInvoice(invoice.id),
  })

  return <div className="subscriptions-page">
    <MountainBanner
      color="green"
      breadcrumb={['Driver', 'Plans & subscriptions']}
      title="Plans & subscriptions"
      count={currentSubscriptions.length}
      subtitle="Join charging networks deliberately, manage recurring benefits, and keep every invoice in one place."
    />

    <MetricStrip className="subscription-kpis">
      <MetricItem icon={<ShieldCheck size={18} />} label="Current memberships" value={currentSubscriptions.length} tone="green" />
      <MetricItem icon={<Building2 size={18} />} label="Networks covered" value={activeByOrganization.size} tone="blue" />
      <MetricItem icon={<BadgePercent size={18} />} label="Best charging discount" value={formatDiscount(bestDiscount)} tone="purple" />
      <MetricItem icon={<CreditCard size={18} />} label="Monthly commitment" value={formatMoney(monthlyTotal)} tone="orange" />
    </MetricStrip>

    <div className="subscription-view-switcher">
      <Segmented<WorkspaceView>
        value={view}
        onChange={setView}
        options={[
          { value: 'networks', label: 'Explore networks', icon: <Building2 size={15} /> },
          { value: 'memberships', label: 'My memberships', icon: <ShieldCheck size={15} /> },
          { value: 'billing', label: 'Invoices', icon: <ReceiptText size={15} /> },
        ]}
      />
    </div>

    {view === 'networks' && <section className="subscription-section subscription-catalog-section">
      <header><div><small>Charging networks</small><h2>Compare plans by organization</h2><p>One current plan per network. A successful checkout is required before benefits change.</p></div></header>
      <div className="subscription-toolbar">
        <Input value={search} onChange={(event) => setSearch(event.target.value)} prefix={<Search size={15} />} placeholder="Search a network, plan or audience" allowClear />
        <Select<number> value={organizationId} allowClear placeholder="All networks" options={organizations.map((organization) => ({ value: organization.id, label: organization.name }))} onChange={setOrganizationId} />
        <Select<PlanSort> value={sort} suffixIcon={<ListFilter size={14} />} options={[
          { value: 'price_low', label: 'Lowest monthly fee' },
          { value: 'discount_high', label: 'Highest discount' },
          { value: 'popular', label: 'Most popular' },
        ]} onChange={setSort} />
      </div>
      {plansQuery.isError && <Alert type="error" showIcon title="Plans could not be loaded" description="Check the API connection and retry." />}
      {plansQuery.isLoading ? <div className="subscription-plan-grid">{Array.from({ length: 4 }, (_, index) => <Skeleton key={index} active />)}</div> : filteredPlans.length === 0 ? <Empty description="No plan matches your filters" /> : <div className="subscription-network-groups">
        {groupPlans(filteredPlans).map(([organization, networkPlans]) => <section key={organization.id} className="subscription-network-group">
          <header>
            <OrganizationIdentity organization={organization} />
            <div><strong>{networkPlans.length} plan{networkPlans.length === 1 ? '' : 's'}</strong><small>{networkPlans.reduce((sum, plan) => sum + plan.member_count, 0)} memberships across plans</small></div>
          </header>
          <div className="subscription-plan-grid">{networkPlans.map((plan) => <PlanCard
            key={plan.id}
            plan={plan}
            activeSubscription={activeByOrganization.get(plan.organization.id)}
            onChoose={() => openCheckout(plan)}
          />)}</div>
        </section>)}
      </div>}
    </section>}

    {view === 'memberships' && <section className="subscription-section">
      <header><div><small>My memberships</small><h2>Current benefits and renewals</h2><p>Cancellation takes effect at the end of the paid period; your discount remains available until then.</p></div></header>
      {subscriptionsQuery.isLoading ? <div className="subscription-current-grid"><Skeleton active /><Skeleton active /></div> : currentSubscriptions.length === 0 ? <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="You do not have a current membership"><Button type="primary" onClick={() => setView('networks')}>Explore networks</Button></Empty> : <div className="subscription-current-grid">
        {currentSubscriptions.map((subscription) => <CurrentSubscriptionCard
          key={subscription.id}
          subscription={subscription}
          updating={renewalMutation.isPending || cancelMutation.isPending || resumeMutation.isPending}
          onRenew={(renew) => renewalMutation.mutate({ subscriptionId: subscription.id, renew })}
          onCancel={() => cancelMutation.mutate(subscription.id)}
          onResume={() => resumeMutation.mutate(subscription.id)}
          onRetry={() => { setRetrySubscription(subscription); setSelectedPlan(null); setPaymentMethod(subscription.payment_method); setSimulationOutcome('success') }}
          onInvoice={() => subscription.latest_invoice && openInvoice(subscription.latest_invoice)}
        />)}
      </div>}
      {previousSubscriptions.length > 0 && <div className="subscription-history-block">
        <h3>Previous memberships</h3>
        <div className="subscription-history-list">{previousSubscriptions.map((subscription) => <article key={subscription.id}><span><X size={14} /><div><strong>{subscription.plan.name}</strong><small>{subscription.organization.name}</small></div></span><div><strong>{subscription.status}</strong><small>{subscription.ended_at ? dayjs(subscription.ended_at).format('DD MMM YYYY') : 'Period ended'}</small></div></article>)}</div>
      </div>}
    </section>}

    {view === 'billing' && <section className="subscription-section subscription-billing-section">
      <header><div><small>Client billing</small><h2>Membership invoices</h2><p>Charging-plan invoices are separate from charging-session receipts.</p></div></header>
      {invoicesQuery.isLoading ? <Skeleton active paragraph={{ rows: 8 }} /> : invoices.length === 0 ? <Empty description="No membership invoice yet" /> : <Table
        className="subscription-invoice-table"
        rowKey="id"
        dataSource={invoices}
        columns={invoiceColumns(openInvoice)}
        pagination={{ pageSize: 8, showSizeChanger: false }}
        scroll={{ x: 760 }}
      />}
    </section>}

    <SubscriptionCheckoutModal
      plan={selectedPlan}
      currentSubscription={selectedPlan ? activeByOrganization.get(selectedPlan.organization.id) : undefined}
      autoRenew={autoRenew}
      paymentMethod={paymentMethod}
      simulationOutcome={simulationOutcome}
      submitting={subscribeMutation.isPending}
      onRenewChange={setAutoRenew}
      onPaymentMethodChange={setPaymentMethod}
      onSimulationOutcomeChange={setSimulationOutcome}
      onClose={() => setSelectedPlan(null)}
      onConfirm={() => selectedPlan && subscribeMutation.mutate(selectedPlan)}
    />
    <RetryPaymentModal
      subscription={retrySubscription}
      method={paymentMethod}
      simulationOutcome={simulationOutcome}
      submitting={retryMutation.isPending}
      onMethodChange={setPaymentMethod}
      onSimulationOutcomeChange={setSimulationOutcome}
      onClose={() => setRetrySubscription(null)}
      onConfirm={() => retrySubscription && retryMutation.mutate(retrySubscription)}
    />
    <PdfDocumentPreviewModal target={invoicePreview} onClose={() => setInvoicePreview(null)} />
  </div>
}

function CurrentSubscriptionCard({ subscription, updating, onRenew, onCancel, onResume, onRetry, onInvoice }: {
  subscription: PlanSubscription
  updating: boolean
  onRenew: (renew: boolean) => void
  onCancel: () => void
  onResume: () => void
  onRetry: () => void
  onInvoice: () => void
}) {
  const pastDue = subscription.status === 'past_due'
  return <article className={`current-subscription-card${pastDue ? ' past-due' : ''}`}>
    <header>
      <OrganizationLogo organization={subscription.organization} />
      <div><small>{subscription.organization.name}</small><h3>{subscription.plan.name}</h3></div>
      <Tag color={pastDue ? 'error' : subscription.cancel_at_period_end ? 'warning' : 'success'}>{pastDue ? 'Payment due' : subscription.cancel_at_period_end ? 'Ends this period' : 'Active'}</Tag>
    </header>
    {pastDue && <Alert type="warning" showIcon title="Renewal payment failed" description={`Retry before ${dayjs(subscription.grace_ends_at).format('DD MMM YYYY')} to keep charging benefits.`} />}
    {subscription.cancel_at_period_end && <div className="subscription-end-notice"><CalendarClock size={16} /><span><strong>Cancellation scheduled</strong><small>Benefits remain active through {dayjs(subscription.current_period_ends_at).format('DD MMM YYYY')}.</small></span></div>}
    <div className="current-subscription-benefits">
      <span><small>Monthly fee</small><strong>{formatMoney(subscription.monthly_fee_millimes)}</strong></span>
      <span><small>Charging discount</small><strong>{formatDiscount(subscription.discount_basis_points)}</strong></span>
      <span><small>Current period</small><strong>Until {dayjs(subscription.current_period_ends_at).format('DD MMM YYYY')}</strong></span>
    </div>
    <footer>
      <label><span><strong>Automatic renewal</strong><small>{paymentMethodLabel(subscription.payment_method)}</small></span><Switch size="small" checked={subscription.auto_renew} disabled={pastDue || subscription.cancel_at_period_end} loading={updating} onChange={onRenew} /></label>
      <div>
        {subscription.latest_invoice && <Button size="small" icon={<FileText size={14} />} onClick={onInvoice}>Invoice</Button>}
        {pastDue ? <Button type="primary" size="small" icon={<RotateCcw size={14} />} onClick={onRetry}>Retry payment</Button> : subscription.cancel_at_period_end ? <Button type="primary" size="small" onClick={onResume} loading={updating}>Keep membership</Button> : <Popconfirm title="End membership at period end?" description={`Benefits remain available until ${dayjs(subscription.current_period_ends_at).format('DD MMM YYYY')}.`} okText="Schedule cancellation" okButtonProps={{ danger: true }} onConfirm={onCancel}><Button danger size="small" loading={updating}>Cancel</Button></Popconfirm>}
      </div>
    </footer>
  </article>
}

function PlanCard({ plan, activeSubscription, onChoose }: { plan: SubscriptionPlan; activeSubscription?: PlanSubscription; onChoose: () => void }) {
  const isCurrent = activeSubscription?.plan.id === plan.id
  const isSwitch = Boolean(activeSubscription && !isCurrent)
  return <article className={`subscription-plan-card${isCurrent ? ' current' : ''}`}>
    <header><div><small>{plan.code}</small><h3>{plan.name}</h3></div>{isCurrent ? <b>Current</b> : plan.discount_basis_points >= 1000 ? <b className="recommended">Best value</b> : null}</header>
    <p>{plan.description ?? `Designed for ${plan.audience.toLowerCase()}.`}</p>
    <strong className="subscription-plan-price">{formatMoney(plan.monthly_fee_millimes)}<small>/month</small></strong>
    <ul>
      <li><BadgePercent size={14} /><span><strong>{formatDiscount(plan.discount_basis_points)}</strong> on charging energy</span></li>
      <li><Zap size={14} /><span>Applied automatically on this network</span></li>
      <li><ShieldCheck size={14} /><span>{plan.audience}</span></li>
    </ul>
    <footer><small>{plan.member_count.toLocaleString()} current member{plan.member_count === 1 ? '' : 's'}</small>{!plan.requires_subscription ? <Button disabled>Pay as you go</Button> : <Button type={isCurrent ? 'default' : 'primary'} disabled={isCurrent} onClick={onChoose}>{isCurrent ? 'Current plan' : isSwitch ? 'Switch plan' : 'Choose plan'}</Button>}</footer>
  </article>
}

function SubscriptionCheckoutModal({ plan, currentSubscription, autoRenew, paymentMethod, simulationOutcome, submitting, onRenewChange, onPaymentMethodChange, onSimulationOutcomeChange, onClose, onConfirm }: {
  plan: SubscriptionPlan | null
  currentSubscription?: PlanSubscription
  autoRenew: boolean
  paymentMethod: SubscriptionPaymentMethod
  simulationOutcome: 'success' | 'declined'
  submitting: boolean
  onRenewChange: (value: boolean) => void
  onPaymentMethodChange: (value: SubscriptionPaymentMethod) => void
  onSimulationOutcomeChange: (value: 'success' | 'declined') => void
  onClose: () => void
  onConfirm: () => void
}) {
  const switching = Boolean(currentSubscription && currentSubscription.plan.id !== plan?.id)
  return <Modal className="subscription-checkout-modal" width={760} open={Boolean(plan)} footer={null} onCancel={onClose} title={switching ? 'Switch charging plan' : 'Complete membership checkout'}>
    {plan && <div className="subscription-checkout-layout">
      <section>
        <OrganizationIdentity organization={plan.organization} />
        <div className="checkout-plan-summary"><small>SELECTED PLAN</small><h3>{plan.name}</h3><p>{plan.description}</p><strong>{formatMoney(plan.monthly_fee_millimes)}<span>/month</span></strong><div><BadgePercent size={15} />{formatDiscount(plan.discount_basis_points)} charging discount</div></div>
        {switching && <Alert type="warning" showIcon title={`Replaces ${currentSubscription?.plan.name}`} description="The former plan ends only after this simulated payment succeeds. Other network memberships are unchanged." />}
      </section>
      <section>
        <h3>Payment method</h3><p className="checkout-helper">Choose the sandbox channel used for this recurring membership.</p>
        <PaymentMethods value={paymentMethod} onChange={onPaymentMethodChange} />
        {import.meta.env.DEV && <label className="subscription-sandbox-control"><span><strong>Simulator outcome</strong><small>Development testing only</small></span><Select value={simulationOutcome} options={[{ value: 'success', label: 'Approve payment' }, { value: 'declined', label: 'Decline payment' }]} onChange={onSimulationOutcomeChange} /></label>}
        <label className="subscription-renew-option"><span><CalendarClock size={16} /><span><strong>Automatic renewal</strong><small>Use this channel at each monthly renewal</small></span></span><Switch checked={autoRenew} onChange={onRenewChange} /></label>
        <div className="checkout-total"><span><small>Due today</small><strong>{formatMoney(plan.monthly_fee_millimes)}</strong></span><small>No real financial transaction occurs in this MVP.</small></div>
        <div className="subscription-modal-actions"><Button onClick={onClose}>Back</Button><Button type="primary" icon={<ShieldCheck size={15} />} loading={submitting} onClick={onConfirm}>Pay and activate</Button></div>
      </section>
    </div>}
  </Modal>
}

function RetryPaymentModal({ subscription, method, simulationOutcome, submitting, onMethodChange, onSimulationOutcomeChange, onClose, onConfirm }: {
  subscription: PlanSubscription | null
  method: SubscriptionPaymentMethod
  simulationOutcome: 'success' | 'declined'
  submitting: boolean
  onMethodChange: (value: SubscriptionPaymentMethod) => void
  onSimulationOutcomeChange: (value: 'success' | 'declined') => void
  onClose: () => void
  onConfirm: () => void
}) {
  return <Modal className="subscription-retry-modal" width={560} open={Boolean(subscription)} footer={null} onCancel={onClose} title="Retry membership payment">
    {subscription && <div className="subscription-retry-content">
      <Alert type="warning" showIcon icon={<TriangleAlert size={17} />} title={`${subscription.plan.name} is past due`} description={`Complete ${formatMoney(subscription.monthly_fee_millimes)} before ${dayjs(subscription.grace_ends_at).format('DD MMM YYYY')}.`} />
      <PaymentMethods value={method} onChange={onMethodChange} />
      {import.meta.env.DEV && <Select value={simulationOutcome} options={[{ value: 'success', label: 'Approve payment' }, { value: 'declined', label: 'Decline payment' }]} onChange={onSimulationOutcomeChange} />}
      <div className="subscription-modal-actions"><Button onClick={onClose}>Cancel</Button><Button type="primary" loading={submitting} onClick={onConfirm}>Retry {formatMoney(subscription.monthly_fee_millimes)}</Button></div>
    </div>}
  </Modal>
}

function PaymentMethods({ value, onChange }: { value: SubscriptionPaymentMethod; onChange: (value: SubscriptionPaymentMethod) => void }) {
  return <Radio.Group className="subscription-payment-methods" value={value} onChange={(event) => onChange(event.target.value)}>
    <Radio value="simulated_card"><span><CreditCard size={18} /><span><strong>Bank card</strong><small>Visa or Mastercard simulation</small></span></span></Radio>
    <Radio value="simulated_edinar"><span><Landmark size={18} /><span><strong>e-DINAR</strong><small>Postal wallet simulation</small></span></span></Radio>
    <Radio value="simulated_d17"><span><Smartphone size={18} /><span><strong>D17 wallet</strong><small>Mobile payment simulation</small></span></span></Radio>
  </Radio.Group>
}

function OrganizationIdentity({ organization }: { organization: SubscriptionPlan['organization'] }) {
  return <div className="subscription-organization-identity"><OrganizationLogo organization={organization} /><div><small>CHARGING NETWORK</small><strong>{organization.name}</strong></div></div>
}

function OrganizationLogo({ organization }: { organization: SubscriptionPlan['organization'] }) {
  return <Avatar className="subscription-organization-logo" shape="square" size={42} src={organization.logo_url ?? undefined}>{initials(organization.name)}</Avatar>
}

function groupPlans(plans: SubscriptionPlan[]): Array<[SubscriptionPlan['organization'], SubscriptionPlan[]]> {
  const groups = new Map<number, [SubscriptionPlan['organization'], SubscriptionPlan[]]>()
  plans.forEach((plan) => {
    const group = groups.get(plan.organization.id) ?? [plan.organization, []]
    group[1].push(plan)
    groups.set(plan.organization.id, group)
  })
  return Array.from(groups.values())
}

function invoiceColumns(onOpen: (invoice: PlanSubscriptionInvoice) => void): ColumnsType<PlanSubscriptionInvoice> {
  return [
    { title: 'Invoice', key: 'invoice', render: (_, invoice) => <div className="subscription-invoice-cell"><ReceiptText size={16} /><div><strong>{invoice.reference}</strong><small>{invoice.plan.name} / {invoice.organization.name}</small></div></div> },
    { title: 'Period', key: 'period', render: (_, invoice) => <span>{dayjs(invoice.period_starts_at).format('DD MMM')} - {dayjs(invoice.period_ends_at).format('DD MMM YYYY')}</span> },
    { title: 'Reason', dataIndex: 'billing_reason', render: (value: string) => <span className="capitalize">{value}</span> },
    { title: 'Amount', dataIndex: 'amount_millimes', render: (value: number) => <strong>{formatMoney(value)}</strong> },
    { title: 'Status', dataIndex: 'status', render: (status: string) => <Tag color={status === 'paid' ? 'success' : status === 'failed' ? 'error' : 'warning'}>{status}</Tag> },
    { title: '', align: 'right', width: 100, render: (_, invoice) => <Button size="small" icon={<FileText size={14} />} onClick={() => onOpen(invoice)}>View PDF</Button> },
  ]
}

function formatMoney(millimes: number): string {
  return `${(millimes / 1000).toLocaleString('en-TN', { minimumFractionDigits: 3, maximumFractionDigits: 3 })} TND`
}

function formatDiscount(basisPoints: number): string {
  return `${(basisPoints / 100).toLocaleString('en-US', { maximumFractionDigits: 2 })}%`
}

function paymentMethodLabel(method: SubscriptionPaymentMethod): string {
  return ({ simulated_card: 'Bank card simulation', simulated_edinar: 'e-DINAR simulation', simulated_d17: 'D17 simulation' })[method]
}

function initials(name: string): string {
  return name.split(/\s+/).slice(0, 2).map((part) => part[0]).join('').toUpperCase()
}
