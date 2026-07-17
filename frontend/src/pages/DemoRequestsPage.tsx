import { useDeferredValue, useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  Alert,
  App,
  Button,
  DatePicker,
  Descriptions,
  Drawer,
  Empty,
  Form,
  Input,
  InputNumber,
  Modal,
  Pagination,
  Popconfirm,
  Select,
  Skeleton,
  Table,
  Tag,
} from 'antd'
import type { ColumnsType } from 'antd/es/table'
import dayjs, { type Dayjs } from 'dayjs'
import { Building2, CalendarClock, CheckCircle2, Inbox, Search, UserPlus, XCircle } from 'lucide-react'
import {
  getDemoRequests,
  provisionDemoRequest,
  resendDemoInvitation,
  revokeDemoInvitation,
  updateDemoRequest,
} from '../features/demoRequests/demoRequestApi'
import type {
  DemoRequest,
  DemoRequestFilters,
  DemoRequestStatus,
  DemoTopic,
  ProvisionDemoRequestPayload,
} from '../types/demoRequest'

interface ReviewValues {
  status: DemoRequestStatus
  scheduled_at?: Dayjs | null
  internal_notes?: string
}

const statusLabels: Record<DemoRequestStatus, string> = {
  new: 'New',
  under_review: 'Under review',
  contacted: 'Contacted',
  demo_scheduled: 'Demo scheduled',
  qualified: 'Qualified',
  approved: 'Approved',
  provisioned: 'Provisioned',
  rejected: 'Rejected',
}

const statusColors: Record<DemoRequestStatus, string> = {
  new: 'blue',
  under_review: 'gold',
  contacted: 'cyan',
  demo_scheduled: 'purple',
  qualified: 'geekblue',
  approved: 'green',
  provisioned: 'success',
  rejected: 'error',
}

const topicOptions: Array<{ value: DemoTopic; label: string }> = [
  { value: 'platform', label: 'Full platform' },
  { value: 'operator', label: 'Operator supervision' },
  { value: 'technician', label: 'Technician workflows' },
  { value: 'client', label: 'Client experience' },
  { value: 'admin', label: 'Administrator controls' },
]

