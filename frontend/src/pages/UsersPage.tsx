import { useDeferredValue, useMemo, useState, type ReactNode } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  Alert,
  App,
  Avatar,
  Button,
  Drawer,
  Dropdown,
  Empty,
  Form,
  Input,
  Modal,
  Pagination,
  Popconfirm,
  Select,
  Skeleton,
  Tooltip,
} from 'antd'
import dayjs from 'dayjs'
import {
  ChevronDown,
  Download,
  Eye,
  Grid2X2,
  List,
  Mail,
  MapPin,
  PencilLine,
  Phone,
  Plus,
  Search,
  Table2,
  Trash2,
  UserRound,
} from 'lucide-react'
import { MountainBanner } from '../components/MountainBanner'
import { UserDirectoryTabs } from '../components/UserDirectoryTabs'
import { useAuth } from '../features/auth/useAuth'
import {
  createManagedUser,
  deactivateManagedUser,
  exportManagedUsers,
  getManagedUsers,
  updateManagedUser,
} from '../features/users/userApi'
import type { UserRole } from '../types/auth'
import type {
  EmployeeRole,
  LastLoginFilter,
  ManagedUser,
  ManagedUserFilters,
  ManagedUserPayload,
  ManagedUserStatus,
} from '../types/user'

type UserView = 'table' | 'list' | 'grid'
type UserEditor = ManagedUser | null | undefined

const roleFilterOptions: Array<{ value: Exclude<EmployeeRole, 'super_admin'>; label: string }> = [
  { value: 'admin', label: 'Administrator' },
  { value: 'operator', label: 'Operator' },
  { value: 'technician', label: 'Technician' },
]

const manageableRoleOptions: Array<{ value: Extract<EmployeeRole, 'operator' | 'technician'>; label: string }> = [
  { value: 'operator', label: 'Operator' },
  { value: 'technician', label: 'Technician' },
]

const teamOptions = ['Management', 'Network Operations', 'Field Maintenance']

