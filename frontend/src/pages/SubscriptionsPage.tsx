import { useDeferredValue, useMemo, useState, type ReactNode } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Alert, App, Button, Empty, Input, Modal, Popconfirm, Select, Skeleton, Switch } from 'antd'
import dayjs from 'dayjs'
import {
  BadgePercent,
  Building2,
  CalendarClock,
  Check,
  CreditCard,
  Search,
  ShieldCheck,
  Sparkles,
  X,
  Zap,
} from 'lucide-react'
import { MountainBanner } from '../components/MountainBanner'
import {
  cancelSubscription,
  getSubscriptionPlans,
  getSubscriptions,
  subscribeToPlan,
  updateSubscription,
} from '../features/subscriptions/subscriptionApi'
import type { PlanSubscription, SubscriptionPlan } from '../types/subscription'

export function SubscriptionsPage() {
  const [search, setSearch] = useState('')
  const [organizationId, setOrganizationId] = useState<number | undefined>()
  const [selectedPlan, setSelectedPlan] = useState<SubscriptionPlan | null>(null)
  const [autoRenew, setAutoRenew] = useState(true)
  const deferredSearch = useDeferredValue(search)
  const queryClient = useQueryClient()
  const { message } = App.useApp()
  const plansQuery = useQuery({ queryKey: ['subscription-plans'], queryFn: getSubscriptionPlans })
  const subscriptionsQuery = useQuery({ queryKey: ['subscriptions'], queryFn: getSubscriptions })
  const plans = useMemo(() => plansQuery.data ?? [], [plansQuery.data])
  const subscriptions = useMemo(() => subscriptionsQuery.data ?? [], [subscriptionsQuery.data])
  const activeSubscriptions = subscriptions.filter((subscription) => subscription.status === 'active')
  const inactiveSubscriptions = subscriptions.filter((subscription) => subscription.status !== 'active')
  const activeByOrganization = useMemo(
    () => new Map(activeSubscriptions.map((subscription) => [subscription.organization.id, subscription])),
    [activeSubscriptions],
  )
  const organizations = useMemo(() => Array.from(new Map(plans.map((plan) => [plan.organization.id, plan.organization])).values()), [plans])
  const filteredPlans = useMemo(() => {
    const needle = deferredSearch.trim().toLowerCase()
    return plans.filter((plan) => (!organizationId || plan.organization.id === organizationId)
      && (!needle || `${plan.name} ${plan.code} ${plan.description ?? ''} ${plan.audience} ${plan.organization.name}`.toLowerCase().includes(needle)))
  }, [deferredSearch, organizationId, plans])

  const refreshSubscriptions = async () => {
    await queryClient.invalidateQueries({ queryKey: ['subscription-plans'] })
    await queryClient.invalidateQueries({ queryKey: ['subscriptions'] })
    await queryClient.invalidateQueries({ queryKey: ['effective-pricing'] })
  }
  const subscribeMutation = useMutation({
    mutationFn: ({ planId, renew }: { planId: number; renew: boolean }) => subscribeToPlan({ charging_plan_id: planId, auto_renew: renew }),
    onSuccess: async (subscription) => {
      await refreshSubscriptions()
      setSelectedPlan(null)
      void message.success(`${subscription.plan.name} is now active for ${subscription.organization.name}.`)
    },
    onError: () => void message.error('The subscription could not be activated. The plan may no longer be available.'),
  })
  const renewalMutation = useMutation({
    mutationFn: ({ subscriptionId, renew }: { subscriptionId: number; renew: boolean }) => updateSubscription(subscriptionId, renew),
    onSuccess: async () => { await refreshSubscriptions(); void message.success('Renewal preference updated.') },
    onError: () => void message.error('The renewal preference could not be updated.'),
  })
  const cancelMutation = useMutation({
    mutationFn: cancelSubscription,
    onSuccess: async () => { await refreshSubscriptions(); void message.success('Subscription cancelled.') },
    onError: () => void message.error('The subscription could not be cancelled.'),
  })

  const monthlyTotal = activeSubscriptions.reduce((total, subscription) => total + subscription.monthly_fee_millimes, 0)
  const bestDiscount = activeSubscriptions.reduce((maximum, subscription) => Math.max(maximum, subscription.discount_basis_points), 0)

  return <div className="subscriptions-page">
    <MountainBanner
      color="green"
      breadcrumb={['Driver', 'Plans & subscriptions']}
      title="Plans & subscriptions"
      count={activeSubscriptions.length}
      subtitle="Choose plans independently from each charging network. Charging alone never creates a subscription."
    />

    <div className="subscription-kpis">
      <SubscriptionKpi icon={<ShieldCheck size={17} />} label="Active subscriptions" value={activeSubscriptions.length} tone="green" />
      <SubscriptionKpi icon={<Building2 size={17} />} label="Networks covered" value={activeByOrganization.size} tone="blue" />
      <SubscriptionKpi icon={<BadgePercent size={17} />} label="Best charging discount" value={formatDiscount(bestDiscount)} tone="purple" />
      <SubscriptionKpi icon={<CreditCard size={17} />} label="Simulated monthly total" value={formatMoney(monthlyTotal)} tone="orange" />
    </div>

    <section className="subscription-section">
      <header><div><small>My subscriptions</small><h2>Current benefits</h2><p>One active plan is allowed per organization; plans from different networks can coexist.</p></div></header>
      {subscriptionsQuery.isLoading ? <div className="subscription-current-grid"><Skeleton active /><Skeleton active /></div> : activeSubscriptions.length === 0 ? <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="You do not have an active subscription" /> : <div className="subscription-current-grid">
        {activeSubscriptions.map((subscription) => <CurrentSubscriptionCard
          key={subscription.id}
          subscription={subscription}
          updating={renewalMutation.isPending}
          cancelling={cancelMutation.isPending}
          onRenew={(renew) => renewalMutation.mutate({ subscriptionId: subscription.id, renew })}
          onCancel={() => cancelMutation.mutate(subscription.id)}
        />)}
      </div>}
    </section>

    <section className="subscription-section subscription-catalog-section">
      <header><div><small>Charging networks</small><h2>Available plans</h2><p>Compare recurring fees and discounts before choosing a network-specific plan.</p></div></header>
      <div className="subscription-toolbar">
        <Input value={search} onChange={(event) => setSearch(event.target.value)} prefix={<Search size={14} />} placeholder="Search plans or networks" allowClear />
        <Select<number> value={organizationId} allowClear placeholder="All organizations" options={organizations.map((organization) => ({ value: organization.id, label: organization.name }))} onChange={setOrganizationId} />
      </div>
      {plansQuery.isError && <Alert type="error" showIcon title="Plans could not be loaded" description="Check the API connection and retry." />}
      {plansQuery.isLoading ? <div className="subscription-plan-grid">{Array.from({ length: 4 }, (_, index) => <Skeleton key={index} active />)}</div> : filteredPlans.length === 0 ? <Empty description="No plan matches your filters" /> : <div className="subscription-plan-grid">
        {filteredPlans.map((plan) => <PlanCard
          key={plan.id}
          plan={plan}
          activeSubscription={activeByOrganization.get(plan.organization.id)}
          onChoose={() => { setSelectedPlan(plan); setAutoRenew(true) }}
        />)}
      </div>}
    </section>

    {inactiveSubscriptions.length > 0 && <section className="subscription-section subscription-history-section">
      <header><div><small>History</small><h2>Previous subscriptions</h2></div></header>
      <div className="subscription-history-list">{inactiveSubscriptions.map((subscription) => <article key={subscription.id}><span><X size={14} /><div><strong>{subscription.plan.name}</strong><small>{subscription.organization.name}</small></div></span><div><strong>{subscription.status}</strong><small>{subscription.cancelled_at ? dayjs(subscription.cancelled_at).format('DD MMM YYYY') : 'Period ended'}</small></div></article>)}</div>
    </section>}

    <SubscriptionModal
      plan={selectedPlan}
      currentSubscription={selectedPlan ? activeByOrganization.get(selectedPlan.organization.id) : undefined}
      autoRenew={autoRenew}
      submitting={subscribeMutation.isPending}
      onRenewChange={setAutoRenew}
      onClose={() => setSelectedPlan(null)}
      onConfirm={() => selectedPlan && subscribeMutation.mutate({ planId: selectedPlan.id, renew: autoRenew })}
    />
  </div>
}