export function DemoRequestsPage() {
  const [search, setSearch] = useState('')
  const [status, setStatus] = useState<DemoRequestStatus>()
  const [topic, setTopic] = useState<DemoTopic>()
  const [page, setPage] = useState(1)
  const [selected, setSelected] = useState<DemoRequest | null>(null)
  const [reviewing, setReviewing] = useState<DemoRequest | null>(null)
  const [provisioning, setProvisioning] = useState<DemoRequest | null>(null)
  const deferredSearch = useDeferredValue(search)
  const queryClient = useQueryClient()
  const { message } = App.useApp()

  const filters = useMemo<DemoRequestFilters>(() => ({
    search: deferredSearch.trim() || undefined,
    status,
    topic,
    page,
    per_page: 20,
  }), [deferredSearch, page, status, topic])

  const requestsQuery = useQuery({
    queryKey: ['demo-requests', filters],
    queryFn: () => getDemoRequests(filters),
  })

  const refresh = () => queryClient.invalidateQueries({ queryKey: ['demo-requests'] })
  const reviewMutation = useMutation({
    mutationFn: ({ requestId, values }: { requestId: number; values: ReviewValues }) => updateDemoRequest(requestId, {
      status: values.status,
      scheduled_at: values.scheduled_at?.toISOString() ?? null,
      internal_notes: values.internal_notes?.trim() || null,
    }),
    onSuccess: async (saved) => {
      await refresh()
      setSelected(saved)
      setReviewing(null)
      void message.success('Demo request updated.')
    },
    onError: () => void message.error('The status transition could not be saved.'),
  })
  const provisionMutation = useMutation({
    mutationFn: ({ requestId, values }: { requestId: number; values: ProvisionDemoRequestPayload }) => provisionDemoRequest(requestId, values),
    onSuccess: async (saved) => {
      await refresh()
      setSelected(saved)
      setProvisioning(null)
      void message.success('Organization created and administrator invitation queued.')
    },
    onError: () => void message.error('Provisioning failed. Check the request status and administrator email.'),
  })
  const resendMutation = useMutation({
    mutationFn: resendDemoInvitation,
    onSuccess: async (saved) => {
      await refresh()
      setSelected(saved)
      void message.success('A new activation invitation was queued. The previous link is invalid.')
    },
    onError: () => void message.error('The administrator invitation could not be reissued.'),
  })
  const revokeMutation = useMutation({
    mutationFn: revokeDemoInvitation,
    onSuccess: async (saved) => {
      await refresh()
      setSelected(saved)
      void message.success('The pending administrator invitation was revoked.')
    },
    onError: () => void message.error('The administrator invitation could not be revoked.'),
  })

  const data = requestsQuery.data?.data ?? []
  const summary = requestsQuery.data?.summary
  const meta = requestsQuery.data?.meta
  const columns: ColumnsType<DemoRequest> = [
    {
      title: 'Request',
      key: 'request',
      render: (_, request) => <div className="demo-request-primary"><strong>{request.company_name}</strong><small>{request.reference}</small></div>,
    },
    {
      title: 'Contact',
      key: 'contact',
      render: (_, request) => <div className="demo-request-primary"><span>{request.full_name}</span><small>{request.email}</small></div>,
    },
    { title: 'Topic', dataIndex: 'topic', render: (value: DemoTopic) => topicOptions.find((option) => option.value === value)?.label ?? value },
    { title: 'Network', dataIndex: 'estimated_stations', align: 'right', render: (value: number | null) => value ? `${value} stations` : 'Not specified' },
    { title: 'Status', dataIndex: 'status', render: (value: DemoRequestStatus) => <DemoStatus status={value} /> },
    { title: 'Submitted', dataIndex: 'created_at', render: (value: string) => dayjs(value).format('DD MMM YYYY, HH:mm') },
    { title: '', key: 'actions', width: 90, render: (_, request) => <Button size="small" onClick={(event) => { event.stopPropagation(); setSelected(request) }}>Review</Button> },
  ]

  return <div className="demo-requests-page">
    <header className="admin-page-heading">
      <div><p>Platform administration</p><h1>Demo requests</h1><span>Review companies, schedule demonstrations and provision isolated organization workspaces.</span></div>
      <Tag color="green">Super Admin only</Tag>
    </header>

    <section className="demo-summary-grid">
      <SummaryTile icon={<Inbox size={18} />} label="Total requests" value={summary?.total ?? 0} />
      <SummaryTile icon={<CalendarClock size={18} />} label="New" value={summary?.new ?? 0} tone="blue" />
      <SummaryTile icon={<Building2 size={18} />} label="In progress" value={summary?.in_progress ?? 0} tone="gold" />
      <SummaryTile icon={<CheckCircle2 size={18} />} label="Provisioned" value={summary?.provisioned ?? 0} tone="green" />
      <SummaryTile icon={<XCircle size={18} />} label="Rejected" value={summary?.rejected ?? 0} tone="red" />
    </section>

    <section className="admin-list-panel">
      <div className="admin-list-toolbar">
        <Input value={search} onChange={(event) => { setSearch(event.target.value); setPage(1) }} prefix={<Search size={15} />} placeholder="Search reference, company, contact or email" allowClear />
        <Select value={status} onChange={(value) => { setStatus(value); setPage(1) }} allowClear placeholder="All statuses" options={Object.entries(statusLabels).map(([value, label]) => ({ value, label }))} />
        <Select value={topic} onChange={(value) => { setTopic(value); setPage(1) }} allowClear placeholder="All topics" options={topicOptions} />
      </div>

      {requestsQuery.isError && <Alert type="error" showIcon title="Unable to load demo requests" action={<Button size="small" onClick={() => void requestsQuery.refetch()}>Retry</Button>} />}
      {requestsQuery.isLoading ? <Skeleton active paragraph={{ rows: 8 }} /> : data.length === 0 ? <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="No demo request matches these filters" /> : <Table<DemoRequest> rowKey="id" columns={columns} dataSource={data} pagination={false} onRow={(request) => ({ onClick: () => setSelected(request) })} />}
      {meta && meta.last_page > 1 && <Pagination current={meta.current_page} total={meta.total} pageSize={meta.per_page} showSizeChanger={false} onChange={setPage} />}
    </section>

    <DemoRequestDrawer
      request={selected}
      invitationLoading={resendMutation.isPending || revokeMutation.isPending}
      onClose={() => setSelected(null)}
      onReview={setReviewing}
      onProvision={setProvisioning}
      onResend={(request) => resendMutation.mutate(request.id)}
      onRevoke={(request) => revokeMutation.mutate(request.id)}
    />
    <ReviewModal request={reviewing} loading={reviewMutation.isPending} onClose={() => setReviewing(null)} onSubmit={(values) => reviewing && reviewMutation.mutate({ requestId: reviewing.id, values })} />
    <ProvisionModal request={provisioning} loading={provisionMutation.isPending} onClose={() => setProvisioning(null)} onSubmit={(values) => provisioning && provisionMutation.mutate({ requestId: provisioning.id, values })} />
  </div>
}

function SummaryTile({ icon, label, value, tone = 'neutral' }: { icon: React.ReactNode; label: string; value: number; tone?: string }) {
  return <article className={`demo-summary-tile demo-summary-tile--${tone}`}><span>{icon}</span><div><strong>{value}</strong><small>{label}</small></div></article>
}

function DemoStatus({ status }: { status: DemoRequestStatus }) {
  return <Tag color={statusColors[status]}>{statusLabels[status]}</Tag>
}