export function UsersPage() {
  const { user: currentUser } = useAuth()
  const [search, setSearch] = useState('')
  const [role, setRole] = useState<EmployeeRole | undefined>()
  const [status, setStatus] = useState<ManagedUserStatus | undefined>()
  const [team, setTeam] = useState<string | undefined>()
  const [lastLogin, setLastLogin] = useState<LastLoginFilter | undefined>()
  const [view, setView] = useState<UserView>('table')
  const [page, setPage] = useState(1)
  const [selectedUser, setSelectedUser] = useState<ManagedUser | null>(null)
  const [editor, setEditor] = useState<UserEditor>(undefined)
  const deferredSearch = useDeferredValue(search)
  const queryClient = useQueryClient()
  const { message } = App.useApp()
  const canCreate = currentUser?.permissions.includes('users.create') ?? false
  const canUpdate = currentUser?.permissions.includes('users.update') ?? false
  const canDeactivate = currentUser?.permissions.includes('users.delete') ?? false

  const filters = useMemo<ManagedUserFilters>(() => ({
    search: deferredSearch.trim() || undefined,
    role,
    status,
    team,
    last_login: lastLogin,
    page,
    per_page: 20,
  }), [deferredSearch, lastLogin, page, role, status, team])

  const usersQuery = useQuery({
    queryKey: ['managed-users', filters],
    queryFn: () => getManagedUsers(filters),
  })

  const refreshUsers = () => queryClient.invalidateQueries({ queryKey: ['managed-users'] })
  const saveUser = useMutation({
    mutationFn: ({ managedUser, payload }: { managedUser: ManagedUser | null; payload: ManagedUserPayload }) => managedUser
      ? updateManagedUser(managedUser.id, payload)
      : createManagedUser(payload),
    onSuccess: async (savedUser, variables) => {
      await refreshUsers()
      if (selectedUser?.id === savedUser.id) setSelectedUser(savedUser)
      setEditor(undefined)
      void message.success(variables.managedUser ? 'Employee updated successfully.' : 'Employee created successfully.')
    },
    onError: () => void message.error('The user could not be saved. Check the email, role, and password.'),
  })
  const deactivateUser = useMutation({
    mutationFn: deactivateManagedUser,
    onSuccess: async (managedUser) => {
      await refreshUsers()
      if (selectedUser?.id === managedUser.id) setSelectedUser(managedUser)
      void message.success('Employee account deactivated and active sessions revoked.')
    },
    onError: () => void message.error('This user cannot be deactivated. Keep at least one active administrator.'),
  })
  const exportUsers = useMutation({
    mutationFn: (format: 'csv' | 'json') => exportManagedUsers(filters, format),
    onSuccess: (blob, format) => {
      const url = URL.createObjectURL(blob)
      const anchor = document.createElement('a')
      anchor.href = url
      anchor.download = `organization-employees.${format}`
      anchor.click()
      URL.revokeObjectURL(url)
      void message.success(`User export generated as ${format.toUpperCase()}.`)
    },
    onError: () => void message.error('The user export could not be generated.'),
  })

  const users = usersQuery.data?.data ?? []
  const meta = usersQuery.data?.meta
  const count = meta?.total ?? 0

  const updateFilter = <T,>(setter: (value: T | undefined) => void, value: T | undefined) => {
    setter(value)
    setPage(1)
  }

  return <div className="users-page">
    <div className="users-banner-wrap">
      <MountainBanner
        color="cyan"
        breadcrumb={['Administrator', 'Users', 'Employees']}
        title="Employees"
        count={count}
        subtitle="Review administrators and manage operators and technicians in your organization."
      />
    </div>

    <UserDirectoryTabs />

    <div className="users-toolbar">
      <Input
        value={search}
        onChange={(event) => { setSearch(event.target.value); setPage(1) }}
        prefix={<Search size={14} />}
        placeholder="Search users"
        allowClear
      />
      <FilterSelect value={role} placeholder="Role: All" options={roleFilterOptions} onChange={(value) => updateFilter(setRole, value)} />
      <FilterSelect value={status} placeholder="Status: All" options={[
        { value: 'active', label: 'Active' },
        { value: 'inactive', label: 'Inactive' },
        { value: 'pending', label: 'Pending' },
      ]} onChange={(value) => updateFilter(setStatus, value)} />
      <FilterSelect value={team} placeholder="Team: All" options={teamOptions.map((value) => ({ value, label: value }))} onChange={(value) => updateFilter(setTeam, value)} />
      <FilterSelect value={lastLogin} placeholder="Last login: Any time" options={[
        { value: 'today', label: 'Today' },
        { value: 'week', label: 'This week' },
        { value: 'month', label: 'This month' },
      ]} onChange={(value) => updateFilter(setLastLogin, value)} />
      <ViewMode value={view} onChange={setView} />
      <Dropdown menu={{
        items: [{ key: 'csv', label: 'Export CSV' }, { key: 'json', label: 'Export JSON' }],
        onClick: ({ key }) => exportUsers.mutate(key as 'csv' | 'json'),
      }}>
        <Button className="users-export-button" loading={exportUsers.isPending}><Download size={14} />Export<ChevronDown size={13} /></Button>
      </Dropdown>
      {canCreate && <Button className="users-add-button" type="primary" onClick={() => setEditor(null)}><Plus size={15} />Add employee</Button>}
    </div>

    {usersQuery.isError && <Alert className="users-api-error" type="error" showIcon title="Unable to load users" description="Make sure the Laravel API is running, then retry." action={<Button size="small" onClick={() => void usersQuery.refetch()}>Retry</Button>} />}

    <SectionCard title="Employee management" subtitle="Administrators are read-only here; operators and technicians are managed by the organization administrator.">
      {usersQuery.isLoading ? <UsersLoading /> : users.length === 0 ? <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="No user matches the current filters" /> : <>
        {view === 'table' && <UsersTable users={users} currentUserId={currentUser?.id} canUpdate={canUpdate} canDeactivate={canDeactivate} onSelect={setSelectedUser} onEdit={setEditor} onDeactivate={(managedUser) => deactivateUser.mutate(managedUser.id)} />}
        {view === 'list' && <UsersList users={users} currentUserId={currentUser?.id} canUpdate={canUpdate} canDeactivate={canDeactivate} onSelect={setSelectedUser} onEdit={setEditor} onDeactivate={(managedUser) => deactivateUser.mutate(managedUser.id)} />}
        {view === 'grid' && <UsersGrid users={users} currentUserId={currentUser?.id} canUpdate={canUpdate} canDeactivate={canDeactivate} onSelect={setSelectedUser} onEdit={setEditor} onDeactivate={(managedUser) => deactivateUser.mutate(managedUser.id)} />}
      </>}
      {meta && meta.last_page > 1 && <Pagination className="users-pagination" current={meta.current_page} total={meta.total} pageSize={meta.per_page} showSizeChanger={false} onChange={setPage} />}
    </SectionCard>

    <UserDetailDrawer
      user={selectedUser}
      canEdit={Boolean(canUpdate && selectedUser && isOrganizationManagedRole(selectedUser.roles[0]))}
      onClose={() => setSelectedUser(null)}
      onEdit={(managedUser) => setEditor(managedUser)}
    />
    {editor !== undefined && <UserEditorModal
      user={editor}
      submitting={saveUser.isPending}
      onClose={() => setEditor(undefined)}
      onSubmit={(payload) => saveUser.mutate({ managedUser: editor, payload })}
    />}
  </div>
}

