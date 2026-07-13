import type { OrganizationSummary } from './auth'

export type SubscriptionStatus = 'active' | 'cancelled' | 'expired'

export interface SubscriptionPlanSummary {
  id: number
  name: string
  code: string
  description: string | null
  audience: string
  status: string
}

export interface PlanSubscription {
  id: number
  organization: OrganizationSummary
  plan: SubscriptionPlanSummary
  status: SubscriptionStatus
  auto_renew: boolean
  billing_provider: string
  monthly_fee_millimes: number
  discount_basis_points: number
  starts_at: string
  current_period_ends_at: string
  cancelled_at: string | null
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
  requires_subscription: boolean
  current_subscription: PlanSubscription | null
}