function CurrentSubscriptionCard({ subscription, updating, cancelling, onRenew, onCancel }: { subscription: PlanSubscription; updating: boolean; cancelling: boolean; onRenew: (renew: boolean) => void; onCancel: () => void }) {
  return <article className="current-subscription-card">
    <header><span><Check size={15} /></span><div><small>{subscription.organization.name}</small><h3>{subscription.plan.name}</h3></div><b>Active</b></header>
    <div className="current-subscription-benefits"><span><small>Monthly fee</small><strong>{formatMoney(subscription.monthly_fee_millimes)}</strong></span><span><small>Charging discount</small><strong>{formatDiscount(subscription.discount_basis_points)}</strong></span><span><small>Current period</small><strong>Until {dayjs(subscription.current_period_ends_at).format('DD MMM YYYY')}</strong></span></div>
    <footer><label><span><strong>Automatic renewal</strong><small>Simulated monthly billing for the MVP</small></span><Switch size="small" checked={subscription.auto_renew} loading={updating} onChange={onRenew} /></label><Popconfirm title="Cancel this subscription?" description="The charging discount stops immediately." okText="Cancel subscription" okButtonProps={{ danger: true }} onConfirm={onCancel}><Button danger size="small" loading={cancelling}>Cancel</Button></Popconfirm></footer>
  </article>
}

