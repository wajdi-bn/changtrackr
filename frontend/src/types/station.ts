import type { OrganizationSummary } from './auth'

export type StationStatus = 'available' | 'charging' | 'faulted' | 'offline' | 'maintenance' | 'reserved' | 'unavailable'
export type AvailabilityOverride = 'maintenance' | 'disabled'
export type ConnectorType = 'CCS2' | 'Type 2' | 'CHAdeMO'

export interface Connector {
  id: number
  external_id: string
  qr_token: string
  ocpp_connector_id: number | null
  type: ConnectorType
  current_type: 'AC' | 'DC'
  max_power_kw: number
  status: StationStatus
  availability_reason: string | null
  availability_source: string | null
  availability_calculated_at: string | null
  error_code: string | null
  last_status_at: string | null
  last_status_relative: string | null
  ocpp_status: string | null
  ocpp_error_code: string | null
  ocpp_last_status_at: string | null
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
  ocpp_managed: boolean
  remote_start_available: boolean
  remote_start_unavailable_reason: RemoteStartUnavailableReason | null
  availability_override: AvailabilityOverride | null
  maintenance_intervention_id: number | null
  availability_reason: string | null
  availability_source: string | null
  availability_calculated_at: string | null
  ocpp_identity: string | null
  ocpp_registration_status: string | null
  ocpp_status: string | null
  ocpp_error_code: string | null
  ocpp_connected_at: string | null
  ocpp_disconnected_at: string | null
  ocpp_last_message_at: string | null
  ocpp_last_status_at: string | null
  ocpp_is_connected: boolean
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
  status?: StationStatus
  availability_override?: AvailabilityOverride | null
  max_power_kw: number
  model: string
  manufacturer: string
  ocpp_version: Station['ocpp_version']
  model_image?: string | null
}

export interface StationMapMarker {
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
  ocpp_managed: boolean
  remote_start_available: boolean
  remote_start_unavailable_reason: RemoteStartUnavailableReason | null
  availability_reason: string | null
  availability_source: string | null
  availability_calculated_at: string | null
  max_power_kw: number
  model_image: string | null
  uptime_percent: number
  connectors_count: number
  available_connectors_count: number
  connectors: Connector[]
}

export interface StationMapFilters {
  search?: string
  status?: StationStatus
  city?: string
  connector_type?: ConnectorType
  min_power_kw?: number
  available_only?: boolean
  north?: number
  south?: number
  east?: number
  west?: number
}

export interface StationMapResponse {
  data: StationMapMarker[]
  summary: {
    stations: number
    available_connectors: number
    by_status: Partial<Record<StationStatus, number>>
  }
  facets: {
    cities: string[]
  }
}

export interface ConnectorPayload {
  external_id: string
  ocpp_connector_id?: number
  type: ConnectorType
  current_type: 'AC' | 'DC'
  max_power_kw: number
  status?: StationStatus
  error_code?: string | null
}

export type OcppCommandAction = 'Reset' | 'UnlockConnector' | 'ChangeAvailability'
export type OcppCommandStatus = 'queued' | 'sent' | 'accepted' | 'rejected' | 'failed' | 'timed_out'

export interface OcppCommand {
  uuid: string
  action: OcppCommandAction
  status: OcppCommandStatus
  station_id: number
  connector: Pick<Connector, 'id' | 'external_id' | 'ocpp_connector_id'> | null
  requested_by: { id: number; name: string; avatar_url: string | null } | null
  result: Record<string, unknown> | null
  failure_code: string | null
  failure_message: string | null
  queued_at: string
  sent_at: string | null
  responded_at: string | null
  expires_at: string
  created_at: string
  updated_at: string
}

export interface OcppCommandsResponse {
  data: OcppCommand[]
  meta: {
    current_page: number
    last_page: number
    per_page: number
    total: number
  }
}

export interface MaintenanceModeResponse {
  station: Station
  command: OcppCommand | null
  ocpp_sync: 'queued' | 'not_connected'
}

export type RemoteStartUnavailableReason =
  | 'not_ocpp_managed'
  | 'organization_inactive'
  | 'maintenance'
  | 'disabled'
  | 'station_offline'
  | 'station_unavailable'
  | 'no_available_connector'

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
