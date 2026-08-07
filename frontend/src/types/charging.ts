import type { OrganizationSummary } from './auth'
import type { PaginationMeta } from './pagination'

export type ChargingSessionStatus = 'pending' | 'charging' | 'stopping' | 'completed' | 'interrupted' | 'failed' | 'cancelled'
export type SessionPaymentStatus = 'unpaid' | 'authorized' | 'paid' | 'failed'
export type PaymentStatus = 'pending' | 'paid' | 'failed'
export type SimulatedPaymentMethod = 'simulated_card' | 'simulated_edinar' | 'simulated_d17'
export type PaymentSimulationOutcome = 'success' | 'declined' | 'timeout' | 'provider_error'

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
  provider_event: {
    event_id: string
    type: string
    status: string
    processing_status: 'received' | 'pending_reconciliation' | 'processed' | 'requires_review'
    received_at: string
  } | null
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
  source: 'simulated' | 'ocpp'
  organization_id: number
  organization: OrganizationSummary | null
  client: { id: number | null; name: string }
  station: { id: number; name: string; city: string | null }
  connector: { id: number | null; external_id: string; type: string | null; max_power_kw: number | null }
  status: ChargingSessionStatus
  lifecycle_reason: string | null
  payment_status: SessionPaymentStatus
  started_at: string
  started_relative: string
  ended_at: string | null
  duration_seconds: number
  duration_minutes: number
  idle_started_at: string | null
  idle_seconds: number
  idle_grace_seconds: number
  idle_minutes: number
  meter_start_kwh: number
  meter_stop_kwh: number | null
  last_meter_value_at: string | null
  energy_kwh: number
  current_power_kw: number | null
  state_of_charge_percent: number | null
  limits: {
    energy_kwh: number | null
    amount_millimes: number | null
    duration_minutes: number | null
  }
  ocpp: {
    transaction_id: number
    id_tag: string
    status: string
    stop_reason: string | null
  } | null
  tariff: { id: number | null; name: string }
  plan: { id: number; name: string; discount_basis_points: number } | null
  price_per_kwh_millimes: number
  session_fee_millimes: number
  idle_fee_per_minute_millimes: number
  idle_fee_millimes: number
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

export type ChargingAttemptStatus = 'payment_pending' | 'authorized' | 'command_queued' | 'command_sent' | 'awaiting_station' | 'charging' | 'completed' | 'failed'
export type ChargingAttemptPaymentStatus = 'pending' | 'authorized' | 'released' | 'release_failed' | 'captured' | 'capture_failed' | 'failed'

export interface ChargingAttempt {
  uuid: string
  status: ChargingAttemptStatus
  payment_status: ChargingAttemptPaymentStatus
  payment_provider: string
  payment_method: SimulatedPaymentMethod
  provider_authorization_id: string | null
  preauthorized_amount_millimes: number
  preauthorized_amount: string
  currency: string
  station: { id: number; name: string; city: string | null }
  connector: { id: number; external_id: string; type: string; max_power_kw: number }
  limits: { energy_kwh: number | null; amount_millimes: number | null; duration_minutes: number | null }
  failure_code: string | null
  failure_message: string | null
  command: { uuid: string; action: string; status: string; failure_message: string | null } | null
  charging_session: ChargingSession | null
  authorized_at: string | null
  started_at: string | null
  completed_at: string | null
  expires_at: string | null
  created_at: string
}

export interface ChargingAttemptPayload {
  station_id: number
  connector_id: number
  method: SimulatedPaymentMethod
  simulation_outcome: PaymentSimulationOutcome
  idempotency_key: string
  limit_energy_kwh?: number
  limit_amount_tnd?: number
  limit_duration_minutes?: number
}

export interface ChargingSessionsResponse {
  data: ChargingSession[]
  active_session: ChargingSession | null
  summary: {
    total: number
    active: number
    completed: number
    energy_kwh: number
    revenue_millimes: number
  }
  meta: PaginationMeta
}

export interface PaymentsResponse {
  data: Payment[]
  summary: {
    total: number
    paid: number
    failed: number
    revenue_millimes: number
  }
  meta: PaginationMeta
}

export interface PaymentPayload {
  method: SimulatedPaymentMethod
  simulation_outcome: PaymentSimulationOutcome
  idempotency_key: string
}
