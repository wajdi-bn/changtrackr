import { useDeferredValue, useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { App, Avatar, Button, Drawer, Form, Input, Modal, Select, Space, Table, Tag, Tooltip } from 'antd'
import type { ColumnsType } from 'antd/es/table'
import type { Key } from 'react'
import { Building2, CircleDollarSign, Eye, MapPin, PencilLine, Plus, Search, Users, Zap } from 'lucide-react'
import dayjs from 'dayjs'
import { MountainBanner } from '../components/MountainBanner'
import { AdminDataPanel, AdminEmpty, AdminLoading, AdminMetric, AdminMetricGrid, AdminStatus } from '../components/admin/AdminSurface'
import { httpClient } from '../api/httpClient'

type OrganizationStatus = 'active' | 'suspended'
interface OrganizationItem { id: number; name: string; slug: string; contact_email: string | null; contact_phone: string | null; status: OrganizationStatus; users_count: number; stations_count: number; charging_sessions_count: number; settled_revenue_millimes: number; created_at: string | null }
interface OrganizationDetail extends OrganizationItem { open_alerts_count: number; admins: Array<{ id: number; name: string; email: string; status: string }>; stations_preview: Array<{ id: number; name: string; reference: string; status: string }> }
interface OrganizationsResponse { data: OrganizationItem[]; summary: { total: number; active: number; suspended: number } }

const getOrganizations = async (filters: { search?: string; status?: OrganizationStatus }) => (await httpClient.get<OrganizationsResponse>('/organizations', { params: filters })).data
const getOrganization = async (id: number) => (await httpClient.get<{ data: OrganizationDetail }>(`/organizations/${id}`)).data.data
const saveOrganization = async (id: number | null, values: Partial<OrganizationItem>) => (id === null ? await httpClient.post<{ data: OrganizationItem }>('/organizations', values) : await httpClient.put<{ data: OrganizationItem }>(`/organizations/${id}`, values)).data.data

export function OrganizationsPage() {
  const [search, setSearch] = useState('')
  const [status, setStatus] = useState<OrganizationStatus | undefined>()
  const [selectedId, setSelectedId] = useState<number | null>(null)
  const [selectedKeys, setSelectedKeys] = useState<Key[]>([])
  const [editor, setEditor] = useState<OrganizationItem | null | undefined>(undefined)
  const deferredSearch = useDeferredValue(search)
  const queryClient = useQueryClient()
  const { message, modal } = App.useApp()
  const filters = useMemo(() => ({ search: deferredSearch.trim() || undefined, status }), [deferredSearch, status])
  const organizationsQuery = useQuery({ queryKey: ['platform-organizations', filters], queryFn: () => getOrganizations(filters) })
  const detailQuery = useQuery({ queryKey: ['platform-organization', selectedId], queryFn: () => getOrganization(selectedId as number), enabled: selectedId !== null })
  const refresh = async () => { await queryClient.invalidateQueries({ queryKey: ['platform-organizations'] }); await queryClient.invalidateQueries({ queryKey: ['platform-organization'] }); await queryClient.invalidateQueries({ queryKey: ['dashboard'] }) }
  const saveMutation = useMutation({ mutationFn: ({ id, values }: { id: number | null; values: Partial<OrganizationItem> }) => saveOrganization(id, values), onSuccess: async () => { await refresh(); setEditor(undefined); void message.success('Organization saved successfully.') }, onError: () => void message.error('The organization could not be saved. Review the entered information.') })
  const organizations = organizationsQuery.data?.data ?? []
  const summary = organizationsQuery.data?.summary
  const totals = organizations.reduce((result, item) => ({ users: result.users + item.users_count, stations: result.stations + item.stations_count, revenue: result.revenue + item.settled_revenue_millimes }), { users: 0, stations: 0, revenue: 0 })

  const confirmStatus = (organization: OrganizationItem, nextStatus: OrganizationStatus) => modal.confirm({ title: `${nextStatus === 'active' ? 'Activate' : 'Suspend'} ${organization.name}?`, content: nextStatus === 'suspended' ? 'Its users will no longer be able to access organization resources.' : 'Organization access will be restored.', okText: nextStatus === 'active' ? 'Activate' : 'Suspend', okButtonProps: { danger: nextStatus === 'suspended' }, onOk: async () => { await saveOrganization(organization.id, { status: nextStatus }); await refresh(); void message.success(`Organization ${nextStatus === 'active' ? 'activated' : 'suspended'}.`) } })
  const columns: ColumnsType<OrganizationItem> = [
    { title: 'Organization', key: 'organization', render: (_, item) => <div className="admin-primary-cell"><Avatar shape="square" icon={<Building2 size={17} />} /><span><strong>{item.name}</strong><small>{item.slug}</small></span></div> },
    { title: 'Contact', key: 'contact', render: (_, item) => <div className="admin-stack-cell"><span>{item.contact_email ?? 'No email'}</span><small>{item.contact_phone ?? 'No phone'}</small></div> },
    { title: 'Users', dataIndex: 'users_count', align: 'right', width: 82 },
    { title: 'Stations', dataIndex: 'stations_count', align: 'right', width: 90 },
    { title: 'Sessions', dataIndex: 'charging_sessions_count', align: 'right', width: 90 },
    { title: 'Revenue', dataIndex: 'settled_revenue_millimes', align: 'right', render: formatMoney },
    { title: 'Status', dataIndex: 'status', width: 112, render: (value: string) => <AdminStatus status={value} /> },
    { title: 'Created', dataIndex: 'created_at', width: 120, render: (value: string | null) => value ? dayjs(value).format('DD MMM YYYY') : '-' },
    { title: '', key: 'actions', width: 116, align: 'right', render: (_, item) => <Space size={3} className="admin-row-actions"><Tooltip title="View details"><Button aria-label={`View ${item.name}`} type="text" icon={<Eye size={15} />} onClick={() => setSelectedId(item.id)} /></Tooltip><Tooltip title="Edit"><Button aria-label={`Edit ${item.name}`} type="text" icon={<PencilLine size={15} />} onClick={() => setEditor(item)} /></Tooltip><Button size="small" danger={item.status === 'active'} onClick={() => confirmStatus(item, item.status === 'active' ? 'suspended' : 'active')}>{item.status === 'active' ? 'Suspend' : 'Activate'}</Button></Space> },
  ]

  return <div className="super-admin-page organizations-page">
    <MountainBanner color="green" breadcrumb={['Super Admin', 'Organizations']} title="Organizations" count={summary?.total ?? 0} subtitle="Manage charging networks, tenant ownership and organization access from one place." />
    <AdminMetricGrid>
      <AdminMetric icon={Building2} label="Organizations" value={summary?.total ?? 0} helper={`${summary?.active ?? 0} active networks`} />
      <AdminMetric icon={Users} label="Platform employees" value={totals.users} helper="Across the current result" tone="blue" />
      <AdminMetric icon={MapPin} label="Managed stations" value={totals.stations} helper="Across all visible networks" tone="purple" />
      <AdminMetric icon={CircleDollarSign} label="Settled revenue" value={formatMoney(totals.revenue)} helper="Recorded transactions" tone="orange" />
    </AdminMetricGrid>
    <AdminDataPanel title="Organization directory" subtitle="Search, inspect and manage every tenant charging network." extra={<Button type="primary" className="admin-primary-action" icon={<Plus size={16} />} onClick={() => setEditor(null)}>Add organization</Button>}>
      <div className="admin-table-toolbar"><Input value={search} onChange={(event) => setSearch(event.target.value)} prefix={<Search size={15} />} placeholder="Search organization, slug or contact" allowClear /><Select value={status} allowClear placeholder="All statuses" options={[{ value: 'active', label: 'Active' }, { value: 'suspended', label: 'Suspended' }]} onChange={setStatus} /><span>{selectedKeys.length ? `${selectedKeys.length} selected` : `${organizations.length} organizations`}</span></div>
      {organizationsQuery.isLoading ? <AdminLoading /> : organizations.length === 0 ? <AdminEmpty description="No organization matches the current filters" actionLabel="Add organization" onAction={() => setEditor(null)} /> : <Table rowKey="id" className="admin-data-table" columns={columns} dataSource={organizations} pagination={{ pageSize: 10, showSizeChanger: false }} rowSelection={{ selectedRowKeys: selectedKeys, onChange: setSelectedKeys }} scroll={{ x: 1120 }} onRow={(record) => ({ onDoubleClick: () => setSelectedId(record.id) })} />}
    </AdminDataPanel>
    <OrganizationDrawer detail={detailQuery.data} loading={detailQuery.isLoading} open={selectedId !== null} onClose={() => setSelectedId(null)} onEdit={(organization) => setEditor(organization)} />
    {editor !== undefined && <OrganizationEditor organization={editor} submitting={saveMutation.isPending} onClose={() => setEditor(undefined)} onSubmit={(values) => saveMutation.mutate({ id: editor?.id ?? null, values })} />}
  </div>
}

function OrganizationDrawer({ detail, loading, open, onClose, onEdit }: { detail?: OrganizationDetail; loading: boolean; open: boolean; onClose: () => void; onEdit: (organization: OrganizationItem) => void }) {
  return <Drawer className="admin-detail-drawer" size={570} open={open} onClose={onClose} title="Organization details" extra={detail ? <Button icon={<PencilLine size={14} />} onClick={() => onEdit(detail)}>Edit</Button> : undefined}>{loading || !detail ? <AdminLoading rows={10} /> : <div className="organization-detail"><header><Avatar shape="square" size={52} icon={<Building2 size={24} />} /><div><h2>{detail.name}</h2><p>{detail.contact_email ?? 'No contact email'} · {detail.contact_phone ?? 'No phone'}</p><AdminStatus status={detail.status} /></div></header><div className="organization-detail-metrics"><span><Users size={16} /><strong>{detail.users_count}</strong><small>Users</small></span><span><MapPin size={16} /><strong>{detail.stations_count}</strong><small>Stations</small></span><span><Zap size={16} /><strong>{detail.charging_sessions_count}</strong><small>Sessions</small></span><span><CircleDollarSign size={16} /><strong>{formatMoney(detail.settled_revenue_millimes)}</strong><small>Revenue</small></span></div><section><h3>Administrators</h3>{detail.admins.length === 0 ? <p>No administrator assigned.</p> : detail.admins.map((admin) => <div className="organization-person" key={admin.id}><Avatar>{initials(admin.name)}</Avatar><div><strong>{admin.name}</strong><small>{admin.email}</small></div><AdminStatus status={admin.status} /></div>)}</section><section><h3>Station preview</h3>{detail.stations_preview.length === 0 ? <p>No station registered.</p> : detail.stations_preview.map((station) => <div className="organization-station" key={station.id}><div><strong>{station.name}</strong><small>{station.reference}</small></div><Tag color={station.status === 'available' ? 'green' : 'orange'}>{station.status}</Tag></div>)}</section></div>}</Drawer>
}

function OrganizationEditor({ organization, submitting, onClose, onSubmit }: { organization: OrganizationItem | null; submitting: boolean; onClose: () => void; onSubmit: (values: Partial<OrganizationItem>) => void }) {
  return <Modal className="admin-editor-modal" width={620} open title={<span><strong>{organization ? 'Edit organization' : 'Add organization'}</strong><small>{organization ? 'Update tenant information and lifecycle.' : 'Create a new isolated charging-network tenant.'}</small></span>} destroyOnHidden onCancel={onClose} footer={null}><Form layout="vertical" initialValues={organization ?? { status: 'active' }} onFinish={onSubmit}><div className="admin-form-grid"><Form.Item label="Organization name" name="name" rules={[{ required: true, message: 'Enter the organization name.' }]}><Input /></Form.Item><Form.Item label="Slug" name="slug" extra="Generated automatically when left empty."><Input /></Form.Item><Form.Item label="Contact email" name="contact_email" rules={[{ type: 'email' }]}><Input /></Form.Item><Form.Item label="Contact phone" name="contact_phone"><Input /></Form.Item>{organization && <Form.Item label="Lifecycle status" name="status"><Select options={[{ value: 'active', label: 'Active' }, { value: 'suspended', label: 'Suspended' }]} /></Form.Item>}</div><div className="admin-modal-actions"><Button onClick={onClose}>Cancel</Button><Button type="primary" htmlType="submit" loading={submitting}>{organization ? 'Save changes' : 'Create organization'}</Button></div></Form></Modal>
}

function formatMoney(millimes: number): string { return new Intl.NumberFormat('en-TN', { style: 'currency', currency: 'TND', minimumFractionDigits: 3 }).format(millimes / 1000) }
function initials(name: string): string { return name.split(' ').filter(Boolean).slice(0, 2).map((part) => part[0]).join('').toUpperCase() }
