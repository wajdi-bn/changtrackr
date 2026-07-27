import type { OrganizationSummary } from './auth'

export type SubscriptionStatus = 'active' | 'past_due' | 'cancelled' | 'expired'
export type SubscriptionInvoiceStatus = 'pending' | 'paid' | 'failed' | 'void'
export type SubscriptionPaymentMethod = 'simulated_card' | 'simulated_edinar' | 'simulated_d17'

export interface SubscriptionPlanSummary {
  id: number
  name: string
  code: string
  description: string | null
  audience: string
  status: string
}

export interface PlanSubscriptionInvoice {
  id: number
  reference: string
  status: SubscriptionInvoiceStatus
  billing_reason: 'initial' | 'renewal'
  organization: OrganizationSummary
  plan: Pick<SubscriptionPlanSummary, 'id' | 'name' | 'code'>
  subscription_id: number | null
  payment_provider: string
  payment_method: SubscriptionPaymentMethod
  provider_transaction_id: string | null
  amount_millimes: number
  currency: 'TND'
  period_starts_at: string
  period_ends_at: string
  due_at: string
  paid_at: string | null
  failed_at: string | null
  failure_code: string | null
  failure_reason: string | null
  created_at: string
}

export interface PlanSubscription {
  id: number
  organization: OrganizationSummary
  plan: SubscriptionPlanSummary
  status: SubscriptionStatus
  auto_renew: boolean
  cancel_at_period_end: boolean
  billing_provider: string
  payment_method: SubscriptionPaymentMethod
  monthly_fee_millimes: number
  discount_basis_points: number
  starts_at: string
  current_period_ends_at: string
  cancellation_requested_at: string | null
  past_due_at: string | null
  grace_ends_at: string | null
  last_renewed_at: string | null
  ended_at: string | null
  cancelled_at: string | null
  latest_invoice: PlanSubscriptionInvoice | null
  created_at: string
}

export interface SubscriptionPlan {
  id: number
  organization: OrganizationSummary
  name: string
  code: string
  description: string | null
  monthly_fee_millimes: number
  discount_basis_points: number
  audience: string
  member_count: number
  collected_millimes: number
  requires_subscription: boolean
  current_subscription: PlanSubscription | null
}

export interface SubscriptionInvoicePage {
  data: PlanSubscriptionInvoice[]
  meta: {
    current_page: number
    last_page: number
    per_page: number
    total: number
  }
}
