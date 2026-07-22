import { httpClient } from '../../api/httpClient'
import type { UserNotification, UserNotificationsResponse } from '../../types/notification'

export interface NotificationPreferences {
  email_alerts: boolean
  email_assignments: boolean
  email_interventions: boolean
  email_maintenance: boolean
  email_sla: boolean
  email_payments: boolean
}

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

export async function getNotificationPreferences(): Promise<NotificationPreferences> {
  const response = await httpClient.get<{ data: NotificationPreferences }>('/notification-preferences')
  return response.data.data
}

export async function updateNotificationPreferences(payload: Partial<NotificationPreferences>): Promise<NotificationPreferences> {
  const response = await httpClient.put<{ data: NotificationPreferences }>('/notification-preferences', payload)
  return response.data.data
}
