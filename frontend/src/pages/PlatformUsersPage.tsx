import { useDeferredValue, useEffect, useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { App, Avatar, Button, Drawer, Form, Input, Modal, Popconfirm, Select, Space, Table, Tooltip } from 'antd'
import type { ColumnsType } from 'antd/es/table'
import { Eye, PencilLine, Search, ShieldCheck, UserCheck, UserRoundX, Users } from 'lucide-react'
import dayjs from 'dayjs'
import { useSearchParams } from 'react-router-dom'
import { MountainBanner } from '../components/MountainBanner'
import { ExportDropdown, type ExportFormat } from '../components/ExportDropdown'
import { AdminDataPanel, AdminEmpty, AdminLoading, AdminMetric, AdminMetricGrid, AdminStatus } from '../components/admin/AdminSurface'
import { useAuth } from '../features/auth/useAuth'
import { cancelEmployeeInvitation, deactivateManagedUser, exportManagedUsers, getManagedUsers, remindEmployeeInvitation, renewEmployeeInvitation, updateManagedUser } from '../features/users/userApi'
import { httpClient } from '../api/httpClient'
import type { EmployeeRole, ManagedUser, ManagedUserFilterStatus, ManagedUserPayload } from '../types/user'

interface OrganizationOption { id: number; name: string; status: string }
const roleOptions: Array<{ value: EmployeeRole; label: string }> = [{ value: 'super_admin', label: 'Super Administrator' }, { value: 'admin', label: 'Organization Administrator' }, { value: 'operator', label: 'Operator' }, { value: 'technician', label: 'Technician' }]
const statusOptions: Array<{ value: ManagedUserFilterStatus; label: string }> = [{ value: 'active', label: 'Active' }, { value: 'inactive', label: 'Suspended' }, { value: 'pending', label: 'Pending activation' }, { value: 'expired', label: 'Invitation expired' }, { value: 'revoked', label: 'Invitation cancelled' }]

export function PlatformUsersPage() {
  const [searchParams] = useSearchParams()
  const { user: currentUser } = useAuth()
  const [search, setSearch] = useState(() => searchParams.get('search') ?? '')
  const [role, setRole] = useState<EmployeeRole | undefined>()
  const [status, setStatus] = useState<ManagedUserFilterStatus | undefined>()
  const [organizationId, setOrganizationId] = useState<number | undefined>()
  const [page, setPage] = useState(1)
  const [selectedUser, setSelectedUser] = useState<ManagedUser | null>(null)
  const [editor, setEditor] = useState<ManagedUser | null>(null)
  const deferredSearch = useDeferredValue(search)
  const queryClient = useQueryClient()
  const { message } = App.useApp()
  const filters = useMemo(() => ({ search: deferredSearch.trim() || undefined, role, status, organization_id: organizationId, page, per_page: 20 }), [deferredSearch, organizationId, page, role, status])
  const usersQuery = useQuery({ queryKey: ['platform-users', filters], queryFn: () => getManagedUsers(filters) })
  const organizationsQuery = useQuery({ queryKey: ['platform-organizations', 'options'], queryFn: async () => (await httpClient.get<{ data: OrganizationOption[] }>('/organizations')).data.data })
  const refresh = () => queryClient.invalidateQueries({ queryKey: ['platform-users'] })
  const saveMutation = useMutation({ mutationFn: ({ id, payload }: { id: number; payload: Partial<ManagedUserPayload> }) => updateManagedUser(id, payload), onSuccess: async (saved) => { await refresh(); setSelectedUser(saved); setEditor(null); void message.success('User account updated.') }, onError: () => void message.error('The user could not be updated. Check the role, organization and account state.') })
  const deactivateMutation = useMutation({ mutationFn: deactivateManagedUser, onSuccess: async (saved) => { await refresh(); setSelectedUser(saved); void message.success('Account suspended and active sessions revoked.') }, onError: () => void message.error('This account cannot be suspended. The last active organization administrator must remain active.') })
  const invitationMutation = useMutation({ mutationFn: ({ id, action }: { id: number; action: 'remind' | 'renew' | 'cancel' }) => action === 'remind' ? remindEmployeeInvitation(id) : action === 'renew' ? renewEmployeeInvitation(id) : cancelEmployeeInvitation(id), onSuccess: async (saved) => { await refresh(); setSelectedUser(saved); void message.success('Invitation lifecycle updated.') }, onError: () => void message.error('This invitation action is not currently available.') })
  const exportMutation = useMutation({ mutationFn: (format: ExportFormat) => exportManagedUsers(filters, format), onSuccess: (blob, format) => { const url = URL.createObjectURL(blob); const anchor = document.createElement('a'); anchor.href = url; anchor.download = `platform-users.${format}`; anchor.click(); URL.revokeObjectURL(url) }, onError: () => void message.error('The platform user export could not be generated.') })
  const users = usersQuery.data?.data ?? []
  const summary = usersQuery.data?.summary
  const organizations = organizationsQuery.data ?? []

  useEffect(() => {
    setSearch(searchParams.get('search') ?? '')
    setPage(1)
  }, [searchParams])
  const columns: ColumnsType<ManagedUser> = [
    { title: 'User', key: 'user', render: (_, user) => <div className="admin-primary-cell"><Avatar src={user.avatar_url ?? undefined}>{initials(user.name)}</Avatar><span><strong>{user.name}</strong><small>{user.email}</small></span></div> },
    { title: 'Role', key: 'role', render: (_, user) => roleLabel(user.roles[0]) },
    { title: 'Organization', key: 'organization', render: (_, user) => <div className="admin-stack-cell"><span>{user.organization?.name ?? 'Platform'}</span><small>{user.team ?? 'No team assigned'}</small></div> },
    { title: 'Status', key: 'status', width: 142, render: (_, user) => <AdminStatus status={lifecycleStatus(user)} /> },
    { title: 'Last login', dataIndex: 'last_login_at', width: 140, render: (value: string | null) => formatLastLogin(value) },
    { title: 'Created', dataIndex: 'created_at', width: 125, render: (value: string | null) => value ? dayjs(value).format('DD MMM YYYY') : '-' },
    { title: '', key: 'actions', width: 108, align: 'right', render: (_, user) => <Space size={3} className="admin-row-actions"><Tooltip title="View details"><Button aria-label={`View ${user.name}`} type="text" icon={<Eye size={15} />} onClick={() => setSelectedUser(user)} /></Tooltip><Tooltip title="Edit account"><Button aria-label={`Edit ${user.name}`} type="text" icon={<PencilLine size={15} />} onClick={() => setEditor(user)} /></Tooltip>{user.id !== currentUser?.id && user.status === 'active' && <Popconfirm title="Suspend this account?" description="Active sessions will be revoked immediately." okText="Suspend" okButtonProps={{ danger: true }} onConfirm={() => deactivateMutation.mutate(user.id)}><Tooltip title="Suspend account"><Button aria-label={`Suspend ${user.name}`} type="text" danger icon={<UserRoundX size={15} />} /></Tooltip></Popconfirm>}</Space> },
  ]

  return <div className="super-admin-page platform-users-page">
    <MountainBanner color="purple" breadcrumb={['Super Admin', 'Platform users']} title="Platform users" count={summary?.total ?? 0} subtitle="Supervise elevated accounts, organization assignments and access lifecycle across the platform." />
    <AdminMetricGrid><AdminMetric icon={Users} label="Employees" value={summary?.total ?? 0} helper="Platform and organization accounts" /><AdminMetric icon={UserCheck} label="Active" value={summary?.active ?? 0} helper="Accounts with current access" tone="blue" /><AdminMetric icon={ShieldCheck} label="Administrators" value={(summary?.by_role.super_admin ?? 0) + (summary?.by_role.admin ?? 0)} helper="Platform and tenant administrators" tone="purple" /><AdminMetric icon={UserRoundX} label="Pending or suspended" value={(summary?.pending ?? 0) + (summary?.inactive ?? 0)} helper="Accounts requiring attention" tone="orange" /></AdminMetricGrid>
    <AdminDataPanel title="Administrators and users" subtitle="Filter global employee accounts and inspect their organization assignment." extra={<ExportDropdown loading={exportMutation.isPending} onExport={(format) => exportMutation.mutate(format)} />}>
      <div className="admin-table-toolbar admin-table-toolbar--users"><Input value={search} onChange={(event) => { setSearch(event.target.value); setPage(1) }} prefix={<Search size={15} />} placeholder="Search name, email or team" allowClear /><Select value={role} onChange={(value) => { setRole(value); setPage(1) }} allowClear placeholder="All roles" options={roleOptions} /><Select value={organizationId} onChange={(value) => { setOrganizationId(value); setPage(1) }} allowClear showSearch optionFilterProp="label" placeholder="All organizations" options={organizations.map((organization) => ({ value: organization.id, label: organization.name }))} /><Select value={status} onChange={(value) => { setStatus(value); setPage(1) }} allowClear placeholder="All statuses" options={statusOptions} /></div>
      {usersQuery.isLoading ? <AdminLoading /> : users.length === 0 ? <AdminEmpty description="No platform user matches the current filters" /> : <Table rowKey="id" className="admin-data-table" columns={columns} dataSource={users} pagination={{ current: usersQuery.data?.meta.current_page, total: usersQuery.data?.meta.total, pageSize: usersQuery.data?.meta.per_page, showSizeChanger: false, onChange: setPage }} scroll={{ x: 980 }} onRow={(record) => ({ onDoubleClick: () => setSelectedUser(record) })} />}
    </AdminDataPanel>
    <PlatformUserDrawer user={selectedUser} currentUserId={currentUser?.id} invitationLoading={invitationMutation.isPending} onClose={() => setSelectedUser(null)} onEdit={setEditor} onInvitation={(id, action) => invitationMutation.mutate({ id, action })} />
    {editor && <PlatformUserEditor user={editor} self={editor.id === currentUser?.id} organizations={organizations} submitting={saveMutation.isPending} onClose={() => setEditor(null)} onSubmit={(payload) => saveMutation.mutate({ id: editor.id, payload })} />}
  </div>
}

function PlatformUserDrawer({ user, currentUserId, invitationLoading, onClose, onEdit, onInvitation }: { user: ManagedUser | null; currentUserId?: number; invitationLoading: boolean; onClose: () => void; onEdit: (user: ManagedUser) => void; onInvitation: (id: number, action: 'remind' | 'renew' | 'cancel') => void }) {
  return <Drawer className="admin-detail-drawer" size={570} open={Boolean(user)} onClose={onClose} title="User account details" extra={user ? <Button icon={<PencilLine size={14} />} onClick={() => onEdit(user)}>Edit</Button> : null}>{user && <div className="platform-user-detail"><header><Avatar size={58} src={user.avatar_url ?? undefined}>{initials(user.name)}</Avatar><div><h2>{user.name}</h2><p>{user.email}</p><AdminStatus status={lifecycleStatus(user)} /></div></header><section className="platform-user-facts"><div><small>Role</small><strong>{roleLabel(user.roles[0])}</strong></div><div><small>Organization</small><strong>{user.organization?.name ?? 'Platform'}</strong></div><div><small>Team</small><strong>{user.team ?? 'Not assigned'}</strong></div><div><small>Last login</small><strong>{formatLastLogin(user.last_login_at)}</strong></div></section><section className="platform-user-activity"><h3>Account activity</h3><div><span><strong>{user.activity.assigned_alerts}</strong><small>Assigned alerts</small></span><span><strong>{user.activity.assigned_interventions}</strong><small>Interventions</small></span><span><strong>{user.activity.charging_sessions}</strong><small>Sessions</small></span></div></section>{user.invitation && <section className="platform-invitation"><h3>Activation invitation</h3><div><span>Status</span><AdminStatus status={user.invitation.status} /></div><div><span>Last sent</span><strong>{formatDate(user.invitation.last_sent_at)}</strong></div><div><span>Expires</span><strong>{formatDate(user.invitation.expires_at)}</strong></div>{['operator', 'technician'].includes(user.roles[0]) && <Space wrap><Button disabled={!user.invitation.can_remind} loading={invitationLoading} onClick={() => onInvitation(user.id, 'remind')}>Send reminder</Button><Button disabled={!user.invitation.can_renew} loading={invitationLoading} onClick={() => onInvitation(user.id, 'renew')}>Renew invitation</Button><Button danger disabled={!user.invitation.can_cancel} loading={invitationLoading} onClick={() => onInvitation(user.id, 'cancel')}>Cancel invitation</Button></Space>}</section>}{user.id === currentUserId && <p className="platform-self-note">This is your current account. Your own elevated role and active status cannot be removed.</p>}</div>}</Drawer>
}

function PlatformUserEditor({ user, self, organizations, submitting, onClose, onSubmit }: { user: ManagedUser; self: boolean; organizations: OrganizationOption[]; submitting: boolean; onClose: () => void; onSubmit: (payload: Partial<ManagedUserPayload>) => void }) {
  const [form] = Form.useForm<ManagedUserPayload>()
  const selectedRole = Form.useWatch('role', form) ?? user.roles[0]
  const needsOrganization = selectedRole !== 'super_admin'
  return <Modal className="admin-editor-modal" width={680} open title={<span><strong>Edit platform user</strong><small>Update identity, access role and organization assignment.</small></span>} destroyOnHidden footer={null} onCancel={onClose}><Form form={form} layout="vertical" initialValues={{ name: user.name, email: user.email, phone: user.phone, team: user.team, address: user.address, avatar_url: user.avatar_url, status: user.status as 'active' | 'inactive' | 'pending', role: user.roles[0], organization_id: user.organization?.id ?? null }} onFinish={onSubmit}><div className="admin-form-grid"><Form.Item name="name" label="Full name" rules={[{ required: true }]}><Input /></Form.Item><Form.Item name="email" label="Email" rules={[{ required: true }, { type: 'email' }]}><Input /></Form.Item><Form.Item name="role" label="Role" rules={[{ required: true }]}><Select disabled={self} options={roleOptions} /></Form.Item><Form.Item name="organization_id" label="Organization" rules={[{ required: needsOrganization, message: 'Select an organization for this role.' }]}><Select disabled={self || !needsOrganization} allowClear showSearch optionFilterProp="label" options={organizations.filter((organization) => organization.status === 'active').map((organization) => ({ value: organization.id, label: organization.name }))} /></Form.Item><Form.Item name="status" label="Account status" rules={[{ required: true }]}><Select disabled={self} options={[{ value: 'active', label: 'Active' }, { value: 'inactive', label: 'Suspended' }, ...(user.status === 'pending' ? [{ value: 'pending', label: 'Pending activation' } as const] : [])]} /></Form.Item><Form.Item name="team" label="Team"><Input /></Form.Item><Form.Item name="phone" label="Phone"><Input /></Form.Item><Form.Item name="address" label="Address"><Input /></Form.Item></div><div className="admin-modal-actions"><Button onClick={onClose}>Cancel</Button><Button type="primary" htmlType="submit" loading={submitting}>Save changes</Button></div></Form></Modal>
}

function roleLabel(role?: EmployeeRole): string { return roleOptions.find((option) => option.value === role)?.label ?? 'Employee' }
function lifecycleStatus(user: ManagedUser): string { return user.status === 'pending' ? user.invitation?.status ?? 'pending' : user.status }
function initials(name: string): string { return name.split(' ').filter(Boolean).slice(0, 2).map((part) => part[0]).join('').toUpperCase() }
function formatLastLogin(value: string | null): string {
  if (!value) return 'Never'
  const date = dayjs(value)
  const minutes = dayjs().diff(date, 'minute')
  if (minutes < 1) return 'Just now'
  if (minutes < 60) return `${minutes} min ago`
  const hours = dayjs().diff(date, 'hour')
  if (hours < 24) return `${hours} h ago`
  const days = dayjs().diff(date, 'day')
  return days < 7 ? `${days} d ago` : date.format('DD MMM YYYY')
}
function formatDate(value: string | null): string { return value ? dayjs(value).format('DD MMM YYYY, HH:mm') : 'Not available' }
