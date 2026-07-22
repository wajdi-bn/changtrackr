import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { App, Card, Divider, Skeleton, Switch } from 'antd'
import { BellRing, CalendarClock, CreditCard, ShieldAlert, Wrench } from 'lucide-react'
import { MountainBanner } from '../components/MountainBanner'
import {
  getNotificationPreferences,
  updateNotificationPreferences,
  type NotificationPreferences,
} from '../features/notifications/notificationApi'

const preferenceRows: Array<{
  key: keyof NotificationPreferences
  title: string
  description: string
  icon: typeof ShieldAlert
}> = [
  { key: 'email_alerts', title: 'Operational alerts', description: 'Critical, warning, and information alerts affecting stations and connectors.', icon: ShieldAlert },
  { key: 'email_assignments', title: 'Assignments', description: 'When an alert or intervention is assigned to you.', icon: Wrench },
  { key: 'email_interventions', title: 'Intervention updates', description: 'Status changes for work assigned to you.', icon: Wrench },
  { key: 'email_maintenance', title: 'Maintenance schedule', description: 'Planned maintenance creation and reminders before a visit.', icon: CalendarClock },
  { key: 'email_sla', title: 'SLA escalation', description: 'Approaching and overdue alert deadlines.', icon: BellRing },
  { key: 'email_payments', title: 'Payment incidents', description: 'Payment failures and provider availability incidents.', icon: CreditCard },
]

export function SettingsPage() {
  const queryClient = useQueryClient()
  const { message } = App.useApp()
  const preferencesQuery = useQuery({
    queryKey: ['notification-preferences'],
    queryFn: getNotificationPreferences,
  })
  const updateMutation = useMutation({
    mutationFn: updateNotificationPreferences,
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['notification-preferences'] })
      void message.success('Notification preferences updated.')
    },
    onError: () => void message.error('Notification preferences could not be updated.'),
  })

  const preferences = preferencesQuery.data

  return (
    <div className="settings-page">
      <MountainBanner
        color="purple"
        breadcrumb={['Account', 'Settings']}
        title="Settings"
        subtitle="Control which operational updates are also delivered by email."
      />

      <Card className="settings-card" title="Notification delivery">
        <div className="settings-intro">
          <BellRing size={20} />
          <div><strong>In-app notifications stay enabled</strong><p>These controls only change email delivery for your own account. Critical operational context remains available in the notification center.</p></div>
        </div>
        <Divider />
        {preferencesQuery.isLoading || !preferences ? <Skeleton active paragraph={{ rows: 7 }} /> : (
          <div className="notification-preference-list">
            {preferenceRows.map(({ key, title, description, icon: Icon }) => (
              <div className="notification-preference-row" key={key}>
                <span className="notification-preference-icon"><Icon size={18} /></span>
                <div><strong>{title}</strong><p>{description}</p></div>
                <Switch
                  checked={preferences[key]}
                  loading={updateMutation.isPending}
                  onChange={(checked) => updateMutation.mutate({ [key]: checked })}
                  aria-label="Email notification preference"
                />
              </div>
            ))}
          </div>
        )}
      </Card>
    </div>
  )
}
