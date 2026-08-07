export type TariffStatus = 'draft' | 'active' | 'archived'

export interface TariffAssignment {
  id: number
  type: 'station' | 'connector'
  station: { id: number; name: string } | null
  connector: { id: number; external_id: string; type: string } | null
}

export interface Tariff {
  id: number
  organization_id: number
  name: string
  code: string
  description: string | null
  status: TariffStatus
  currency: 'TND'
  price_per_kwh_millimes: number
  session_fee_millimes: number
  idle_fee_per_minute_millimes: number
  minimum_charge_millimes: number
  valid_from: string | null
  valid_until: string | null
  is_default: boolean
  assignments: TariffAssignment[]
  created_at: string
  updated_at: string
}

export interface TariffPayload {
  name: string
  code: string
  description?: string | null
  status: TariffStatus
  currency: 'TND'
  price_per_kwh_millimes: number
  session_fee_millimes: number
  idle_fee_per_minute_millimes: number
  minimum_charge_millimes: number
  valid_from?: string | null
  valid_until?: string | null
  is_default: boolean
}

export interface TariffsResponse {
  data: Tariff[]
  summary: { total: number; active: number; draft: number; assignments: number }
}

export interface EffectivePricing {
  id: number | null
  name: string
  source: 'connector' | 'station' | 'organization_default' | 'configuration_fallback'
  currency: 'TND'
  price_per_kwh_millimes: number
  session_fee_millimes: number
  idle_fee_per_minute_millimes: number
  minimum_charge_millimes: number
  effective_price_per_kwh_millimes: number
  plan: { id: number; name: string; discount_basis_points: number } | null
}

export interface ChargingPlan {
  id: number
  organization_id: number
  name: string
  code: string
  description: string | null
  monthly_fee_millimes: number
  discount_basis_points: number
  audience: string
  status: TariffStatus
  member_count: number
  collected_millimes: number
  failed_payments_count: number
  created_at: string
  updated_at: string
}

export interface ChargingPlanPayload {
  name: string
  code: string
  description?: string | null
  monthly_fee_millimes: number
  discount_basis_points: number
  audience: string
  status: TariffStatus
}

export interface ChargingPlanSubscribers {
  summary: { current_members: number; past_due: number; collected_millimes: number }
  data: Array<{
    id: number
    customer: { id: number; name: string; email: string; avatar_url: string | null }
    status: string
    auto_renew: boolean
    cancel_at_period_end: boolean
    current_period_ends_at: string
    grace_ends_at: string | null
    invoices_count: number
    paid_millimes: number
    latest_invoice_status: string | null
  }>
}

export type ChargingTargetType = 'energy' | 'duration' | 'amount'

export interface PricingSimulationPayload {
  station_id: number
  connector_id?: number
  charging_plan_id?: number
  target_type?: ChargingTargetType
  target_value?: number
  energy_kwh?: number
  duration_minutes?: number
  idle_minutes?: number
}

export interface PricingSimulation {
  tariff: { id: number | null; name: string; source: EffectivePricing['source']; currency: 'TND' }
  plan: { id: number; name: string; discount_basis_points: number } | null
  inputs: { energy_kwh: number; duration_minutes: number; idle_minutes: number }
  breakdown: {
    energy_gross_millimes: number
    discount_millimes: number
    energy_net_millimes: number
    time_cost_millimes: number
    session_fee_millimes: number
    idle_fee_millimes: number
    minimum_charge_millimes: number
    subtotal_millimes: number
    total_millimes: number
  }
  estimate: {
    target_type: ChargingTargetType
    target_value: number
    energy_kwh: number
    duration_minutes: number
    amount_millimes: number
    connector_power_kw: number
    preauthorization_amount_millimes: number
    within_preauthorization: boolean
    maximums: {
      energy_kwh: number
      duration_minutes: number
      amount_millimes: number
    }
  } | null
}
