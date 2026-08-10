import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { App, Button, Card, Divider, Form, Input, Modal, Radio, Segmented, Select, Skeleton, Switch } from 'antd'
import { BellRing, CalendarClock, Clock3, CreditCard, KeyRound, LockKeyhole, MailCheck, MonitorCog, Moon, Navigation, ShieldAlert, Sun, Wrench } from 'lucide-react'
import { MountainBanner } from '../components/MountainBanner'
import { getAccountPreferences, updateAccountPreferences } from '../features/account/accountPreferenceApi'
import { changeAccountPassword, getAccountSecurity, type ChangePasswordPayload } from '../features/account/accountSecurityApi'
import { useAuth } from '../features/auth/useAuth'
import {
  getNotificationPreferences,
  updateNotificationPreferences,
  type NotificationPreferences,
} from '../features/notifications/notificationApi'
import type { ThemeMode } from '../features/theme/themePreference'
import { useTheme } from '../features/theme/useTheme'
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
  const { user, updateCurrentUser, primaryRole } = useAuth()
  const { mode: themeMode, resolvedTheme, setMode: setThemeMode } = useTheme()
  const [passwordForm] = Form.useForm<ChangePasswordPayload>()
  const [passwordModalOpen, setPasswordModalOpen] = useState(false)
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
  const nearbyRadiusMutation = useMutation({
    mutationFn: updateAccountPreferences,
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['account-preferences'] })
      void message.success('Nearby station radius updated.')
    },
    onError: () => void message.error('Nearby station radius could not be updated.'),
  })
  const accountSecurityQuery = useQuery({
    queryKey: ['account-security'],
    queryFn: getAccountSecurity,
  })
  const passwordMutation = useMutation({
    mutationFn: changeAccountPassword,
    onSuccess: () => {
      passwordForm.resetFields()
      setPasswordModalOpen(false)
      void message.success('Password updated successfully.')
    },
    onError: () => void message.error('Password could not be updated. Check your current password and try again.'),
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

      <Card className="settings-card settings-appearance-card" title="Appearance">
        <div className="settings-appearance-layout">
          <div className="settings-intro settings-intro--appearance">
            <MonitorCog size={20} />
            <div>
              <strong>Interface theme</strong>
              <p>Use the system appearance or keep ChargeTrackr consistently light or dark on this browser.</p>
            </div>
          </div>
          <div className="theme-preference-control">
            <Segmented
              value={themeMode}
              onChange={(value) => setThemeMode(value as ThemeMode)}
              options={[
                { value: 'system', label: 'System', icon: <MonitorCog size={16} /> },
                { value: 'light', label: 'Light', icon: <Sun size={16} /> },
                { value: 'dark', label: 'Dark', icon: <Moon size={16} /> },
              ]}
              aria-label="Interface theme"
            />
            <small>Currently using {resolvedTheme} appearance.</small>
          </div>
        </div>
      </Card>

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

      <div className="settings-support-grid">
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

      <Card className="settings-card" title="Account security">
        <div className="settings-intro settings-intro--security">
          <LockKeyhole size={20} />
          <div><strong>Sign-in and access protection</strong><p>Your email identity and authentication methods are protected server-side. Role and organization access are managed by authorized administrators.</p></div>
        </div>
        <Divider />
        {accountSecurityQuery.isLoading || !accountSecurityQuery.data ? <Skeleton active paragraph={{ rows: 3 }} /> : (() => {
          const security = accountSecurityQuery.data
          const providerLabel = security.sign_in_providers.map((provider) => provider.charAt(0).toUpperCase() + provider.slice(1)).join(' · ')
          return <div className="account-security-summary">
            <div className="account-security-row">
              <span className="account-security-icon"><MailCheck size={18} /></span>
              <div><strong>Account email</strong><p>{security.email} · {security.email_verified ? 'Verified' : 'Pending verification'}</p></div>
            </div>
            <div className="account-security-row">
              <span className="account-security-icon"><KeyRound size={18} /></span>
              <div>
                <strong>{security.password_login_enabled ? 'Password sign-in' : 'Google sign-in'}</strong>
                <p>{security.password_login_enabled ? 'Use a private password to access your account.' : `${providerLabel || 'Google'} manages access to this account.`}</p>
              </div>
              {security.password_login_enabled && <Button onClick={() => setPasswordModalOpen(true)}>Change password</Button>}
            </div>
          </div>
        })()}
      </Card>
      </div>

      {primaryRole === 'client' && <Card className="settings-card settings-nearby-card" title="Station discovery">
        <div className="settings-intro settings-intro--nearby">
          <Navigation size={20} />
          <div><strong>Near me search radius</strong><p>When location access is active, only charging stations inside this distance are shown. You can change it before every trip.</p></div>
        </div>
        <Divider />
        {accountPreferencesQuery.isLoading ? <Skeleton active paragraph={{ rows: 2 }} /> : <div className="nearby-radius-preference">
          <Radio.Group
            optionType="button"
            buttonStyle="solid"
            value={accountPreferencesQuery.data?.near_me_radius_km ?? 25}
            disabled={nearbyRadiusMutation.isPending}
            onChange={(event) => nearbyRadiusMutation.mutate({ near_me_radius_km: Number(event.target.value) })}
            options={[5, 10, 25, 50, 100].map((radius) => ({ value: radius, label: `${radius} km` }))}
          />
          <div>
            <strong>{accountPreferencesQuery.data?.near_me_radius_km ?? 25} km around your current location</strong>
            <span>The radius is applied only after you select Near me and authorize browser geolocation.</span>
          </div>
        </div>}
      </Card>}

      <Modal
        title="Change password"
        open={passwordModalOpen}
        onCancel={() => { if (!passwordMutation.isPending) setPasswordModalOpen(false) }}
        okText="Update password"
        okButtonProps={{ loading: passwordMutation.isPending }}
        onOk={() => passwordForm.submit()}
        destroyOnHidden
      >
        <p className="password-modal-copy">Confirm your current password before choosing a new one. The new password must contain at least eight characters, including uppercase letters and numbers.</p>
        <Form form={passwordForm} layout="vertical" onFinish={(values) => passwordMutation.mutate(values)}>
          <Form.Item name="current_password" label="Current password" rules={[{ required: true, message: 'Enter your current password.' }]}>
            <Input.Password autoComplete="current-password" />
          </Form.Item>
          <Form.Item name="password" label="New password" rules={[{ required: true, min: 8, message: 'Use at least eight characters.' }]}>
            <Input.Password autoComplete="new-password" />
          </Form.Item>
          <Form.Item name="password_confirmation" label="Confirm new password" dependencies={['password']} rules={[{ required: true, message: 'Confirm your new password.' }, ({ getFieldValue }) => ({ validator(_, value) { return !value || getFieldValue('password') === value ? Promise.resolve() : Promise.reject(new Error('Passwords do not match.')) } })]}>
            <Input.Password autoComplete="new-password" />
          </Form.Item>
        </Form>
      </Modal>
    </div>
  )
}
