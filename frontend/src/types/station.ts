import type { OrganizationSummary } from './auth'

export type StationStatus = 'available' | 'charging' | 'faulted' | 'offline' | 'maintenance'
export type ConnectorType = 'CCS2' | 'Type 2' | 'CHAdeMO'

export interface Connector {
  id: number
  external_id: string
  type: ConnectorType
  current_type: 'AC' | 'DC'
  max_power_kw: number
  status: StationStatus
  error_code: string | null
  last_status_at: string | null
  last_status_relative: string | null
}

export interface Station {
  id: number
  organization_id: number
  organization: OrganizationSummary | null
  name: string
  reference: string
  location_name: string
  city: string
  location: string
  address: string
  latitude: number
  longitude: number
  status: StationStatus
  max_power_kw: number
  model: string
  manufacturer: string
  ocpp_version: 'OCPP 1.6J' | 'OCPP 2.0.1'
  model_image: string | null
  last_heartbeat_at: string | null
  last_heartbeat_relative: string
  uptime_percent: number
  energy_today_kwh: number
  sessions_today: number
  utilization_percent: number
  revenue_today: number
  open_alerts_count: number
  connectors_count: number
  available_connectors_count: number
  connectors: Connector[]
}

export interface StationPayload {
  name: string
  reference: string
  location_name: string
  city: string
  address: string
  latitude: number
  longitude: number
  status: StationStatus
  max_power_kw: number
  model: string
  manufacturer: string
  ocpp_version: Station['ocpp_version']
  model_image?: string | null
}

export interface ConnectorPayload {
  external_id: string
  type: ConnectorType
  current_type: 'AC' | 'DC'
  max_power_kw: number
  status: StationStatus
  error_code?: string | null
}

export interface StationsResponse {
  data: Station[]
  summary: {
    stations: number
    connectors: number
    availability_percent: number
  }
  meta: {
    current_page: number
    last_page: number
    per_page: number
    total: number
  }
}
