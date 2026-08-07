import { useEffect, useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Alert, App, Button, Progress, Switch, Tag } from 'antd'
import { Building2, CarFront, Crown, KeyRound, RadioTower, RotateCcw, Save, ShieldCheck, Users, Wrench } from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import { MountainBanner } from '../components/MountainBanner'
import { AdminDataPanel, AdminEmpty, AdminLoading, AdminMetric, AdminMetricGrid } from '../components/admin/AdminSurface'
import { getRolePermissions, updateRolePermissions } from '../features/platform/platformApi'
import type { PlatformPermission, PlatformRole } from '../types/platform'

const roleIcons: Record<PlatformRole['name'], LucideIcon> = {
  super_admin: Crown,
  admin: Building2,
  operator: RadioTower,
  technician: Wrench,
  client: CarFront,
}

export function RolesPermissionsPage() {
  const [selectedName, setSelectedName] = useState<PlatformRole['name']>('super_admin')
  const [draft, setDraft] = useState<string[]>([])
  const { message } = App.useApp()
  const queryClient = useQueryClient()
  const accessQuery = useQuery({ queryKey: ['platform-role-permissions'], queryFn: getRolePermissions })
  const roles = useMemo(() => accessQuery.data?.data.roles ?? [], [accessQuery.data])
  const permissions = useMemo(() => accessQuery.data?.data.permissions ?? [], [accessQuery.data])
  const selectedRole = roles.find((role) => role.name === selectedName) ?? roles[0]
  const groupedPermissions = useMemo(() => groupPermissions(permissions), [permissions])
  const saved = selectedRole?.permissions ?? []
  const dirty = selectedRole ? !samePermissions(saved, draft) : false
  const saveMutation = useMutation({
    mutationFn: () => {
      if (!selectedRole) throw new Error('Select a role before saving permissions.')
      return updateRolePermissions(selectedRole.name, draft)
    },
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['platform-role-permissions'] })
      await queryClient.invalidateQueries({ queryKey: ['platform-audit-logs'] })
      void message.success(`${selectedRole?.label ?? 'Role'} permissions updated.`)
    },
    onError: () => void message.error('The permission set could not be updated.'),
  })

  useEffect(() => {
    setDraft(selectedRole?.permissions ?? [])
  }, [selectedRole])

  const togglePermission = (permission: string, enabled: boolean) => {
    setDraft((current) => enabled
      ? Array.from(new Set([...current, permission])).sort()
      : current.filter((item) => item !== permission))
  }

  return <div className="super-admin-page roles-permissions-page">
    <MountainBanner color="purple" breadcrumb={['Super Admin', 'Governance', 'Roles & permissions']} title="Roles & permissions" count={accessQuery.data?.summary.roles ?? 0} subtitle="Inspect system roles, organization boundaries and effective permissions from one controlled matrix." />
    <AdminMetricGrid>
      <AdminMetric icon={ShieldCheck} label="System roles" value={accessQuery.data?.summary.roles ?? 0} helper="Fixed platform role model" />
      <AdminMetric icon={KeyRound} label="Permissions" value={accessQuery.data?.summary.permissions ?? 0} helper="Server-enforced capabilities" tone="purple" />
      <AdminMetric icon={Users} label="Assignments" value={accessQuery.data?.summary.assignments ?? 0} helper="Users holding a system role" tone="blue" />
      <AdminMetric icon={Save} label="Editable roles" value={accessQuery.data?.summary.editable_roles ?? 0} helper="Protected by Super Admin access" tone="orange" />
    </AdminMetricGrid>
    <AdminDataPanel title="System role access" subtitle="Review role scope and maintain the backend permission matrix.">
      {accessQuery.isLoading ? <AdminLoading rows={10} /> : accessQuery.isError || !selectedRole ? <AdminEmpty description="Role permissions could not be loaded" actionLabel="Try again" onAction={() => void accessQuery.refetch()} /> : <div className="permission-workspace">
        <aside className="permission-role-rail">
          <header><strong>Roles</strong><span>{roles.length} system roles</span></header>
          <div>{roles.map((role) => <RoleSelector key={role.name} role={role} active={role.name === selectedRole.name} onClick={() => setSelectedName(role.name)} />)}</div>
        </aside>
        <section className="permission-editor">
          <header className="permission-editor__header">
            <div className="permission-role-heading">
              <span>{renderRoleIcon(selectedRole.name, 21)}</span>
              <div><h2>{selectedRole.label}</h2><p>{selectedRole.description}</p><div><Tag color={selectedRole.boundary === 'Platform-wide' ? 'purple' : 'green'}>{selectedRole.boundary}</Tag><span>{selectedRole.users_count} assigned users</span></div></div>
            </div>
            <div className="permission-editor__actions">
              <Button icon={<RotateCcw size={14} />} disabled={!dirty || saveMutation.isPending} onClick={() => setDraft(saved)}>Reset</Button>
              <Button type="primary" icon={<Save size={14} />} disabled={!dirty || selectedRole.immutable} loading={saveMutation.isPending} onClick={() => saveMutation.mutate()}>Save changes</Button>
            </div>
          </header>
          <div className="permission-coverage">
            <div><span>Permission coverage</span><strong>{draft.length} / {permissions.length}</strong></div>
            <Progress percent={permissions.length ? Math.round((draft.length / permissions.length) * 100) : 0} showInfo={false} strokeColor="#6f45e8" railColor="#edf0ee" />
          </div>
          {selectedRole.immutable && <Alert type="info" showIcon title="Protected platform role" description="The Super Administrator always retains every platform permission so governance access cannot be lost." />}
          <div className="permission-groups">
            {groupedPermissions.map(([module, items]) => <section key={module} className="permission-group">
              <header><div><strong>{moduleLabel(module)}</strong><span>{items.filter((permission) => draft.includes(permission.name)).length} of {items.length} enabled</span></div><span>{module.toUpperCase()}</span></header>
              <div>{items.map((permission) => <label key={permission.name} className="permission-row"><span><strong>{permission.label}</strong><small>{permission.name}</small></span><Switch aria-label={`${selectedRole.label}: ${permission.label}`} checked={draft.includes(permission.name)} disabled={selectedRole.immutable || saveMutation.isPending} onChange={(checked) => togglePermission(permission.name, checked)} /></label>)}</div>
            </section>)}
          </div>
        </section>
      </div>}
    </AdminDataPanel>
  </div>
}

function RoleSelector({ role, active, onClick }: { role: PlatformRole; active: boolean; onClick: () => void }) {
  return <button type="button" className={active ? 'permission-role-option active' : 'permission-role-option'} onClick={onClick}>
    <span>{renderRoleIcon(role.name, 18)}</span>
    <div><strong>{role.label}</strong><small>{role.boundary}</small></div>
    <em>{role.users_count}</em>
  </button>
}

function groupPermissions(permissions: PlatformPermission[]): Array<[string, PlatformPermission[]]> {
  const grouped = new Map<string, PlatformPermission[]>()
  permissions.forEach((permission) => grouped.set(permission.module, [...(grouped.get(permission.module) ?? []), permission]))
  return Array.from(grouped.entries())
}

function samePermissions(left: string[], right: string[]): boolean {
  if (left.length !== right.length) return false
  const sortedRight = [...right].sort()
  return [...left].sort().every((permission, index) => permission === sortedRight[index])
}

function moduleLabel(module: string): string {
  return module.replaceAll('_', ' ').replace(/^./, (character) => character.toUpperCase())
}

function renderRoleIcon(role: PlatformRole['name'], size: number) {
  const Icon = roleIcons[role]
  return <Icon size={size} />
}
