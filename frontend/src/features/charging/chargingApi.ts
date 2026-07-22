import { httpClient } from '../../api/httpClient'
import type {
  ChargingSession,
  ChargingAttempt,
  ChargingAttemptPayload,
  ChargingSessionsResponse,
  ChargingSessionStatus,
  Payment,
  PaymentPayload,
  PaymentsResponse,
  PaymentStatus,
  SessionPaymentStatus,
} from '../../types/charging'
import type { ExportFormat } from '../../components/ExportDropdown'

export interface SessionFilters {
  search?: string
  status?: ChargingSessionStatus
  payment_status?: SessionPaymentStatus
}

export async function getChargingSessions(filters: SessionFilters = {}): Promise<ChargingSessionsResponse> {
  const response = await httpClient.get<ChargingSessionsResponse>('/charging-sessions', { params: filters })
  return response.data
}

export async function exportChargingSessions(filters: SessionFilters, format: ExportFormat): Promise<Blob> {
  const response = await httpClient.get<Blob>('/charging-sessions/export', {
    params: { ...filters, format },
    responseType: 'blob',
  })
  return response.data
}

export async function startChargingSession(payload: { station_id: number; connector_id: number }): Promise<ChargingSession> {
  const response = await httpClient.post<{ data: ChargingSession }>('/charging-sessions', payload)
  return response.data.data
}

export async function startChargingAttempt(payload: ChargingAttemptPayload): Promise<ChargingAttempt> {
  const response = await httpClient.post<{ data: ChargingAttempt }>('/charging-attempts', payload)
  return response.data.data
}

export async function getChargingAttempt(uuid: string): Promise<ChargingAttempt> {
  const response = await httpClient.get<{ data: ChargingAttempt }>(`/charging-attempts/${uuid}`)
  return response.data.data
}

export async function getChargingAttempts(): Promise<ChargingAttempt[]> {
  const response = await httpClient.get<{ data: ChargingAttempt[] }>('/charging-attempts')
  return response.data.data
}

export async function stopChargingSession(sessionId: number): Promise<ChargingSession> {
  const response = await httpClient.post<{ data: ChargingSession }>(`/charging-sessions/${sessionId}/stop`)
  return response.data.data
}

export async function remoteStopChargingSession(sessionId: number): Promise<ChargingSession> {
  const response = await httpClient.post<{ data: ChargingSession }>(`/charging-sessions/${sessionId}/remote-stop`)
  return response.data.data
}

export async function getPayments(filters: { search?: string; status?: PaymentStatus } = {}): Promise<PaymentsResponse> {
  const response = await httpClient.get<PaymentsResponse>('/payments', { params: filters })
  return response.data
}

export async function exportPayments(filters: { search?: string; status?: PaymentStatus }, format: ExportFormat): Promise<Blob> {
  const response = await httpClient.get<Blob>('/payments/export', {
    params: { ...filters, format },
    responseType: 'blob',
  })
  return response.data
}

export async function processPayment(sessionId: number, payload: PaymentPayload): Promise<Payment> {
  const response = await httpClient.post<{ data: Payment }>(`/charging-sessions/${sessionId}/payments`, payload)
  return response.data.data
}
