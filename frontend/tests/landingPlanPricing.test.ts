import assert from 'node:assert/strict'
import test from 'node:test'
import type { PublicSaasPlan } from '../src/features/commercial/publicCommercialApi.ts'
import { formatLandingPlanLimit, getLandingPlanPrice } from '../src/pages/landingPlanPricing.ts'

const plan: PublicSaasPlan = {
  name: 'Business',
  code: 'BUSINESS',
  description: 'Operations plan',
  monthly_price_millimes: 399000,
  annual_price_millimes: 3990000,
  max_stations: 50,
  max_employees: null,
  features: [],
  is_featured: true,
}

test('uses the authoritative monthly and annual commercial prices', () => {
  assert.deepEqual(getLandingPlanPrice(plan, 'monthly'), {
    amount: 399,
    period: 'month',
    monthlyEquivalent: null,
  })
  assert.deepEqual(getLandingPlanPrice(plan, 'annual'), {
    amount: 3990,
    period: 'year',
    monthlyEquivalent: 332.5,
  })
})

test('formats finite and unlimited commercial capacity clearly', () => {
  assert.equal(formatLandingPlanLimit(plan.max_stations, 'stations'), '50 stations')
  assert.equal(formatLandingPlanLimit(plan.max_employees, 'employees'), 'Unlimited employees')
})
