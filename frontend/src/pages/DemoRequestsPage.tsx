import { useDeferredValue, useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  Alert,
  App,
  Button,
  Descriptions,
  Drawer,
  Empty,
  Form,
  Input,
  Modal,
  Pagination,
  Popconfirm,
  Select,
  Skeleton,
  Steps,
  Table,
  Tag,
} from 'antd'
import type { ColumnsType } from 'antd/es/table'
import dayjs from 'dayjs'
import {
  Building2,
  CheckCircle2,
  ClipboardCheck,
  Inbox,
  PencilLine,
  RotateCcw,
  Search,
  Send,
  ShieldCheck,
  UserPlus,
  XCircle,
} from 'lucide-react'
import { MountainBanner } from '../components/MountainBanner'
import { MetricItem, MetricStrip } from '../components/MetricStrip'
import { CompactInputNumber } from '../components/CompactInputNumber'
import {
  getDemoRequests,
  issueDemoInvitation,
  provisionDemoRequest,
  rejectDemoRequest,
  reopenDemoRequest,
  revokeDemoInvitation,
  startDemoRequestReview,
  updateDemoRequestNotes,
} from '../features/demoRequests/demoRequestApi'
import { demoObjectiveLabels, demoObjectiveOptions } from '../features/demoRequests/demoRequestOptions'
import type {
  DemoObjective,
  DemoRequest,
  DemoRequestFilters,
  DemoRequestStatus,
  ProvisionDemoRequestPayload,
  RejectDemoRequestPayload,
} from '../types/demoRequest'

const statusLabels: Record<DemoRequestStatus, string> = {
  submitted: 'Submitted',
  under_review: 'Under review',
  provisioned: 'Provisioned',
  rejected: 'Rejected',
}

const statusColors: Record<DemoRequestStatus, string> = {
  submitted: 'blue',
  under_review: 'gold',
  provisioned: 'success',
  rejected: 'error',
}

interface NotesValues {
  internal_notes?: string
}

