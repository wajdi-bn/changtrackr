import { httpClient } from '../../api/httpClient'
import type {
  Connector,
  ConnectorPayload,
  Station,
  StationMapFilters,
  StationMapResponse,
  StationPayload,
  StationStatus,
  StationsResponse,
} from '../../types/station'

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

export async function createStation(payload: StationPayload): Promise<Station> {
  const response = await httpClient.post<{ data: Station }>('/stations', payload)
  return response.data.data
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
