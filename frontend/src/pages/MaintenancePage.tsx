import { useDeferredValue, useEffect, useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  App,
  Avatar,
  Button,
  Calendar,
  Card,
  DatePicker,
  Drawer,
  Empty,
  Form,
  Input,
  InputNumber,
  Popconfirm,
  Segmented,
  Select,
  Skeleton,
  Table,
  Tag,
} from 'antd'
import type { ColumnsType } from 'antd/es/table'
import { isAxiosError } from 'axios'
import dayjs from 'dayjs'
import {
  CalendarDays,
  CalendarRange,
  CheckCircle2,
  Clock3,
  FileDown,
  List,
  Plus,
  Repeat2,
  Search,
  ShieldCheck,
  UserRoundCog,
  Wrench,
  XCircle,
} from 'lucide-react'
import { useNavigate } from 'react-router-dom'
import { MountainBanner } from '../components/MountainBanner'
import { MetricItem, MetricStrip, type MetricTone } from '../components/MetricStrip'
import { useAuth } from '../features/auth/useAuth'
import { downloadOperationalDocument } from '../features/reports/reportingApi'
import {
  createMaintenancePlan,
  getMaintenances,
  updateIntervention,
  updateMaintenanceOccurrence,
} from '../features/operations/operationsApi'
import { WorkflowTag } from '../features/operations/WorkflowTag'
import type {
  AlertSeverity,
  InterventionItem,
  InterventionStatus,
  MaintenancePlanPayload,
  MaintenanceRecurrence,
  MaintenanceStationOption,
  MaintenanceType,
  TechnicianOption,
} from '../types/operations'
import { downloadBlob } from '../utils/downloadBlob'

type MaintenanceView = 'table' | 'calendar'
type MaintenanceFilterStatus = 'all' | InterventionStatus
type MaintenanceFilterType = 'all' | MaintenanceType