function DemoRequestDrawer({ request, invitationLoading, onClose, onReview, onProvision, onResend, onRevoke }: { request: DemoRequest | null; invitationLoading: boolean; onClose: () => void; onReview: (request: DemoRequest) => void; onProvision: (request: DemoRequest) => void; onResend: (request: DemoRequest) => void; onRevoke: (request: DemoRequest) => void }) {
  return <Drawer size="large" open={Boolean(request)} onClose={onClose} title={request ? `${request.company_name} · ${request.reference}` : 'Demo request'} extra={request && <DemoStatus status={request.status} />}>
    {request && <div className="demo-request-drawer">
      <Descriptions column={1} size="small" bordered items={[
        { key: 'contact', label: 'Contact', children: `${request.full_name} · ${request.email}` },
        { key: 'phone', label: 'Phone', children: request.phone ?? 'Not provided' },
        { key: 'topic', label: 'Topic', children: topicOptions.find((option) => option.value === request.topic)?.label },
        { key: 'network', label: 'Estimated network', children: request.estimated_stations ? `${request.estimated_stations} stations` : 'Not specified' },
        { key: 'submitted', label: 'Submitted', children: dayjs(request.created_at).format('DD MMM YYYY, HH:mm') },
        { key: 'handled', label: 'Handled by', children: request.handled_by?.name ?? 'Unassigned' },
        { key: 'scheduled', label: 'Demo date', children: request.scheduled_at ? dayjs(request.scheduled_at).format('DD MMM YYYY, HH:mm') : 'Not scheduled' },
      ]} />
      <section><h3>Request message</h3><p>{request.message}</p></section>
      <section><h3>Internal notes</h3><p>{request.internal_notes || 'No internal note yet.'}</p></section>
      {request.organization && <Alert type="success" showIcon title={`Organization provisioned: ${request.organization.name}`} description={`Invitation status: ${request.invitation?.status ?? 'unknown'}`} />}
      <div className="demo-drawer-actions">
        {request.status !== 'provisioned' && <Button onClick={() => onReview(request)}>Update review</Button>}
        {request.status === 'approved' && <Button type="primary" icon={<UserPlus size={15} />} onClick={() => onProvision(request)}>Provision organization</Button>}
        {request.status === 'provisioned' && request.invitation?.status !== 'accepted' && <Popconfirm title="Send a new invitation?" description="The previous activation link will become invalid." onConfirm={() => onResend(request)}><Button loading={invitationLoading}>Resend invitation</Button></Popconfirm>}
        {request.status === 'provisioned' && request.invitation?.status === 'pending' && <Popconfirm title="Revoke this invitation?" description="The administrator will not be able to activate with the current link." onConfirm={() => onRevoke(request)}><Button danger loading={invitationLoading}>Revoke invitation</Button></Popconfirm>}
      </div>
    </div>}
  </Drawer>
}

function ReviewModal({ request, loading, onClose, onSubmit }: { request: DemoRequest | null; loading: boolean; onClose: () => void; onSubmit: (values: ReviewValues) => void }) {
  return <Modal open={Boolean(request)} title="Update demo request" footer={null} onCancel={onClose} destroyOnHidden>
    {request && <Form<ReviewValues> layout="vertical" initialValues={{ status: request.status, scheduled_at: request.scheduled_at ? dayjs(request.scheduled_at) : null, internal_notes: request.internal_notes ?? '' }} onFinish={onSubmit}>
      <Form.Item name="status" label="Status" rules={[{ required: true }]}><Select options={[request.status, ...request.allowed_transitions].map((value) => ({ value, label: statusLabels[value] }))} /></Form.Item>
      <Form.Item noStyle shouldUpdate={(previous, current) => previous.status !== current.status}>{({ getFieldValue }) => getFieldValue('status') === 'demo_scheduled' && <Form.Item name="scheduled_at" label="Demo date" rules={[{ required: true, message: 'Choose the planned demo date' }]}><DatePicker showTime format="DD MMM YYYY HH:mm" /></Form.Item>}</Form.Item>
      <Form.Item name="internal_notes" label="Internal notes"><Input.TextArea rows={5} maxLength={5000} showCount placeholder="Qualification, follow-up and decision notes" /></Form.Item>
      <div className="modal-form-actions"><Button onClick={onClose}>Cancel</Button><Button type="primary" htmlType="submit" loading={loading}>Save review</Button></div>
    </Form>}
  </Modal>
}

function ProvisionModal({ request, loading, onClose, onSubmit }: { request: DemoRequest | null; loading: boolean; onClose: () => void; onSubmit: (values: ProvisionDemoRequestPayload) => void }) {
  return <Modal open={Boolean(request)} title="Provision organization" footer={null} onCancel={onClose} destroyOnHidden>
    {request && <Form<ProvisionDemoRequestPayload> layout="vertical" initialValues={{ organization_name: request.company_name, admin_name: request.full_name, trial_days: 30 }} onFinish={onSubmit}>
      <Alert className="provision-alert" type="info" showIcon title="A pending administrator account will be created" description={`The one-time activation link will be sent to ${request.email}.`} />
      <Form.Item name="organization_name" label="Organization name" rules={[{ required: true }]}><Input /></Form.Item>
      <Form.Item name="admin_name" label="Administrator name" rules={[{ required: true }]}><Input /></Form.Item>
      <Form.Item name="trial_days" label="Trial period" rules={[{ required: true }]}><InputNumber min={7} max={90} addonAfter="days" /></Form.Item>
      <div className="modal-form-actions"><Button onClick={onClose}>Cancel</Button><Button type="primary" htmlType="submit" loading={loading}>Create and invite</Button></div>
    </Form>}
  </Modal>
}
