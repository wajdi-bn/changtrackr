export type NotificationSeverity = 'info' | 'warning' | 'critical'
export type NotificationDeliveryStatus = 'pending' | 'processing' | 'delivered' | 'failed'

export interface UserNotification {
  id: number
  category: 'alert' | 'assignment' | 'intervention' | 'maintenance' | 'sla' | 'payment' | string
  severity: NotificationSeverity
  title: string
  message: string
  action_url: string | null
  entity: { type: string | null; id: number | null }
  data: Record<string, unknown>
  is_read: boolean
  read_at: string | null
  created_at: string
  created_relative: string
  deliveries: Array<{
    channel: 'in_app' | 'email' | string
    status: NotificationDeliveryStatus
    attempts: number
    delivered_at: string | null
    failed_at: string | null
  }>
}

export interface UserNotificationsResponse {
  data: UserNotification[]
  summary: { unread: number; total: number }
}