function PlanCard({ plan, activeSubscription, onChoose }: { plan: SubscriptionPlan; activeSubscription?: PlanSubscription; onChoose: () => void }) {
  const isCurrent = activeSubscription?.plan.id === plan.id
  const isSwitch = Boolean(activeSubscription && !isCurrent)
  return <article className={`subscription-plan-card${isCurrent ? ' current' : ''}`}>
    <header><div><small>{plan.organization.name}</small><h3>{plan.name}</h3></div>{isCurrent ? <b>Current</b> : plan.discount_basis_points >= 1000 ? <b className="recommended">Best value</b> : null}</header>
    <p>{plan.description ?? `Designed for ${plan.audience.toLowerCase()}.`}</p>
    <strong className="subscription-plan-price">{formatMoney(plan.monthly_fee_millimes)}<small>/month</small></strong>
    <ul><li><BadgePercent size={14} /><span><strong>{formatDiscount(plan.discount_basis_points)}</strong> on charging energy</span></li><li><Zap size={14} /><span>Applies automatically on this network</span></li><li><ShieldCheck size={14} /><span>{plan.audience}</span></li></ul>
    <footer><small>{plan.member_count.toLocaleString()} active member{plan.member_count === 1 ? '' : 's'}</small>{!plan.requires_subscription ? <Button disabled>Pay as you go</Button> : <Button type={isCurrent ? 'default' : 'primary'} disabled={isCurrent} onClick={onChoose}>{isCurrent ? 'Current plan' : isSwitch ? 'Switch plan' : 'Choose plan'}</Button>}</footer>
  </article>
}

function SubscriptionModal({ plan, currentSubscription, autoRenew, submitting, onRenewChange, onClose, onConfirm }: { plan: SubscriptionPlan | null; currentSubscription?: PlanSubscription; autoRenew: boolean; submitting: boolean; onRenewChange: (value: boolean) => void; onClose: () => void; onConfirm: () => void }) {
  const switching = Boolean(currentSubscription && currentSubscription.plan.id !== plan?.id)
  return <Modal className="subscription-modal" width={540} open={Boolean(plan)} footer={null} onCancel={onClose} title={switching ? 'Switch charging plan' : 'Confirm subscription'}>
    {plan && <div className="subscription-modal-content">
      <div className="subscription-modal-plan"><span><Sparkles size={18} /></span><div><small>{plan.organization.name}</small><h3>{plan.name}</h3><p>{formatMoney(plan.monthly_fee_millimes)} per month - {formatDiscount(plan.discount_basis_points)} charging discount</p></div></div>
      {switching && <Alert type="warning" showIcon title={`This replaces ${currentSubscription?.plan.name}`} description="Your former plan is cancelled when the new plan becomes active. Subscriptions with other organizations are unchanged." />}
      <Alert type="info" showIcon title="MVP simulated recurring billing" description="No real card or wallet is charged. The subscription boundary is ready for a future payment-provider adapter." />
      <label className="subscription-renew-option"><span><CalendarClock size={16} /><span><strong>Automatic renewal</strong><small>Renew this plan at the end of each monthly period</small></span></span><Switch checked={autoRenew} onChange={onRenewChange} /></label>
      <div className="subscription-modal-actions"><Button onClick={onClose}>Cancel</Button><Button type="primary" loading={submitting} onClick={onConfirm}>{switching ? 'Confirm switch' : 'Activate plan'}</Button></div>
    </div>}
  </Modal>
}

function SubscriptionKpi({ icon, label, value, tone }: { icon: ReactNode; label: string; value: string | number; tone: string }) {
  return <article className={`subscription-kpi subscription-kpi--${tone}`}><span>{icon}</span><div><small>{label}</small><strong>{value}</strong></div></article>
}

function formatMoney(millimes: number): string {
  return `${(millimes / 1000).toLocaleString('en-US', { minimumFractionDigits: 3, maximumFractionDigits: 3 })} TND`
}

function formatDiscount(basisPoints: number): string {
  return `${(basisPoints / 100).toLocaleString('en-US', { maximumFractionDigits: 2 })}%`
}
