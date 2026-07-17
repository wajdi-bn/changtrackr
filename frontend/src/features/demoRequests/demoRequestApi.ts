import { csrfCookieRequest, httpClient } from '../../api/httpClient'
import type {
  DemoRequest,
  DemoRequestFilters,
  DemoRequestsResponse,
  ProvisionDemoRequestPayload,
  PublicDemoRequestPayload,
  RejectDemoRequestPayload,
} from '../../types/demoRequest'

export async function submitDemoRequest(payload: PublicDemoRequestPayload): Promise<{ message: string; reference: string }> {
  await csrfCookieRequest()
  const response = await httpClient.post<{ message: string; reference: string }>('/demo-requests', payload)
  return response.data
}

export async function getDemoRequests(filters: DemoRequestFilters): Promise<DemoRequestsResponse> {
  const response = await httpClient.get<DemoRequestsResponse>('/demo-requests', { params: filters })
  return response.data
}

export async function updateDemoRequestNotes(
  requestId: number,
  internal_notes: string | null,
): Promise<DemoRequest> {
  const response = await httpClient.patch<{ data: DemoRequest }>(`/demo-requests/${requestId}`, { internal_notes })
  return response.data.data
}

export async function startDemoRequestReview(requestId: number): Promise<DemoRequest> {
  const response = await httpClient.post<{ data: DemoRequest }>(`/demo-requests/${requestId}/start-review`)
  return response.data.data
}

export async function rejectDemoRequest(requestId: number, payload: RejectDemoRequestPayload): Promise<DemoRequest> {
  const response = await httpClient.post<{ data: DemoRequest }>(`/demo-requests/${requestId}/reject`, payload)
  return response.data.data
}

export async function reopenDemoRequest(requestId: number): Promise<DemoRequest> {
  const response = await httpClient.post<{ data: DemoRequest }>(`/demo-requests/${requestId}/reopen`)
  return response.data.data
}

export async function provisionDemoRequest(requestId: number, payload: ProvisionDemoRequestPayload): Promise<DemoRequest> {
  const response = await httpClient.post<{ data: DemoRequest }>(`/demo-requests/${requestId}/provision`, payload)
  return response.data.data
}

export async function issueDemoInvitation(requestId: number): Promise<DemoRequest> {
  const response = await httpClient.post<{ data: DemoRequest }>(`/demo-requests/${requestId}/invitation/issue`)
  return response.data.data
}

export async function revokeDemoInvitation(requestId: number): Promise<DemoRequest> {
  const response = await httpClient.post<{ data: DemoRequest }>(`/demo-requests/${requestId}/invitation/revoke`)
  return response.data.data
}