export function MaintenancePage() {
  const { user, primaryRole } = useAuth()
  const canManage = user?.permissions.includes('maintenances.manage') ?? false
  const [search, setSearch] = useState('')
  const deferredSearch = useDeferredValue(search)
  const [status, setStatus] = useState<MaintenanceFilterStatus>('all')
  const [type, setType] = useState<MaintenanceFilterType>('all')
  const [view, setView] = useState<MaintenanceView>('table')
  const [createOpen, setCreateOpen] = useState(false)
  const [selectedOccurrence, setSelectedOccurrence] = useState<InterventionItem | null>(null)
  const queryClient = useQueryClient()
  const navigate = useNavigate()
  const { message } = App.useApp()

  const filters = useMemo(() => ({
    search: deferredSearch.trim() || undefined,
    status: status === 'all' ? undefined : status,
    type: type === 'all' ? undefined : type,
  }), [deferredSearch, status, type])
  const maintenanceQuery = useQuery({
    queryKey: ['maintenances', filters],
    queryFn: () => getMaintenances(filters),
  })
  const occurrences = useMemo(() => maintenanceQuery.data?.data ?? [], [maintenanceQuery.data?.data])

  const refresh = async () => {
    await Promise.all([
      queryClient.invalidateQueries({ queryKey: ['maintenances'] }),
      queryClient.invalidateQueries({ queryKey: ['interventions'] }),
      queryClient.invalidateQueries({ queryKey: ['station'] }),
      queryClient.invalidateQueries({ queryKey: ['stations'] }),
    ])
  }
  const createMutation = useMutation({
    mutationFn: createMaintenancePlan,
    onSuccess: async () => {
      await refresh()
      setCreateOpen(false)
      void message.success('Maintenance plan created and assigned.')
    },
    onError: () => void message.error('The maintenance plan could not be created.'),
  })
  const updateMutation = useMutation({
    mutationFn: ({ id, payload }: { id: number; payload: Parameters<typeof updateMaintenanceOccurrence>[1] }) => updateMaintenanceOccurrence(id, payload),
    onSuccess: async () => {
      await refresh()
      setSelectedOccurrence(null)
      void message.success('Maintenance occurrence updated.')
    },
    onError: () => void message.error('The maintenance occurrence could not be updated.'),
  })
  const cancelMutation = useMutation({
    mutationFn: (id: number) => updateIntervention(id, { status: 'cancelled' }),
    onSuccess: async () => {
      await refresh()
      void message.success('Maintenance occurrence cancelled.')
    },
    onError: () => void message.error('The maintenance occurrence could not be cancelled.'),
  })
  const documentMutation = useMutation({
    mutationFn: (occurrence: InterventionItem) => downloadOperationalDocument('maintenance', occurrence.id),
    onSuccess: (blob, occurrence) => downloadBlob(blob, `maintenance-${occurrence.reference}.pdf`),
    onError: () => void message.error('The maintenance report could not be generated.'),
  })

  const columns: ColumnsType<InterventionItem> = [
    {
      title: 'Maintenance',
      key: 'maintenance',
      width: 220,
      render: (_, occurrence) => <div className="maintenance-title-cell">
        <span className={`maintenance-type-icon ${occurrence.maintenance_plan?.type ?? 'corrective'}`}><Wrench size={15} /></span>
        <span><strong>{occurrence.maintenance_plan?.title ?? occurrence.problem}</strong><small>{occurrence.reference} · {occurrence.maintenance_plan?.type ?? 'corrective'}</small></span>
      </div>,
    },
    {
      title: 'Station',
      key: 'station',
      width: 150,
      render: (_, occurrence) => <button className="maintenance-station-link" type="button" onClick={() => navigate(`/stations/${occurrence.station.id}`)}><strong>{occurrence.station.name}</strong><small>{occurrence.station.city}</small></button>,
    },
    {
      title: 'Technician',
      key: 'technician',
      width: 150,
      render: (_, occurrence) => occurrence.assigned_technician ? <div className="maintenance-technician"><Avatar size={26} src={occurrence.assigned_technician.avatar_url}>{occurrence.assigned_technician.name.charAt(0)}</Avatar><span>{occurrence.assigned_technician.name}</span></div> : 'Unassigned',
    },
    {
      title: 'Schedule',
      key: 'schedule',
      width: 150,
      render: (_, occurrence) => <div className="maintenance-schedule-cell"><strong>{occurrence.scheduled_at ? dayjs(occurrence.scheduled_at).format('DD MMM YYYY, HH:mm') : 'Not scheduled'}</strong><small><Clock3 size={12} />{occurrence.estimated_duration_minutes ?? 0} minutes</small></div>,
    },
    {
      title: 'Recurrence',
      key: 'recurrence',
      width: 110,
      render: (_, occurrence) => <RecurrenceTag occurrence={occurrence} />,
    },
    {
      title: 'Priority',
      dataIndex: 'priority',
      width: 90,
      render: (priority: AlertSeverity) => <WorkflowTag value={priority} />,
    },
    {
      title: 'Status',
      dataIndex: 'status',
      width: 100,
      render: (value: InterventionStatus) => <MaintenanceStatusTag status={value} />,
    },
    {
      title: '',
      key: 'actions',
      width: 150,
      render: (_: unknown, occurrence: InterventionItem) => <div className="maintenance-row-actions">
        <Button type="text" size="small" aria-label={`Download report ${occurrence.reference}`} icon={<FileDown size={14} />} loading={documentMutation.isPending && documentMutation.variables?.id === occurrence.id} onClick={() => documentMutation.mutate(occurrence)} />
        {canManage && occurrence.status === 'assigned' && <Button type="text" size="small" onClick={() => setSelectedOccurrence(occurrence)}>Edit</Button>}
        {canManage && occurrence.status === 'assigned' && <Popconfirm title="Cancel this occurrence?" description="A recurring plan will continue to generate its next occurrences." okText="Cancel occurrence" okButtonProps={{ danger: true }} onConfirm={() => cancelMutation.mutate(occurrence.id)}><Button type="text" size="small" danger>Cancel</Button></Popconfirm>}
      </div>,
    },
  ]

  const summary = maintenanceQuery.data?.summary
  return <div className="maintenance-page">
    <MountainBanner
      color="green"
      breadcrumb={[primaryRole === 'technician' ? 'Technician' : 'Operations', 'Maintenance']}
      title="Maintenance planning"
      count={summary?.total ?? 0}
      subtitle="Schedule preventive and corrective work, assign technicians, and coordinate station availability from one operational calendar."
    />

    <MetricStrip className="maintenance-summary-grid">
      <SummaryMetric icon={CalendarRange} label="Planned" value={summary?.planned ?? 0} tone="purple" />
      <SummaryMetric icon={Wrench} label="In progress" value={summary?.in_progress ?? 0} tone="blue" />
      <SummaryMetric icon={CheckCircle2} label="Completed" value={summary?.completed ?? 0} tone="green" />
      <SummaryMetric icon={XCircle} label="Cancelled" value={summary?.cancelled ?? 0} tone="gray" />
    </MetricStrip>

    <div className="maintenance-toolbar">
      <Input value={search} onChange={(event) => setSearch(event.target.value)} prefix={<Search size={15} />} placeholder="Search plan, station or reference" allowClear />
      <Select value={status} onChange={setStatus} options={[
        { value: 'all', label: 'All statuses' },
        { value: 'assigned', label: 'Planned' },
        { value: 'in-progress', label: 'In progress' },
        { value: 'paused', label: 'Paused' },
        { value: 'waiting-parts', label: 'Waiting parts' },
        { value: 'resolved', label: 'Completed' },
        { value: 'cancelled', label: 'Cancelled' },
      ]} />
      <Select value={type} onChange={setType} options={[
        { value: 'all', label: 'All types' },
        { value: 'preventive', label: 'Preventive' },
        { value: 'corrective', label: 'Corrective' },
      ]} />
      <Segmented value={view} onChange={(value) => setView(value as MaintenanceView)} options={[
        { value: 'table', icon: <List size={15} />, label: 'Table' },
        { value: 'calendar', icon: <CalendarDays size={15} />, label: 'Calendar' },
      ]} />
      {canManage && <Button className="maintenance-create-button" type="primary" icon={<Plus size={15} />} onClick={() => setCreateOpen(true)}>Plan maintenance</Button>}
    </div>

    {maintenanceQuery.isLoading ? <Card><Skeleton active paragraph={{ rows: 9 }} /></Card> : maintenanceQuery.isError ? <Card><Empty description={maintenanceErrorMessage(maintenanceQuery.error)}><Button onClick={() => void maintenanceQuery.refetch()}>Retry</Button></Empty></Card> : view === 'table' ? (
      <Card className="maintenance-table-card" title="Scheduled work" extra={<small>{occurrences.length} matching occurrences</small>}>
        <Table<InterventionItem> rowKey="id" columns={columns} dataSource={occurrences} pagination={{ pageSize: 8, showSizeChanger: false }} scroll={{ x: 1070 }} locale={{ emptyText: <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="No maintenance occurrence matches these filters" /> }} />
      </Card>
    ) : (
      <Card className="maintenance-calendar-card" title="Maintenance calendar" extra={<small>Click an event to open its station</small>}>
        <Calendar cellRender={(date, info) => info.type === 'date' ? <CalendarEvents date={date} occurrences={occurrences} onSelect={(occurrence) => navigate(`/stations/${occurrence.station.id}`)} /> : info.originNode} />
      </Card>
    )}

    <MaintenancePlanDrawer
      open={createOpen}
      stations={maintenanceQuery.data?.stations ?? []}
      technicians={maintenanceQuery.data?.technicians ?? []}
      submitting={createMutation.isPending}
      onClose={() => setCreateOpen(false)}
      onSubmit={(payload) => createMutation.mutate(payload)}
    />
    <RescheduleMaintenanceDrawer
      occurrence={selectedOccurrence}
      technicians={maintenanceQuery.data?.technicians ?? []}
      submitting={updateMutation.isPending}
      onClose={() => setSelectedOccurrence(null)}
      onSubmit={(payload) => selectedOccurrence && updateMutation.mutate({ id: selectedOccurrence.id, payload })}
    />
  </div>
}

