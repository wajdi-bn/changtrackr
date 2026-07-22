import type { ConnectorType } from './station'

export interface Vehicle {
  id: number
  name: string
  make: string | null
  model: string | null
  model_year: number | null
  license_plate: string | null
  battery_capacity_kwh: number | null
  max_charging_power_kw: number | null
  connector_types: ConnectorType[]
  is_default: boolean
  charging_sessions_count?: number
  created_at: string
  updated_at: string
}

export interface VehiclePayload {
  name: string
  make?: string
  model?: string
  model_year?: number
  license_plate?: string
  battery_capacity_kwh?: number
  max_charging_power_kw?: number
  connector_types: ConnectorType[]
  is_default?: boolean
}
