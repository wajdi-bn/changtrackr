import type { PublicSaasPlan } from '../features/commercial/publicCommercialApi'

export type LandingBillingCycle = 'monthly' | 'annual'

export function getLandingPlanPrice(plan: PublicSaasPlan, cycle: LandingBillingCycle) {
  const amountMillimes = cycle === 'monthly'
    ? plan.monthly_price_millimes
    : plan.annual_price_millimes

  return {
    amount: amountMillimes / 1000,
    period: cycle === 'monthly' ? 'month' : 'year',
    monthlyEquivalent: cycle === 'annual' ? amountMillimes / 12 / 1000 : null,
  }
}

export function formatLandingPlanLimit(value: number | null, label: string): string {
  return value === null ? `Unlimited ${label}` : `${value} ${label}`
}
