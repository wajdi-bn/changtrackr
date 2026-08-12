import type { OrganizationSummary } from './auth'

export type StationStatus = 'available' | 'charging' | 'faulted' | 'offline' | 'maintenance' | 'reserved' | 'unavailable'
export type AvailabilityOverride = 'maintenance' | 'disabled'
export type ConnectorType = 'CCS2' | 'Type 2' | 'CHAdeMO'
export type CommissioningTarget = 'external' | 'simulator' | 'inventory'
export type CommissioningStatus = 'not_provisioned' | 'provisioning' | 'provisioning_failed' | 'awaiting_connection' | 'connected' | 'offline' | 'rejected'
export type SimulatorProvisioningStatus = 'not_required' | 'not_provisioned' | 'queued' | 'provisioning' | 'provisioned' | 'failed'

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
  ocpp_commissioning_target: CommissioningTarget
  ocpp_simulator_profile: string | null
  ocpp_provisioning_status: SimulatorProvisioningStatus
  ocpp_provisioning_error: string | null
  ocpp_provisioned_at: string | null
  commissioning_status: CommissioningStatus
  ocpp_secret_configured: boolean
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

export interface StationTelemetryDay {
  date: string
  sessions: number
  energy_kwh: number
  revenue_millimes: number | null
}

export interface StationPowerPoint {
  sampled_at: string
  power_kw: number
}

export interface StationTelemetry {
  window: {
    days: 1 | 7 | 30
    from: string
    to: string
    timezone: string
  }
  summary: {
    sessions: number
    energy_kwh: number
    revenue_millimes: number | null
    power_points: number
    latest_power_kw: number | null
    last_sample_at: string | null
  }
  daily: StationTelemetryDay[]
  power: StationPowerPoint[]
  sources: {
    daily: 'charging_sessions'
    power: 'ocpp_meter_values'
    financials_visible: boolean
  }
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

export interface StationCommissioningPayload {
  organization_id?: number
  name: string
  reference: string
  ocpp_identity: string
  location_name: string
  city: string
  address: string
  latitude: number
  longitude: number
  commissioning_target: 'simulator'
  simulator_profile: string
}

export interface SimulatorHardwareProfile {
  key: string
  label: string
  description: string
  manufacturer: string
  model: string
  max_power_kw: number
  model_image: string | null
  connectors: Array<Required<Pick<ConnectorPayload, 'external_id' | 'ocpp_connector_id' | 'type' | 'current_type' | 'max_power_kw'>>>
}

export interface StationCommissioningInstructions {
  status: CommissioningStatus
  target: CommissioningTarget
  gateway_url: string
  connection_url: string
  identity: string
  username: string
  secret: string | null
  secret_visible_once: boolean
  simulator_profile: string | null
  provisioning_status: SimulatorProvisioningStatus
  provisioning_error: string | null
}

export interface StationCommissioningResult {
  data: Station
  commissioning: StationCommissioningInstructions
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

export type OcppSimulatorActionName =
  | 'connect'
  | 'disconnect'
  | 'heartbeat'
  | 'plug'
  | 'unplug'
  | 'inject_fault'
  | 'recover'
  | 'normal_cycle'
  | 'fault_recovery'

export type OcppSimulatorActionStatus = 'queued' | 'running' | 'succeeded' | 'failed'

export interface OcppSimulatorConnectorState {
  connector_id: number
  status: string
  error_code: string
  availability: string
  transaction_started: boolean
}

export interface OcppSimulatorState {
  identity: string
  started: boolean
  connected: boolean
  ws_state: number | null
  connectors: OcppSimulatorConnectorState[]
}

export type OcppSimulatorSignalCategory = 'connection' | 'heartbeat' | 'status' | 'transaction' | 'meter' | 'protocol'

export interface OcppSimulatorSignalEvent {
  id: string
  action: string
  category: OcppSimulatorSignalCategory
  connector_id: number | null
  status: string | null
  error_code: string | null
  processing_status: string
  occurred_at: string | null
  received_at: string | null
}

export interface OcppSimulatorAction {
  uuid: string
  action: OcppSimulatorActionName
  status: OcppSimulatorActionStatus
  station_id: number
  connector: Pick<Connector, 'id' | 'external_id' | 'ocpp_connector_id'> | null
  requested_by: { id: number; name: string; avatar_url: string | null } | null
  result: OcppSimulatorState | null
  failure_code: string | null
  failure_message: string | null
  queued_at: string
  started_at: string | null
  completed_at: string | null
}

export interface OcppSimulatorConsoleResponse {
  station: Station
  state: OcppSimulatorState | null
  adapter: {
    available: boolean
    message: string | null
  }
  capabilities: {
    execute: boolean
  }
  signals: {
    last_event_at: string | null
    last_heartbeat_at: string | null
    recent_count: number
    events: OcppSimulatorSignalEvent[]
  }
  history: {
    data: OcppSimulatorAction[]
    meta: {
      current_page: number
      last_page: number
      per_page: number
      total: number
    }
  }
}

export interface SimulationLabStationsResponse {
  data: Station[]
  meta: {
    current_page: number
    last_page: number
    per_page: number
    total: number
  }
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
