import { httpClient } from '../../api/httpClient'
import type { UserNotification, UserNotificationsResponse } from '../../types/notification'

export async function getNotifications(limit = 20): Promise<UserNotificationsResponse> {
  const response = await httpClient.get<UserNotificationsResponse>('/notifications', { params: { limit } })
  return response.data
}

export async function markNotificationRead(id: number): Promise<UserNotification> {
  const response = await httpClient.patch<UserNotification>(`/notifications/${id}/read`)
  return response.data
}

export async function markAllNotificationsRead(): Promise<number> {
  const response = await httpClient.post<{ updated: number }>('/notifications/read-all')
  return response.data.updated
}