function maintenanceErrorMessage(error: unknown) {
  if (isAxiosError<{ message?: string }>(error)) {
    return error.response?.data?.message ?? 'Maintenance planning could not be loaded.'
  }
  return 'Maintenance planning could not be loaded.'
}

function SummaryMetric({ icon: Icon, label, value, tone }: { icon: typeof Wrench; label: string; value: number; tone: MetricTone }) {
  return <MetricItem icon={<Icon size={18} />} label={label} value={value} tone={tone} />
}

function MaintenanceStatusTag({ status }: { status: InterventionStatus }) {
  if (status === 'assigned') return <Tag color="purple" className="workflow-tag"><span />Planned</Tag>
  if (status === 'resolved') return <Tag color="success" className="workflow-tag"><span />Completed</Tag>
  return <WorkflowTag value={status} />
}

function RecurrenceTag({ occurrence }: { occurrence: InterventionItem }) {
  const plan = occurrence.maintenance_plan
  if (!plan || plan.recurrence_frequency === 'none') return <Tag>One time</Tag>
  const interval = plan.recurrence_interval > 1 ? `Every ${plan.recurrence_interval} ${plan.recurrence_frequency.replace('daily', 'days').replace('weekly', 'weeks').replace('monthly', 'months')}` : plan.recurrence_frequency.charAt(0).toUpperCase() + plan.recurrence_frequency.slice(1)
  return <Tag color="cyan" icon={<Repeat2 size={11} />}>{interval}</Tag>
}

