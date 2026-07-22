export type OrganizationSubscriptionStatus = 'trialing' | 'active' | 'past_due' | 'grace_period' | 'suspended' | 'cancelled'
export type OrganizationInvoiceStatus = 'open' | 'paid' | 'failed' | 'void' | 'overdue'
export type BillingCycle = 'monthly' | 'annual'

export interface SaasPlan {
  id: number
  name: string
  code: string
  description: string | null
  monthly_price_millimes: number
  annual_price_millimes: number
  max_stations: number | null
  max_employees: number | null
  features: string[]
  is_featured: boolean
  status: 'active' | 'archived'
  sort_order: number
}

export interface OrganizationSubscriptionEvent {
  id: number
  event: string
  from_status: string | null
  to_status: string | null
  note: string | null
  actor: string
  created_at: string
}

export interface OrganizationCommercialSubscription {
  id: number
  organization: { id: number; name: string; contact_email: string | null }
  plan: SaasPlan
  status: OrganizationSubscriptionStatus
  billing_cycle: BillingCycle
  source: string
  auto_renew: boolean
  trial_started_at: string | null
  trial_ends_at: string | null
  current_period_starts_at: string | null
  current_period_ends_at: string | null
  grace_ends_at: string | null
  suspended_at: string | null
  open_invoices_count: number
  events: OrganizationSubscriptionEvent[]
}

export interface OrganizationInvoice {
  id: number
  number: string
  status: OrganizationInvoiceStatus
  organization: { id: number; name: string }
  plan: { id: number; name: string; code: string }
  billing_cycle: BillingCycle
  amount_millimes: number
  currency: string
  period_starts_at: string
  period_ends_at: string
  due_at: string
  paid_at: string | null
  payment_provider: string | null
  provider_reference: string | null
  requested_by: string | null
  settled_by: string | null
  created_at: string
}

export interface CommercialPortfolio {
  summary: {
    organizations: number
    trialing: number
    active: number
    attention: number
    open_invoices: number
    collected_millimes: number
    monthly_recurring_millimes: number
  }
  subscriptions: OrganizationCommercialSubscription[]
  invoices: OrganizationInvoice[]
}

export interface OrganizationBillingWorkspace {
  organization: { id: number; name: string; contact_email: string | null }
  subscription: OrganizationCommercialSubscription | null
  usage: { employees: number; stations: number; limits: { employees: number | null; stations: number | null } }
  plans: SaasPlan[]
  invoices: OrganizationInvoice[]
}