function FilterSelect<T extends string>({ value, placeholder, options, onChange }: { value?: T; placeholder: string; options: Array<{ value: T; label: string }>; onChange: (value?: T) => void }) {
  return <Select<T> value={value} placeholder={placeholder} options={options} allowClear onChange={onChange} />
}

function ViewMode({ value, onChange }: { value: UserView; onChange: (value: UserView) => void }) {
  const items: Array<{ value: UserView; label: string; icon: ReactNode }> = [
    { value: 'table', label: 'Table view', icon: <Table2 size={16} /> },
    { value: 'list', label: 'List view', icon: <List size={16} /> },
    { value: 'grid', label: 'Grid view', icon: <Grid2X2 size={16} /> },
  ]

  return <div className="users-view-mode">{items.map((item) => <Tooltip key={item.value} title={item.label}><button type="button" className={value === item.value ? 'active' : ''} aria-label={item.label} onClick={() => onChange(item.value)}>{item.icon}</button></Tooltip>)}</div>
}

function UsersTable(props: UsersViewProps) {
  return <div className="users-table-wrap"><table className="users-table">
    <thead><tr>{['Avatar', 'Full name', 'Email', 'Role', 'Team', 'Status', 'Last login', 'Main activity summary', 'Actions'].map((heading) => <th key={heading}>{heading}</th>)}</tr></thead>
    <tbody>{props.users.map((managedUser) => <tr key={managedUser.id}>
      <td><UserAvatar user={managedUser} size={36} /></td>
      <td><strong>{managedUser.name}</strong></td>
      <td>{managedUser.email}</td>
      <td>{roleLabel(managedUser.roles[0])}</td>
      <td>{managedUser.team ?? '-'}</td>
      <td><UserStatus status={managedUser.status} /></td>
      <td>{formatLastLogin(managedUser.last_login_at)}</td>
      <td className="users-activity-cell">{activitySummary(managedUser)}</td>
      <td><UserActions {...props} managedUser={managedUser} /></td>
    </tr>)}</tbody>
  </table></div>
}

function UsersList(props: UsersViewProps) {
  return <div className="users-list">{props.users.map((managedUser) => <article key={managedUser.id}>
    <div className="users-list-identity"><UserAvatar user={managedUser} size={44} /><span><strong>{managedUser.name}</strong><small>{managedUser.email} - {roleLabel(managedUser.roles[0])} - {managedUser.team ?? 'No team'}</small></span></div>
    <p>{activitySummary(managedUser)}</p>
    <UserStatus status={managedUser.status} />
    <UserActions {...props} managedUser={managedUser} />
  </article>)}</div>
}

function UsersGrid(props: UsersViewProps) {
  return <div className="users-grid">{props.users.map((managedUser) => <article key={managedUser.id}>
    <header><div><UserAvatar user={managedUser} size={48} /><span><strong>{managedUser.name}</strong><small>{roleLabel(managedUser.roles[0])}</small></span></div><UserStatus status={managedUser.status} /></header>
    <p>{activitySummary(managedUser)}</p>
    <footer><span>{managedUser.team ?? 'No team assigned'}</span><UserActions {...props} managedUser={managedUser} /></footer>
  </article>)}</div>
}

interface UsersViewProps {
  users: ManagedUser[]
  currentUserId?: number
  canUpdate: boolean
  canDeactivate: boolean
  onSelect: (user: ManagedUser) => void
  onEdit: (user: ManagedUser) => void
  onDeactivate: (user: ManagedUser) => void
}