export function DemoRequestsPage() {
  const [search, setSearch] = useState('')
  const [status, setStatus] = useState<DemoRequestStatus>()
  const [objective, setObjective] = useState<DemoObjective>()
  const [page, setPage] = useState(1)
  const [selected, setSelected] = useState<DemoRequest | null>(null)
  const [provisioning, setProvisioning] = useState<DemoRequest | null>(null)
  const [rejecting, setRejecting] = useState<DemoRequest | null>(null)
  const [editingNotes, setEditingNotes] = useState<DemoRequest | null>(null)
  const deferredSearch = useDeferredValue(search)
  const queryClient = useQueryClient()
  const { message } = App.useApp()

  const filters = useMemo<DemoRequestFilters>(() => ({
    search: deferredSearch.trim() || undefined,
    status,
    objective,
    page,
    per_page: 20,
  }), [deferredSearch, objective, page, status])

  const requestsQuery = useQuery({
    queryKey: ['demo-requests', filters],
    queryFn: () => getDemoRequests(filters),
  })

  const refresh = () => queryClient.invalidateQueries({ queryKey: ['demo-requests'] })
  const syncSaved = async (saved: DemoRequest) => {
    await refresh()
    setSelected(saved)
  }

  const startReviewMutation = useMutation({
    mutationFn: startDemoRequestReview,
    onSuccess: async (saved) => {
      await syncSaved(saved)
      void message.success('Review started and assigned to you.')
    },
    onError: () => void message.error('This request can no longer enter review.'),
  })
  const notesMutation = useMutation({
    mutationFn: ({ requestId, notes }: { requestId: number; notes: string | null }) => updateDemoRequestNotes(requestId, notes),
    onSuccess: async (saved) => {
      await syncSaved(saved)
      setEditingNotes(null)
      void message.success('Internal note saved.')
    },
    onError: () => void message.error('The internal note could not be saved.'),
  })
  const rejectMutation = useMutation({
    mutationFn: ({ requestId, values }: { requestId: number; values: RejectDemoRequestPayload }) => rejectDemoRequest(requestId, values),
    onSuccess: async (saved) => {
      await syncSaved(saved)
      setRejecting(null)
      void message.success('Demo request rejected.')
    },
    onError: () => void message.error('The request could not be rejected.'),
  })
  const reopenMutation = useMutation({
    mutationFn: reopenDemoRequest,
    onSuccess: async (saved) => {
      await syncSaved(saved)
      void message.success('Request reopened and returned to review.')
    },
    onError: () => void message.error('The request could not be reopened.'),
  })
  const provisionMutation = useMutation({
    mutationFn: ({ requestId, values }: { requestId: number; values: ProvisionDemoRequestPayload }) => provisionDemoRequest(requestId, values),
    onSuccess: async (saved) => {
      await syncSaved(saved)
      setProvisioning(null)
      void message.success('Workspace created and administrator invitation queued.')
    },
    onError: () => void message.error('Provisioning failed. Check the administrator email and request state.'),
  })
  const issueMutation = useMutation({
    mutationFn: issueDemoInvitation,
    onSuccess: async (saved) => {
      await syncSaved(saved)
      void message.success('A new one-time invitation was queued.')
    },
    onError: () => void message.error('Revoke or let the current invitation expire before issuing a new one.'),
  })
  const revokeMutation = useMutation({
    mutationFn: revokeDemoInvitation,
    onSuccess: async (saved) => {
      await syncSaved(saved)
      void message.success('The pending invitation was revoked.')
    },
    onError: () => void message.error('Only a pending invitation can be revoked.'),
  })

  const data = requestsQuery.data?.data ?? []
  const summary = requestsQuery.data?.summary
  const meta = requestsQuery.data?.meta
  const actionLoading = startReviewMutation.isPending
    || rejectMutation.isPending
    || reopenMutation.isPending
    || provisionMutation.isPending
    || issueMutation.isPending
    || revokeMutation.isPending

  const columns: ColumnsType<DemoRequest> = [
    {
      title: 'Organization request',
      key: 'request',
      render: (_, request) => <div className="demo-request-primary"><strong>{request.company_name}</strong><small>{request.reference}</small></div>,
    },
    {
      title: 'Applicant',
      key: 'contact',
      render: (_, request) => <div className="demo-request-primary"><span>{request.full_name}</span><small>{request.email}</small></div>,
    },
    {
      title: 'Main objectives',
      key: 'objectives',
      render: (_, request) => <ObjectiveTags objectives={request.objectives} compact />,
    },
    { title: 'Network', dataIndex: 'estimated_stations', align: 'right', render: (value: number | null) => value ? `${value} stations` : 'Not specified' },
    { title: 'Status', dataIndex: 'status', render: (value: DemoRequestStatus) => <DemoStatus status={value} /> },
    { title: 'Submitted', dataIndex: 'created_at', render: (value: string) => dayjs(value).format('DD MMM YYYY, HH:mm') },
    { title: '', key: 'actions', width: 84, render: (_, request) => <Button size="small" onClick={(event) => { event.stopPropagation(); setSelected(request) }}>Open</Button> },
  ]

  return <div className="demo-requests-page">
    <MountainBanner
      color="purple"
      breadcrumb={['Platform', 'Demo requests']}
      title="Demo requests"
      count={summary?.total ?? 0}
      subtitle="Review organization access requests and create isolated trial workspaces for approved administrators."
    />

    <MetricStrip className="demo-summary-grid">
      <MetricItem icon={<Inbox size={18} />} label="Submitted" value={summary?.submitted ?? 0} helper="Awaiting review" tone="blue" />
      <MetricItem icon={<ClipboardCheck size={18} />} label="Under review" value={summary?.under_review ?? 0} helper="Assigned to the platform team" tone="amber" />
      <MetricItem icon={<CheckCircle2 size={18} />} label="Provisioned" value={summary?.provisioned ?? 0} helper="Workspace created" tone="green" />
      <MetricItem icon={<XCircle size={18} />} label="Rejected" value={summary?.rejected ?? 0} helper="Closed requests" tone="red" />
    </MetricStrip>

    <section className="admin-list-panel">
      <div className="admin-list-toolbar">
        <Input value={search} onChange={(event) => { setSearch(event.target.value); setPage(1) }} prefix={<Search size={15} />} placeholder="Search reference, company, applicant or email" allowClear />
        <Select value={status} onChange={(value) => { setStatus(value); setPage(1) }} allowClear placeholder="All statuses" options={Object.entries(statusLabels).map(([value, label]) => ({ value, label }))} />
        <Select value={objective} onChange={(value) => { setObjective(value); setPage(1) }} allowClear showSearch optionFilterProp="label" placeholder="All objectives" options={demoObjectiveOptions} />
      </div>

      {requestsQuery.isError && <Alert type="error" showIcon title="Unable to load demo requests" action={<Button size="small" onClick={() => void requestsQuery.refetch()}>Retry</Button>} />}
      {requestsQuery.isLoading ? <Skeleton active paragraph={{ rows: 8 }} /> : data.length === 0 ? <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="No demo request matches these filters" /> : <Table<DemoRequest> rowKey="id" columns={columns} dataSource={data} pagination={false} onRow={(request) => ({ onClick: () => setSelected(request) })} />}
      {meta && meta.last_page > 1 && <Pagination current={meta.current_page} total={meta.total} pageSize={meta.per_page} showSizeChanger={false} onChange={setPage} />}
    </section>

    <DemoRequestDrawer
      request={selected}
      actionLoading={actionLoading}
      onClose={() => setSelected(null)}
      onStartReview={(request) => startReviewMutation.mutate(request.id)}
      onEditNotes={setEditingNotes}
      onReject={setRejecting}
      onProvision={setProvisioning}
      onReopen={(request) => reopenMutation.mutate(request.id)}
      onIssue={(request) => issueMutation.mutate(request.id)}
      onRevoke={(request) => revokeMutation.mutate(request.id)}
    />
    <NotesModal request={editingNotes} loading={notesMutation.isPending} onClose={() => setEditingNotes(null)} onSubmit={(values) => editingNotes && notesMutation.mutate({ requestId: editingNotes.id, notes: values.internal_notes?.trim() || null })} />
    <RejectModal request={rejecting} loading={rejectMutation.isPending} onClose={() => setRejecting(null)} onSubmit={(values) => rejecting && rejectMutation.mutate({ requestId: rejecting.id, values })} />
    <ProvisionModal request={provisioning} loading={provisionMutation.isPending} onClose={() => setProvisioning(null)} onSubmit={(values) => provisioning && provisionMutation.mutate({ requestId: provisioning.id, values })} />
  </div>
}

