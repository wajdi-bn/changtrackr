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
}