function UserActions({ managedUser, currentUserId, canUpdate, canDeactivate, onSelect, onEdit, onDeactivate }: Omit<UsersViewProps, 'users'> & { managedUser: ManagedUser }) {
  const self = managedUser.id === currentUserId
  const manageable = isOrganizationManagedRole(managedUser.roles[0])
  const deactivationAvailable = canDeactivate && manageable && !self && managedUser.status !== 'inactive'

  return <div className="user-row-actions">
    <Tooltip title="View user"><button type="button" className="view" aria-label={`View ${managedUser.name}`} onClick={() => onSelect(managedUser)}><Eye size={15} /></button></Tooltip>
    {canUpdate && manageable && <Tooltip title="Edit user"><button type="button" aria-label={`Edit ${managedUser.name}`} onClick={() => onEdit(managedUser)}><PencilLine size={15} /></button></Tooltip>}
    {deactivationAvailable && <Popconfirm title="Deactivate this account?" description="All active API sessions will be revoked." okText="Deactivate" okButtonProps={{ danger: true }} onConfirm={() => onDeactivate(managedUser)}><Tooltip title="Deactivate user"><button type="button" className="danger" aria-label={`Deactivate ${managedUser.name}`}><Trash2 size={15} /></button></Tooltip></Popconfirm>}
  </div>
}

function UserDetailDrawer({ user, canEdit, onClose, onEdit }: { user: ManagedUser | null; canEdit: boolean; onClose: () => void; onEdit: (user: ManagedUser) => void }) {
  return <Drawer className="user-detail-drawer" size={510} open={Boolean(user)} onClose={onClose} title={user ? <div className="user-drawer-title"><UserAvatar user={user} size={48} /><span><strong>{user.name}</strong><small>{roleLabel(user.roles[0])} - {user.team ?? 'No team'}</small></span></div> : null}>
    {user && <div className="user-drawer-content">
      <div className="user-contact-grid">
        <InfoPanel icon={<Mail size={14} />} label="Email" value={user.email} />
        <InfoPanel icon={<Phone size={14} />} label="Phone" value={user.phone ?? 'Not provided'} />
        <InfoPanel icon={<MapPin size={14} />} label="Address" value={user.address ?? 'Not provided'} />
        <InfoPanel icon={<UserRound size={14} />} label="Last login" value={formatLastLogin(user.last_login_at)} />
      </div>
      <SectionCard title={`${roleLabel(user.roles[0])} detail`} subtitle={roleDetailSubtitle(user.roles[0])}>
        <div className="user-metrics">{roleMetrics(user).map((metric) => <div key={metric.label}><small>{metric.label}</small><strong>{metric.value}</strong></div>)}</div>
      </SectionCard>
      <SectionCard title="Account history" subtitle="Account lifecycle and recent access metadata.">
        <div className="user-history">
          <p><span>Account created</span><time>{formatDate(user.created_at)}</time></p>
          <p><span>Profile last updated</span><time>{formatDate(user.updated_at)}</time></p>
          <p><span>Last authenticated session</span><time>{formatLastLogin(user.last_login_at)}</time></p>
        </div>
      </SectionCard>
      {canEdit && <Button className="users-drawer-edit" type="primary" onClick={() => onEdit(user)}>Edit user</Button>}
    </div>}
  </Drawer>
}

interface UserFormValues extends ManagedUserPayload {
  password?: string
}

function UserEditorModal({ user, submitting, onClose, onSubmit }: { user: ManagedUser | null; submitting: boolean; onClose: () => void; onSubmit: (payload: ManagedUserPayload) => void }) {
  const [form] = Form.useForm<UserFormValues>()
  const initialValues: Partial<UserFormValues> = user ? {
    name: user.name,
    email: user.email,
    phone: user.phone,
    avatar_url: user.avatar_url,
    team: user.team,
    address: user.address,
    status: user.status as ManagedUserStatus,
    role: user.roles[0],
  } : { status: 'active', role: 'operator', team: 'Network Operations' }

  const submit = (values: UserFormValues) => {
    const payload: ManagedUserPayload = { ...values }
    if (!payload.password) delete payload.password
    onSubmit(payload)
  }

  return <Modal className="user-editor-modal" width={680} open title={<div><strong>{user ? `Edit ${user.name}` : 'Add employee'}</strong><small>Organization administrators create operators and technicians only.</small></div>} footer={null} onCancel={onClose} destroyOnHidden>
    <Form form={form} layout="vertical" initialValues={initialValues} onFinish={submit}>
      <div className="user-form-grid">
        <Form.Item name="name" label="Full name" rules={[{ required: true, message: 'Enter the full name.' }]}><Input placeholder="New Organization User" /></Form.Item>
        <Form.Item name="email" label="Email" rules={[{ required: true }, { type: 'email' }]}><Input placeholder="new.user@chargetrackr.tn" /></Form.Item>
        <Form.Item name="role" label="Role" rules={[{ required: true }]}><Select options={manageableRoleOptions} /></Form.Item>
        <Form.Item name="team" label="Team or department"><Select options={teamOptions.map((value) => ({ value, label: value }))} /></Form.Item>
        <Form.Item name="phone" label="Phone"><Input placeholder="+216 00 000 000" /></Form.Item>
        <Form.Item name="address" label="Address"><Input placeholder="Tunis, Tunisia" /></Form.Item>
        <Form.Item name="status" label="Status" rules={[{ required: true }]}><Select options={[{ value: 'active', label: 'Active' }, { value: 'inactive', label: 'Inactive' }, { value: 'pending', label: 'Pending' }]} /></Form.Item>
        <Form.Item name="avatar_url" label="Avatar URL"><Input placeholder="/assets/avatar-vendor-1.jpg" /></Form.Item>
        <Form.Item name="password" label={user ? 'New password' : 'Temporary password'} rules={user ? [{ min: 8 }] : [{ required: true }, { min: 8 }]}><Input.Password placeholder={user ? 'Leave empty to keep current password' : 'Minimum 8 characters'} /></Form.Item>
      </div>
      <div className="user-modal-actions"><Button onClick={onClose}>Cancel</Button><Button className="users-save-button" type="primary" htmlType="submit" loading={submitting}>{user ? 'Save changes' : 'Create employee'}</Button></div>
    </Form>
  </Modal>
}