function DemoStatus({ status }: { status: DemoRequestStatus }) {
  return <Tag color={statusColors[status]}>{statusLabels[status]}</Tag>
}

function ObjectiveTags({ objectives, compact = false }: { objectives: DemoObjective[]; compact?: boolean }) {
  const visible = compact ? objectives.slice(0, 2) : objectives
  return <div className="demo-objective-tags">
    {visible.map((objective) => <Tag key={objective}>{demoObjectiveLabels[objective]}</Tag>)}
    {compact && objectives.length > 2 && <Tag>+{objectives.length - 2}</Tag>}
  </div>
}

interface DrawerProps {
  request: DemoRequest | null
  actionLoading: boolean
  onClose: () => void
  onStartReview: (request: DemoRequest) => void
  onEditNotes: (request: DemoRequest) => void
  onReject: (request: DemoRequest) => void
  onProvision: (request: DemoRequest) => void
  onReopen: (request: DemoRequest) => void
  onIssue: (request: DemoRequest) => void
  onRevoke: (request: DemoRequest) => void
}

function DemoRequestDrawer({ request, actionLoading, onClose, onStartReview, onEditNotes, onReject, onProvision, onReopen, onIssue, onRevoke }: DrawerProps) {
  return <Drawer size="large" open={Boolean(request)} onClose={onClose} title={request ? `${request.company_name} / ${request.reference}` : 'Demo request'} extra={request && <DemoStatus status={request.status} />}>
    {request && <div className="demo-request-drawer">
      <WorkflowProgress status={request.status} />
      <Descriptions column={1} size="small" bordered items={[
        { key: 'contact', label: 'Applicant', children: `${request.full_name} / ${request.email}` },
        { key: 'phone', label: 'Phone', children: request.phone ?? 'Not provided' },
        { key: 'network', label: 'Estimated network', children: request.estimated_stations ? `${request.estimated_stations} stations` : 'Not specified' },
        { key: 'submitted', label: 'Submitted', children: dayjs(request.created_at).format('DD MMM YYYY, HH:mm') },
        { key: 'handled', label: 'Reviewer', children: request.handled_by?.name ?? 'Not assigned' },
      ]} />
      <section><h3>Main objectives</h3><ObjectiveTags objectives={request.objectives} /></section>
      <section><h3>Applicant message</h3><p>{request.message}</p></section>
      <section className="demo-note-section"><div><h3>Internal note</h3><Button type="text" size="small" icon={<PencilLine size={14} />} onClick={() => onEditNotes(request)}>Edit</Button></div><p>{request.internal_notes || 'No internal note yet.'}</p></section>
      {request.status === 'rejected' && <Alert type="error" showIcon title="Request rejected" description={request.rejection_reason} />}
      {request.organization && <InvitationPanel request={request} loading={actionLoading} onIssue={onIssue} onRevoke={onRevoke} />}
      <div className="demo-drawer-actions">
        {request.status === 'submitted' && <Button type="primary" icon={<ClipboardCheck size={15} />} loading={actionLoading} onClick={() => onStartReview(request)}>Start review</Button>}
        {request.status === 'submitted' && <Button danger loading={actionLoading} onClick={() => onReject(request)}>Reject</Button>}
        {request.status === 'under_review' && <Button type="primary" icon={<UserPlus size={15} />} loading={actionLoading} onClick={() => onProvision(request)}>Approve & create workspace</Button>}
        {request.status === 'under_review' && <Button danger loading={actionLoading} onClick={() => onReject(request)}>Reject</Button>}
        {request.status === 'rejected' && <Popconfirm title="Reopen this request?" description="The request will return to review and the previous rejection reason will be cleared." onConfirm={() => onReopen(request)}><Button icon={<RotateCcw size={15} />} loading={actionLoading}>Reopen request</Button></Popconfirm>}
      </div>
    </div>}
  </Drawer>
}

