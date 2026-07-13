import type { OrganizationSummary } from './auth'

export type ChargingSessionStatus = 'charging' | 'completed' | 'cancelled'
export type SessionPaymentStatus = 'unpaid' | 'paid' | 'failed'
export type PaymentStatus = 'pending' | 'paid' | 'failed'
export type SimulatedPaymentMethod = 'simulated_card' | 'simulated_edinar' | 'simulated_d17'

export interface Payment {
  id: number
  reference: string
  provider: string
  method: SimulatedPaymentMethod
  status: PaymentStatus
  amount_millimes: number
  amount: string
  currency: string
  provider_transaction_id: string | null
  failure_reason: string | null
  paid_at: string | null
  failed_at: string | null
  created_at: string
  organization: OrganizationSummary | null
  client?: { id: number; name: string } | null
  session?: {
    id: number
    reference: string
    station_name: string
    connector_external_id: string
    energy_kwh: number
  }
}

export interface ChargingSession {
  id: number
  reference: string
  organization_id: number
  organization: OrganizationSummary | null
  client: { id: number | null; name: string }
  station: { id: number; name: string; city: string | null }
  connector: { id: number | null; external_id: string; type: string | null; max_power_kw: number | null }
  status: ChargingSessionStatus
  payment_status: SessionPaymentStatus
  started_at: string
  started_relative: string
  ended_at: string | null
  duration_seconds: number
  duration_minutes: number
  meter_start_kwh: number
  meter_stop_kwh: number | null
  energy_kwh: number
  tariff: { id: number | null; name: string }
  plan: { id: number; name: string; discount_basis_points: number } | null
  price_per_kwh_millimes: number
  session_fee_millimes: number
  idle_fee_per_minute_millimes: number
  minimum_charge_millimes: number
  energy_gross_millimes: number
  discount_millimes: number
  energy_cost_millimes: number
  minimum_adjustment_millimes: number
  total_millimes: number
  total_amount: string
  currency: string
  payment: Payment | null
}

export interface ChargingSessionsResponse {
  data: ChargingSession[]
  summary: {
    total: number
    active: number
    completed: number
    energy_kwh: number
    revenue_millimes: number
  }
}

export interface PaymentsResponse {
  data: Payment[]
  summary: {
    total: number
    paid: number
    failed: number
    revenue_millimes: number
  }
}

export interface PaymentPayload {
  method: SimulatedPaymentMethod
  simulation_outcome: 'success' | 'declined'
  idempotency_key: string
}
