import { httpClient } from '../../api/httpClient'
import type {
  Connector,
  ConnectorPayload,
  MaintenanceModeResponse,
  OcppCommand,
  OcppCommandsResponse,
  Station,
  StationCommissioningPayload,
  StationCommissioningResult,
  StationMapFilters,
  StationMapResponse,
  StationPayload,
  StationStatus,
  StationTelemetry,
  StationsResponse,
} from '../../types/station'

export interface ConnectorQrTarget {
  station_id: number
  connector_id: number
  station_name: string
  connector_external_id: string
}

export async function getStations(filters: { search?: string; status?: StationStatus }): Promise<StationsResponse> {
  const response = await httpClient.get<StationsResponse>('/stations', { params: filters })
  return response.data
}

export async function getStationMap(filters: StationMapFilters): Promise<StationMapResponse> {
  const response = await httpClient.get<StationMapResponse>('/stations/map', { params: filters })
  return response.data
}

export async function getStation(stationId: number): Promise<Station> {
  const response = await httpClient.get<{ data: Station }>(`/stations/${stationId}`)
  return response.data.data
}

export async function getStationTelemetry(stationId: number, days: 1 | 7 | 30): Promise<StationTelemetry> {
  const response = await httpClient.get<{ data: StationTelemetry }>(`/stations/${stationId}/telemetry`, {
    params: { days },
  })
  return response.data.data
}

export async function resolveConnectorQr(token: string): Promise<ConnectorQrTarget> {
  const response = await httpClient.get<{ data: ConnectorQrTarget }>(`/connector-qr/${encodeURIComponent(token)}`)
  return response.data.data
}

export async function createStation(payload: StationPayload): Promise<Station> {
  const response = await httpClient.post<{ data: Station }>('/stations', payload)
  return response.data.data
}

export async function commissionStation(payload: StationCommissioningPayload): Promise<StationCommissioningResult> {
  const response = await httpClient.post<StationCommissioningResult>('/stations/commission', payload)
  return response.data
}

export async function rotateStationCredentials(stationId: number): Promise<StationCommissioningResult> {
  const response = await httpClient.post<StationCommissioningResult>(`/stations/${stationId}/commissioning/rotate-credentials`)
  return response.data
}

export async function updateStation(stationId: number, payload: Partial<StationPayload>): Promise<Station> {
  const response = await httpClient.patch<{ data: Station }>(`/stations/${stationId}`, payload)
  return response.data.data
}

export async function deleteStation(stationId: number): Promise<void> {
  await httpClient.delete(`/stations/${stationId}`)
}

export async function createConnector(stationId: number, payload: ConnectorPayload): Promise<Connector> {
  const response = await httpClient.post<{ data: Connector }>(`/stations/${stationId}/connectors`, payload)
  return response.data.data
}

export async function updateConnector(stationId: number, connectorId: number, payload: ConnectorPayload): Promise<Connector> {
  const response = await httpClient.put<{ data: Connector }>(`/stations/${stationId}/connectors/${connectorId}`, payload)
  return response.data.data
}

export async function deleteConnector(stationId: number, connectorId: number): Promise<void> {
  await httpClient.delete(`/stations/${stationId}/connectors/${connectorId}`)
}

export async function getStationCommands(stationId: number): Promise<OcppCommandsResponse> {
  const response = await httpClient.get<OcppCommandsResponse>(`/stations/${stationId}/commands`)
  return response.data
}

export async function restartStation(stationId: number): Promise<OcppCommand> {
  const response = await httpClient.post<{ data: OcppCommand }>(`/stations/${stationId}/commands/reset`)
  return response.data.data
}

export async function unlockStationConnector(stationId: number, connectorId: number): Promise<OcppCommand> {
  const response = await httpClient.post<{ data: OcppCommand }>(`/stations/${stationId}/connectors/${connectorId}/commands/unlock`)
  return response.data.data
}

export async function setStationMaintenanceMode(stationId: number, enabled: boolean): Promise<MaintenanceModeResponse> {
  const response = await httpClient.put<MaintenanceModeResponse>(`/stations/${stationId}/maintenance`, { enabled })
  return response.data
}