function WorkflowProgress({ status }: { status: DemoRequestStatus }) {
  const current = status === 'submitted' ? 0 : status === 'under_review' || status === 'rejected' ? 1 : 2
  return <section className="demo-workflow-progress">
    <Steps current={current} status={status === 'rejected' ? 'error' : 'process'} responsive={false} items={[
      { title: 'Submitted' },
      { title: status === 'rejected' ? 'Rejected' : 'Under review' },
      { title: 'Workspace created' },
    ]} />
  </section>
}

function InvitationPanel({ request, loading, onIssue, onRevoke }: { request: DemoRequest; loading: boolean; onIssue: (request: DemoRequest) => void; onRevoke: (request: DemoRequest) => void }) {
  const invitation = request.invitation
  if (!invitation) return null

  return <section className="demo-invitation-panel">
    <div><span><ShieldCheck size={18} /></span><div><h3>Administrator invitation</h3><p>Status: <Tag color={invitation.status === 'accepted' ? 'success' : invitation.status === 'pending' ? 'processing' : 'default'}>{invitation.status}</Tag></p></div></div>
    {invitation.status === 'pending' && <Popconfirm title="Revoke this invitation?" description="The current activation link will stop working." onConfirm={() => onRevoke(request)}><Button danger loading={loading}>Revoke invitation</Button></Popconfirm>}
    {(invitation.status === 'revoked' || invitation.status === 'expired') && <Popconfirm title="Issue a new invitation?" description="A new one-time activation link will be emailed to the administrator." onConfirm={() => onIssue(request)}><Button icon={<Send size={15} />} loading={loading}>Issue new invitation</Button></Popconfirm>}
    {invitation.status === 'accepted' && <Tag color="success" icon={<CheckCircle2 size={12} />}>Administrator account active</Tag>}
  </section>
}

