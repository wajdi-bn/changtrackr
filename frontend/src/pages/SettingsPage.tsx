import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { App, Card, Divider, Radio, Select, Skeleton, Switch } from 'antd'
import { BellRing, CalendarClock, Clock3, CreditCard, ShieldAlert, Wrench } from 'lucide-react'
import { MountainBanner } from '../components/MountainBanner'
import { getAccountPreferences, updateAccountPreferences } from '../features/account/accountPreferenceApi'
import { useAuth } from '../features/auth/useAuth'
import {
  getNotificationPreferences,
  updateNotificationPreferences,
  type NotificationPreferences,
} from '../features/notifications/notificationApi'
import { deviceTimeZone, formatDateTime } from '../utils/dateTime'

const timeZoneOptions = [
  'Africa/Tunis', 'Africa/Algiers', 'Africa/Cairo', 'Africa/Casablanca', 'Africa/Tripoli',
  'Asia/Dubai', 'Asia/Riyadh', 'Asia/Tokyo', 'Asia/Singapore',
  'Europe/London', 'Europe/Paris', 'Europe/Rome', 'Europe/Berlin', 'Europe/Istanbul',
  'America/New_York', 'America/Chicago', 'America/Los_Angeles', 'UTC',
].map((value) => ({ value, label: value }))

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
  const { user, updateCurrentUser } = useAuth()
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
  const accountPreferencesQuery = useQuery({
    queryKey: ['account-preferences'],
    queryFn: getAccountPreferences,
  })
  const timezoneMutation = useMutation({
    mutationFn: updateAccountPreferences,
    onSuccess: async (preferences) => {
      if (user) updateCurrentUser({ ...user, timezone: preferences.timezone })
      await queryClient.invalidateQueries({ queryKey: ['account-preferences'] })
      void message.success('Time zone preference updated.')
    },
    onError: () => void message.error('Time zone preference could not be updated.'),
  })

  const preferences = preferencesQuery.data
  const preferredTimeZone = accountPreferencesQuery.data?.timezone ?? null
  const effectiveTimeZone = preferredTimeZone ?? deviceTimeZone()

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

      <Card className="settings-card" title="Regional display">
        <div className="settings-intro settings-intro--timezone">
          <Clock3 size={20} />
          <div><strong>Date and time display</strong><p>By default, ChargeTrackr follows the local time zone of the device currently in use.</p></div>
        </div>
        <Divider />
        {accountPreferencesQuery.isLoading ? <Skeleton active paragraph={{ rows: 2 }} /> : <div className="timezone-preference">
          <Radio.Group
            value={preferredTimeZone === null ? 'device' : 'custom'}
            onChange={(event) => {
              if (event.target.value === 'device') timezoneMutation.mutate({ timezone: null })
              else timezoneMutation.mutate({ timezone: preferredTimeZone ?? deviceTimeZone() })
            }}
            disabled={timezoneMutation.isPending}
          >
            <Radio value="device"><strong>Use device time zone</strong><small>Detected: {deviceTimeZone()}</small></Radio>
            <Radio value="custom"><strong>Choose another time zone</strong><small>Useful only when you need to view operations from another region.</small></Radio>
          </Radio.Group>
          {preferredTimeZone !== null && <Select
            showSearch
            optionFilterProp="label"
            value={preferredTimeZone}
            options={timeZoneOptions}
            loading={timezoneMutation.isPending}
            onChange={(timezone) => timezoneMutation.mutate({ timezone })}
            aria-label="Time zone preference"
          />}
          <div className="timezone-preview"><span>Preview</span><strong>{formatDateTime(new Date().toISOString(), effectiveTimeZone)}</strong><small>{effectiveTimeZone}</small></div>
        </div>}
      </Card>
    </div>
  )
}
