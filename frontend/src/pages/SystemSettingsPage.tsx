import { useEffect, useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { App, Button, Input, InputNumber, Switch, Tag } from 'antd'
import { DatabaseZap, Globe2, Mail, RotateCcw, Save, Settings2, ShieldCheck, SlidersHorizontal, UserPlus } from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import { MountainBanner } from '../components/MountainBanner'
import { AdminDataPanel, AdminEmpty, AdminLoading, AdminMetric, AdminMetricGrid, AdminStatus } from '../components/admin/AdminSurface'
import { getPlatformSettings, updatePlatformSettings } from '../features/platform/platformApi'
import type { PlatformSetting, PlatformSettingGroup } from '../types/platform'

type SettingValue = boolean | number | string
type SettingDraft = Record<string, SettingValue>

const groupIcons: Record<PlatformSetting['group'], LucideIcon> = {
  access: Globe2,
  invitations: UserPlus,
  communications: Mail,
  governance: ShieldCheck,
}

export function SystemSettingsPage() {
  const [selectedGroup, setSelectedGroup] = useState<PlatformSetting['group']>('access')
  const [draft, setDraft] = useState<SettingDraft>({})
  const { message } = App.useApp()
  const queryClient = useQueryClient()
  const settingsQuery = useQuery({ queryKey: ['platform-system-settings'], queryFn: getPlatformSettings })
  const settings = useMemo(() => settingsQuery.data?.data.settings ?? [], [settingsQuery.data])
  const saved = useMemo(() => Object.fromEntries(settings.map((setting) => [setting.key, setting.value])) as SettingDraft, [settings])
  const dirty = JSON.stringify(saved) !== JSON.stringify(draft)
  const saveMutation = useMutation({
    mutationFn: () => updatePlatformSettings(changedValues(saved, draft)),
    onSuccess: async (response) => {
      queryClient.setQueryData(['platform-system-settings'], response)
      await queryClient.invalidateQueries({ queryKey: ['platform-audit-logs'] })
      void message.success('Platform settings updated.')
    },
    onError: () => void message.error('Platform settings could not be updated.'),
  })

  useEffect(() => setDraft(saved), [saved])

  const groups = settingsQuery.data?.data.groups ?? []
  const visibleSettings = settings.filter((setting) => setting.group === selectedGroup)
  const summary = settingsQuery.data?.summary

  return <div className="super-admin-page system-settings-page">
    <MountainBanner color="gold" breadcrumb={['Super Admin', 'Platform', 'System settings']} title="System settings" count={summary?.settings} subtitle="Control platform-wide onboarding, invitations, communications and governance from one protected workspace." />
    <AdminMetricGrid>
      <AdminMetric icon={Settings2} label="Managed settings" value={summary?.settings ?? 0} helper="Validated server-side controls" />
      <AdminMetric icon={SlidersHorizontal} label="Custom values" value={summary?.overrides ?? 0} helper="Overrides of environment defaults" tone="purple" />
      <AdminMetric icon={Globe2} label="Public controls enabled" value={summary?.enabled_controls ?? 0} helper="Registration and demo entry points" tone="blue" />
      <AdminMetric icon={DatabaseZap} label="Environment" value={summary?.environment ?? '—'} helper="Deployment configuration context" tone="orange" />
    </AdminMetricGrid>
    <AdminDataPanel title="Global controls" subtitle="Changes apply across the platform and are recorded in the audit log.">
      {settingsQuery.isLoading ? <AdminLoading rows={12} /> : settingsQuery.isError ? <AdminEmpty description="System settings could not be loaded" actionLabel="Try again" onAction={() => void settingsQuery.refetch()} /> : <div className="settings-workspace">
        <aside className="settings-group-rail">
          <header><strong>Configuration</strong><span>{groups.length} policy groups</span></header>
          <div>{groups.map((group) => <GroupSelector key={group.id} group={group} active={selectedGroup === group.id} count={settings.filter((setting) => setting.group === group.id).length} onClick={() => setSelectedGroup(group.id)} />)}</div>
        </aside>
        <section className="settings-editor">
          <header><div><span>{renderGroupIcon(selectedGroup)}</span><div><h2>{groups.find((group) => group.id === selectedGroup)?.label}</h2><p>{groups.find((group) => group.id === selectedGroup)?.description}</p></div></div><Tag color="gold">Platform-wide</Tag></header>
          <div className="settings-list">{visibleSettings.map((setting) => <SettingControl key={setting.key} setting={setting} value={draft[setting.key]} disabled={saveMutation.isPending} onChange={(value) => setDraft((current) => ({ ...current, [setting.key]: value }))} />)}</div>
          <footer className="settings-savebar"><div><strong>{dirty ? `${Object.keys(changedValues(saved, draft)).length} unsaved change(s)` : 'All changes saved'}</strong><span>Every update is attributed to your account.</span></div><Button icon={<RotateCcw size={14} />} disabled={!dirty || saveMutation.isPending} onClick={() => setDraft(saved)}>Discard</Button><Button className="admin-primary-action" type="primary" icon={<Save size={14} />} disabled={!dirty} loading={saveMutation.isPending} onClick={() => saveMutation.mutate()}>Save changes</Button></footer>
        </section>
      </div>}
    </AdminDataPanel>
    <AdminDataPanel title="Environment safeguards" subtitle="Read-only deployment posture. Sensitive values cannot be changed from this screen.">
      <div className="safeguard-grid">{settingsQuery.data?.data.safeguards.map((item) => <article key={item.label}><span><ShieldCheck size={17} /></span><div><small>{item.label}</small><strong>{item.value}</strong></div><AdminStatus status={item.status} /></article>)}</div>
    </AdminDataPanel>
  </div>
}

function GroupSelector({ group, active, count, onClick }: { group: PlatformSettingGroup; active: boolean; count: number; onClick: () => void }) {
  const Icon = groupIcons[group.id]
  return <button type="button" className={active ? 'settings-group-option active' : 'settings-group-option'} onClick={onClick}><span><Icon size={17} /></span><div><strong>{group.label}</strong><small>{group.description}</small></div><em>{count}</em></button>
}

function SettingControl({ setting, value, disabled, onChange }: { setting: PlatformSetting; value: SettingValue | undefined; disabled: boolean; onChange: (value: SettingValue) => void }) {
  return <article className="setting-control">
    <div><div><strong>{setting.label}</strong>{setting.overridden && <Tag color="purple">Custom</Tag>}</div><p>{setting.description}</p><code>{setting.key}</code></div>
    <div className="setting-control__input">
      {setting.type === 'boolean' ? <Switch checked={Boolean(value)} disabled={disabled} checkedChildren="Enabled" unCheckedChildren="Disabled" onChange={onChange} /> : setting.type === 'integer' ? <div><InputNumber value={Number(value)} min={setting.min ?? undefined} max={setting.max ?? undefined} disabled={disabled} onChange={(next) => onChange(next ?? Number(setting.default_value))} /><span>{setting.unit}</span></div> : <Input type="email" value={String(value ?? '')} disabled={disabled} onChange={(event) => onChange(event.target.value)} />}
      <small>Default: {formatSettingValue(setting.default_value, setting.unit)}</small>
    </div>
  </article>
}

function changedValues(saved: SettingDraft, draft: SettingDraft): SettingDraft {
  return Object.fromEntries(Object.entries(draft).filter(([key, value]) => saved[key] !== value))
}

function formatSettingValue(value: SettingValue, unit: string | null): string {
  if (typeof value === 'boolean') return value ? 'Enabled' : 'Disabled'
  return `${value}${unit ? ` ${unit}` : ''}`
}

function renderGroupIcon(group: PlatformSetting['group']) {
  const Icon = groupIcons[group]
  return <Icon size={20} />
}