function NotesModal({ request, loading, onClose, onSubmit }: { request: DemoRequest | null; loading: boolean; onClose: () => void; onSubmit: (values: NotesValues) => void }) {
  return <Modal open={Boolean(request)} title="Internal review note" footer={null} onCancel={onClose} destroyOnHidden>
    {request && <Form<NotesValues> layout="vertical" initialValues={{ internal_notes: request.internal_notes ?? '' }} onFinish={onSubmit}>
      <Form.Item name="internal_notes" label="Visible only to platform administrators"><Input.TextArea rows={6} maxLength={5000} showCount placeholder="Verification details, context, or decision notes" /></Form.Item>
      <div className="modal-form-actions"><Button onClick={onClose}>Cancel</Button><Button type="primary" htmlType="submit" loading={loading}>Save note</Button></div>
    </Form>}
  </Modal>
}

function RejectModal({ request, loading, onClose, onSubmit }: { request: DemoRequest | null; loading: boolean; onClose: () => void; onSubmit: (values: RejectDemoRequestPayload) => void }) {
  return <Modal open={Boolean(request)} title="Reject demo request" footer={null} onCancel={onClose} destroyOnHidden>
    {request && <Form<RejectDemoRequestPayload> layout="vertical" onFinish={onSubmit}>
      <Alert className="provision-alert" type="warning" showIcon title="A rejection reason is required" description="The reason is retained for audit and is not emailed automatically." />
      <Form.Item name="rejection_reason" label="Reason" rules={[{ required: true, min: 10, message: 'Provide a clear reason of at least 10 characters' }]}><Input.TextArea rows={4} maxLength={2000} showCount /></Form.Item>
      <Form.Item name="internal_notes" label="Additional internal note"><Input.TextArea rows={3} maxLength={5000} /></Form.Item>
      <div className="modal-form-actions"><Button onClick={onClose}>Cancel</Button><Button danger type="primary" htmlType="submit" loading={loading}>Reject request</Button></div>
    </Form>}
  </Modal>
}

function ProvisionModal({ request, loading, onClose, onSubmit }: { request: DemoRequest | null; loading: boolean; onClose: () => void; onSubmit: (values: ProvisionDemoRequestPayload) => void }) {
  return <Modal open={Boolean(request)} title="Approve and create workspace" footer={null} onCancel={onClose} destroyOnHidden>
    {request && <Form<ProvisionDemoRequestPayload> layout="vertical" initialValues={{ organization_name: request.company_name, admin_name: request.full_name, trial_days: 14 }} onFinish={onSubmit}>
      <Alert className="provision-alert" type="info" showIcon title="One approval, one transaction" description={`An isolated trial organization and pending administrator account will be created. The activation link will be sent to ${request.email}.`} />
      <Form.Item name="organization_name" label="Organization name" rules={[{ required: true }]}><Input /></Form.Item>
      <Form.Item name="admin_name" label="Administrator name" rules={[{ required: true }]}><Input /></Form.Item>
      <Form.Item name="trial_days" label="Trial period" rules={[{ required: true }]}><CompactInputNumber min={7} max={90} addon="days" /></Form.Item>
      <div className="modal-form-actions"><Button onClick={onClose}>Cancel</Button><Button type="primary" htmlType="submit" loading={loading} icon={<Building2 size={15} />}>Create workspace & invite</Button></div>
    </Form>}
  </Modal>
}
