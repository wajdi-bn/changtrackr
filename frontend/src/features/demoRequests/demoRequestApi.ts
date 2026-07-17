import { httpClient } from '../../api/httpClient'
import type {
  DemoRequest,
  DemoRequestFilters,
  DemoRequestsResponse,
  ProvisionDemoRequestPayload,
  PublicDemoRequestPayload,
} from '../../types/demoRequest'

export async function submitDemoRequest(payload: PublicDemoRequestPayload): Promise<{ message: string; reference: string }> {
  const response = await httpClient.post<{ message: string; reference: string }>('/demo-requests', payload)
  return response.data
}

export async function getDemoRequests(filters: DemoRequestFilters): Promise<DemoRequestsResponse> {
  const response = await httpClient.get<DemoRequestsResponse>('/demo-requests', { params: filters })
  return response.data
}

export async function updateDemoRequest(
  requestId: number,
  payload: Partial<Pick<DemoRequest, 'status' | 'scheduled_at' | 'internal_notes'>>,
): Promise<DemoRequest> {
  const response = await httpClient.patch<{ data: DemoRequest }>(`/demo-requests/${requestId}`, payload)
  return response.data.data
}

export async function provisionDemoRequest(requestId: number, payload: ProvisionDemoRequestPayload): Promise<DemoRequest> {
  const response = await httpClient.post<{ data: DemoRequest }>(`/demo-requests/${requestId}/provision`, payload)
  return response.data.data
}

export async function resendDemoInvitation(requestId: number): Promise<DemoRequest> {
  const response = await httpClient.post<{ data: DemoRequest }>(`/demo-requests/${requestId}/invitation/resend`)
  return response.data.data
}

export async function revokeDemoInvitation(requestId: number): Promise<DemoRequest> {
  const response = await httpClient.post<{ data: DemoRequest }>(`/demo-requests/${requestId}/invitation/revoke`)
  return response.data.data
}