function CalendarEvents({ date, occurrences, onSelect }: { date: dayjs.Dayjs; occurrences: InterventionItem[]; onSelect: (occurrence: InterventionItem) => void }) {
  const matches = occurrences.filter((occurrence) => occurrence.scheduled_at && dayjs(occurrence.scheduled_at).isSame(date, 'day'))
  return <div className="maintenance-calendar-events">{matches.slice(0, 3).map((occurrence) => <button key={occurrence.id} type="button" className={`status-${occurrence.status}`} onClick={() => onSelect(occurrence)} title={occurrence.maintenance_plan?.title ?? occurrence.problem}><span />{dayjs(occurrence.scheduled_at).format('HH:mm')} {occurrence.station.name}</button>)}{matches.length > 3 && <small>+{matches.length - 3} more</small>}</div>
}

interface MaintenancePlanFormValues {
  station_id: number
  connector_id?: number
  assigned_technician_id: number
  title: string
  type: MaintenanceType
  priority: AlertSeverity
  instructions: string
  first_scheduled_at: dayjs.Dayjs
  estimated_duration_minutes: number
  recurrence_frequency: MaintenanceRecurrence
  recurrence_interval: number
  recurrence_ends_at?: dayjs.Dayjs
}

function MaintenancePlanDrawer({ open, stations, technicians, submitting, onClose, onSubmit }: {
  open: boolean
  stations: MaintenanceStationOption[]
  technicians: TechnicianOption[]
  submitting: boolean
  onClose: () => void
  onSubmit: (payload: MaintenancePlanPayload) => void
}) {
  const [form] = Form.useForm<MaintenancePlanFormValues>()
  const stationId = Form.useWatch('station_id', form)
  const recurrence = Form.useWatch('recurrence_frequency', form) ?? 'none'
  const selectedStation = stations.find((station) => station.id === stationId)
  const close = () => { form.resetFields(); onClose() }
  const submit = (values: MaintenancePlanFormValues) => onSubmit({
    ...values,
    connector_id: values.connector_id ?? null,
    first_scheduled_at: values.first_scheduled_at.toISOString(),
    recurrence_ends_at: values.recurrence_ends_at?.endOf('day').toISOString() ?? null,
  })

  return <Drawer className="maintenance-drawer" title="Plan maintenance" open={open} onClose={close} size={540} extra={<Button type="primary" loading={submitting} onClick={() => form.submit()}>Create plan</Button>}>
    <div className="maintenance-drawer-intro"><span><ShieldCheck size={18} /></span><p><strong>Controlled availability workflow</strong><small>The station remains usable until the assigned technician starts this occurrence.</small></p></div>
    <Form form={form} layout="vertical" requiredMark="optional" initialValues={{ type: 'preventive', priority: 'info', estimated_duration_minutes: 60, recurrence_frequency: 'none', recurrence_interval: 1 }} onFinish={submit}>
      <div className="maintenance-form-grid">
        <Form.Item label="Station" name="station_id" rules={[{ required: true }]}><Select showSearch optionFilterProp="label" placeholder="Select a station" options={stations.map((station) => ({ value: station.id, label: `${station.name} · ${station.reference}` }))} onChange={() => form.setFieldValue('connector_id', undefined)} /></Form.Item>
        <Form.Item label="Connector" name="connector_id"><Select allowClear disabled={!selectedStation} placeholder="All connectors" options={(selectedStation?.connectors ?? []).map((connector) => ({ value: connector.id, label: `${connector.external_id} · ${connector.type}` }))} /></Form.Item>
      </div>
      <Form.Item label="Plan title" name="title" rules={[{ required: true, min: 3 }]}><Input placeholder="Example: Monthly connector safety inspection" /></Form.Item>
      <div className="maintenance-form-grid three">
        <Form.Item label="Type" name="type" rules={[{ required: true }]}><Select options={[{ value: 'preventive', label: 'Preventive' }, { value: 'corrective', label: 'Corrective' }]} /></Form.Item>
        <Form.Item label="Priority" name="priority" rules={[{ required: true }]}><Select options={[{ value: 'info', label: 'Normal' }, { value: 'warning', label: 'High' }, { value: 'critical', label: 'Critical' }]} /></Form.Item>
        <Form.Item label="Duration" name="estimated_duration_minutes" rules={[{ required: true }]}><InputNumber min={5} max={1440} suffix="min" style={{ width: '100%' }} /></Form.Item>
      </div>
      <Form.Item label="Assigned technician" name="assigned_technician_id" rules={[{ required: true }]}><Select showSearch optionFilterProp="label" prefix={<UserRoundCog size={14} />} options={technicians.map((technician) => ({ value: technician.id, label: technician.name }))} /></Form.Item>
      <Form.Item label="Instructions and scope" name="instructions" rules={[{ required: true, min: 5 }]}><Input.TextArea rows={4} placeholder="Describe the checks, affected equipment and expected outcome." /></Form.Item>
      <Form.Item label="First scheduled start" name="first_scheduled_at" rules={[{ required: true }]}><DatePicker showTime minuteStep={5} format="DD MMM YYYY, HH:mm" style={{ width: '100%' }} disabledDate={(date) => date.endOf('day').isBefore(dayjs())} /></Form.Item>
      <div className="maintenance-form-grid three">
        <Form.Item label="Recurrence" name="recurrence_frequency" rules={[{ required: true }]}><Select options={[{ value: 'none', label: 'One time' }, { value: 'daily', label: 'Daily' }, { value: 'weekly', label: 'Weekly' }, { value: 'monthly', label: 'Monthly' }]} /></Form.Item>
        <Form.Item label="Repeat every" name="recurrence_interval" rules={[{ required: true }]}><InputNumber min={1} max={52} disabled={recurrence === 'none'} style={{ width: '100%' }} /></Form.Item>
        <Form.Item label="Recurrence end" name="recurrence_ends_at" rules={recurrence === 'none' ? [] : [{ required: true }]}><DatePicker disabled={recurrence === 'none'} style={{ width: '100%' }} /></Form.Item>
      </div>
      <Button type="primary" htmlType="submit" loading={submitting} block>Schedule maintenance</Button>
    </Form>
  </Drawer>
}