function SectionCard({ title, subtitle, children }: { title: string; subtitle?: string; children: ReactNode }) {
  return <section className="prototype-section-card"><header><h2>{title}</h2>{subtitle && <p>{subtitle}</p>}</header><div>{children}</div></section>
}

function UserAvatar({ user, size }: { user: ManagedUser; size: number }) {
  return <Avatar className={`managed-user-avatar managed-user-avatar--${user.id % 4}`} size={size} src={user.avatar_url ?? undefined}>{initials(user.name)}</Avatar>
}

function UserStatus({ status }: { status: string }) {
  return <span className={`user-status user-status--${status}`}><i />{status}</span>
}

function InfoPanel({ icon, label, value }: { icon: ReactNode; label: string; value: string }) {
  return <div className="user-info-panel"><span>{icon}</span><div><small>{label}</small><strong>{value}</strong></div></div>
}

function UsersLoading() {
  return <div className="users-loading">{Array.from({ length: 5 }, (_, index) => <Skeleton key={index} active avatar paragraph={{ rows: 1 }} />)}</div>
}

function initials(name: string): string {
  return name.split(' ').filter(Boolean).slice(0, 2).map((part) => part[0]).join('').toUpperCase()
}

function roleLabel(role?: UserRole): string {
  return roleFilterOptions.find((option) => option.value === role)?.label ?? (role === 'super_admin' ? 'Super Administrator' : 'User')
}

function isOrganizationManagedRole(role?: UserRole): role is 'operator' | 'technician' {
  return role === 'operator' || role === 'technician'
}

function formatLastLogin(value: string | null): string {
  if (!value) return 'Never'
  const date = dayjs(value)
  const minutes = dayjs().diff(date, 'minute')
  if (minutes < 1) return 'Just now'
  if (minutes < 60) return `${minutes} min ago`
  const hours = dayjs().diff(date, 'hour')
  if (hours < 24) return `${hours} h ago`
  const days = dayjs().diff(date, 'day')
  if (days < 7) return `${days} d ago`
  return date.format('DD MMM YYYY')
}

function formatDate(value: string | null): string {
  return value ? dayjs(value).format('DD MMM YYYY, HH:mm') : 'Not available'
}

function activitySummary(user: ManagedUser): string {
  const role = user.roles[0]
  if (role === 'technician') return `${user.activity.assigned_interventions} assigned interventions and ${user.activity.assigned_alerts} alerts.`
  if (role === 'operator') return 'Station supervision, alert coordination, and charging operations.'
  return 'Organization users, tariff configuration, and reporting administration.'
}

function roleDetailSubtitle(role?: UserRole): string {
  if (role === 'technician') return 'Assigned alerts, interventions, and field activity.'
  if (role === 'operator') return 'Station supervision, alerts, sessions, and operational activity.'
  return 'Organization user administration, tariff access, and reporting.'
}

function roleMetrics(user: ManagedUser): Array<{ label: string; value: string | number }> {
  const role = user.roles[0]
  if (role === 'technician') return [{ label: 'Assigned alerts', value: user.activity.assigned_alerts }, { label: 'Interventions', value: user.activity.assigned_interventions }]
  if (role === 'operator') return [{ label: 'Account status', value: user.status }, { label: 'Team', value: user.team ?? 'Not assigned' }]
  return [{ label: 'Organization', value: user.organization?.name ?? 'Platform' }, { label: 'Account status', value: user.status }]
}