function RescheduleMaintenanceDrawer({ occurrence, technicians, submitting, onClose, onSubmit }: {
  occurrence: InterventionItem | null
  technicians: TechnicianOption[]
  submitting: boolean
  onClose: () => void
  onSubmit: (payload: Parameters<typeof updateMaintenanceOccurrence>[1]) => void
}) {
  const [form] = Form.useForm<{ assigned_technician_id: number; scheduled_at: dayjs.Dayjs; estimated_duration_minutes: number; priority: AlertSeverity; problem: string }>()
  useEffect(() => {
    if (!occurrence) return
    form.setFieldsValue({
      assigned_technician_id: occurrence.assigned_technician?.id,
      scheduled_at: occurrence.scheduled_at ? dayjs(occurrence.scheduled_at) : dayjs(),
      estimated_duration_minutes: occurrence.estimated_duration_minutes ?? 60,
      priority: occurrence.priority,
      problem: occurrence.problem,
    })
  }, [form, occurrence])
  const close = () => { form.resetFields(); onClose() }
  return <Drawer title="Edit maintenance occurrence" open={Boolean(occurrence)} onClose={close} size={500} extra={<Button type="primary" loading={submitting} onClick={() => form.submit()}>Save changes</Button>}>
    <p className="drawer-context">{occurrence?.reference} · {occurrence?.station.name}</p>
    <Form form={form} layout="vertical" requiredMark="optional" onFinish={(values) => onSubmit({ ...values, scheduled_at: values.scheduled_at.toISOString() })}>
      <Form.Item label="Assigned technician" name="assigned_technician_id" rules={[{ required: true }]}><Select options={technicians.map((technician) => ({ value: technician.id, label: technician.name }))} /></Form.Item>
      <Form.Item label="Scheduled start" name="scheduled_at" rules={[{ required: true }]}><DatePicker showTime minuteStep={5} format="DD MMM YYYY, HH:mm" style={{ width: '100%' }} /></Form.Item>
      <div className="maintenance-form-grid">
        <Form.Item label="Duration" name="estimated_duration_minutes" rules={[{ required: true }]}><InputNumber min={5} max={1440} suffix="min" style={{ width: '100%' }} /></Form.Item>
        <Form.Item label="Priority" name="priority" rules={[{ required: true }]}><Select options={[{ value: 'info', label: 'Normal' }, { value: 'warning', label: 'High' }, { value: 'critical', label: 'Critical' }]} /></Form.Item>
      </div>
      <Form.Item label="Instructions" name="problem" rules={[{ required: true, min: 5 }]}><Input.TextArea rows={5} /></Form.Item>
      <Button type="primary" htmlType="submit" loading={submitting} block>Save occurrence</Button>
    </Form>
  </Drawer>
}
