import { useDeferredValue, useEffect, useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { App, Button, DatePicker, Drawer, Empty, Form, Input, InputNumber, Select, Skeleton, Tooltip } from 'antd'
import dayjs from 'dayjs'
import {
  CalendarDays,
  CheckCircle2,
  ChevronDown,
  ClipboardCheck,
  Filter,
  MoreHorizontal,
  Plus,
  Search,
  UserRound,
} from 'lucide-react'
import { useNavigate } from 'react-router-dom'
import { MountainBanner } from '../components/MountainBanner'
import { getStations } from '../features/stations/stationApi'
import {
  createAlert,
  createIntervention,
  getAlerts,
  updateAlert,
} from '../features/operations/operationsApi'
import { WorkflowTag } from '../features/operations/WorkflowTag'
import { useAuth } from '../features/auth/useAuth'
import type { AlertItem, AlertSeverity, AlertStatus, InterventionPayload } from '../types/operations'

export function AlertsPage() {
  const { primaryRole, user } = useAuth()
  const technicianMode = primaryRole === 'technician'
  const canManageAlerts = user?.permissions.includes('alerts.manage') ?? false
  const canAssignAlerts = user?.permissions.includes('alerts.assign') ?? false
  const canManageInterventions = user?.permissions.includes('interventions.manage') ?? false
  const [search, setSearch] = useState('')
  const deferredSearch = useDeferredValue(search)
  const [severity, setSeverity] = useState<'all' | AlertSeverity>('all')
  const [status, setStatus] = useState<'all' | AlertStatus>('all')
  const [selectedId, setSelectedId] = useState<number | null>(null)
  const [drawer, setDrawer] = useState<'create' | 'assign' | 'intervention' | null>(null)
  const queryClient = useQueryClient()
  const navigate = useNavigate()
  const { message } = App.useApp()

  const filters = useMemo(() => ({
    search: deferredSearch.trim() || undefined,
    severity: severity === 'all' ? undefined : severity,
    status: status === 'all' ? undefined : status,
  }), [deferredSearch, severity, status])

  const alertsQuery = useQuery({ queryKey: ['alerts', filters, technicianMode], queryFn: () => getAlerts(filters) })
  const stationsQuery = useQuery({ queryKey: ['stations', 'alert-options'], queryFn: () => getStations({}), enabled: canManageAlerts })
  const alerts = useMemo(() => alertsQuery.data?.data ?? [], [alertsQuery.data?.data])
  const selectedAlert = alerts.find((alert) => alert.id === selectedId) ?? alerts[0] ?? null

  useEffect(() => {
    if (selectedId === null && alerts[0]) setSelectedId(alerts[0].id)
    if (selectedId !== null && alerts.length > 0 && !alerts.some((alert) => alert.id === selectedId)) setSelectedId(alerts[0].id)
  }, [alerts, selectedId])

  const updateMutation = useMutation({
    mutationFn: ({ alertId, payload }: { alertId: number; payload: Parameters<typeof updateAlert>[1] }) => updateAlert(alertId, payload),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['alerts'] })
      await queryClient.invalidateQueries({ queryKey: ['stations'] })
      setDrawer(null)
      void message.success('Alert workflow updated.')
    },
    onError: () => void message.error('The alert could not be updated.'),
  })

  const interventionMutation = useMutation({
    mutationFn: ({ alertId, payload }: { alertId: number; payload: InterventionPayload }) => createIntervention(alertId, payload),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['alerts'] })
      await queryClient.invalidateQueries({ queryKey: ['interventions'] })
      setDrawer(null)
      void message.success('Intervention created and assigned.')
    },
    onError: () => void message.error('The intervention could not be created.'),
  })

  const createMutation = useMutation({
    mutationFn: createAlert,
    onSuccess: async (created) => {
      await queryClient.invalidateQueries({ queryKey: ['alerts'] })
      setSelectedId(created.id)
      setDrawer(null)
      void message.success('Manual alert created.')
    },
    onError: () => void message.error('The alert could not be created.'),
  })

  return (
    <div className="alerts-page">
      <MountainBanner
        color={technicianMode ? 'orange' : 'pink'}
        breadcrumb={[technicianMode ? 'Technician' : primaryRole === 'admin' ? 'Organization' : 'Operations', technicianMode ? 'My alerts' : 'Alerts']}
        title={technicianMode ? 'My alerts' : 'Alerts'}
        count={alertsQuery.data?.summary.total ?? 0}
        subtitle={technicianMode
          ? 'Assigned alerts only. Review technical context, OCPP logs, recommended actions, and intervention entry points.'
          : 'Split-panel alert and intervention management for station availability incidents.'}
      />

      <div className="alerts-split">
        <section className="alerts-list-panel">
          <div className="alerts-search-row">
            <Input value={search} onChange={(event) => setSearch(event.target.value)} prefix={<Search size={14} />} placeholder="Search alerts" allowClear />
            {canManageAlerts && <Button type="primary" onClick={() => setDrawer('create')}>Create <ChevronDown size={14} /></Button>}
          </div>
          <div className="alerts-filter-pills">
            {(['all', 'critical', 'warning', 'info'] as const).map((item) => (
              <button key={item} type="button" className={severity === item ? 'active' : ''} onClick={() => setSeverity(item)}>{item === 'all' ? 'All severity' : item}</button>
            ))}
          </div>
          <div className="alerts-status-row">
            {(['all', 'new', 'in-progress', 'resolved'] as const).map((item) => (
              <button key={item} type="button" className={status === item ? 'active' : ''} onClick={() => setStatus(item)}>{item === 'all' ? 'All status' : item.replace('-', ' ')}</button>
            ))}
            <Tooltip title="Severity and status filters"><Filter size={14} /></Tooltip>
          </div>

          <div className="alerts-list">
            {alertsQuery.isLoading ? <Skeleton active paragraph={{ rows: 8 }} /> : alerts.length === 0 ? <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="No matching alerts" /> : alerts.map((alert) => (
              <button key={alert.id} type="button" className={`alert-list-item ${selectedAlert?.id === alert.id ? 'selected' : ''}`} onClick={() => setSelectedId(alert.id)}>
                <div><strong>{alert.title}</strong><WorkflowTag value={alert.severity} /></div>
                <p>{alert.station.name}</p>
                <div><WorkflowTag value={alert.status} /><small>{alert.detected_relative}</small></div>
              </button>
            ))}
          </div>
        </section>

        <section className="alert-detail-panel">
          {!selectedAlert ? <Empty description="Select an alert" /> : (
            <AlertDetails
              alert={selectedAlert}
              technicianMode={technicianMode}
              canManageAlerts={canManageAlerts}
              canAssignAlerts={canAssignAlerts}
              canManageInterventions={canManageInterventions}
              updating={updateMutation.isPending}
              onAssign={() => setDrawer('assign')}
              onCreateIntervention={() => setDrawer('intervention')}
              onStatus={(nextStatus) => updateMutation.mutate({ alertId: selectedAlert.id, payload: { status: nextStatus } })}
              onOpenIntervention={() => navigate(technicianMode ? '/my-interventions' : '/interventions')}
            />
          )}
        </section>
      </div>

      <CreateAlertDrawer
        open={drawer === 'create'}
        stations={stationsQuery.data?.data ?? []}
        technicians={alertsQuery.data?.technicians ?? []}
        submitting={createMutation.isPending}
        onClose={() => setDrawer(null)}
        onSubmit={(values) => createMutation.mutate(values)}
      />
      <AssignDrawer
        open={drawer === 'assign'}
        alert={selectedAlert}
        technicians={alertsQuery.data?.technicians ?? []}
        submitting={updateMutation.isPending}
        onClose={() => setDrawer(null)}
        onSubmit={(technicianId) => selectedAlert && updateMutation.mutate({ alertId: selectedAlert.id, payload: { assigned_technician_id: technicianId } })}
      />
      <InterventionDrawer
        open={drawer === 'intervention'}
        alert={selectedAlert}
        technicians={alertsQuery.data?.technicians ?? []}
        submitting={interventionMutation.isPending}
        onClose={() => setDrawer(null)}
        onSubmit={(payload) => selectedAlert && interventionMutation.mutate({ alertId: selectedAlert.id, payload })}
      />
    </div>
  )
}

function AlertDetails({ alert, technicianMode, canManageAlerts, canAssignAlerts, canManageInterventions, updating, onAssign, onCreateIntervention, onStatus, onOpenIntervention }: {
  alert: AlertItem
  technicianMode: boolean
  canManageAlerts: boolean
  canAssignAlerts: boolean
  canManageInterventions: boolean
  updating: boolean
  onAssign: () => void
  onCreateIntervention: () => void
  onStatus: (status: AlertStatus) => void
  onOpenIntervention: () => void
}) {
  const hasActiveIntervention = alert.intervention
    && ['assigned', 'in-progress', 'paused', 'waiting-parts'].includes(alert.intervention.status)

  return <div className="alert-detail-content">
    <header><div><h2>{alert.title}</h2><p>{alert.reference} - {alert.station.name}</p></div><Button type="text" icon={<MoreHorizontal size={17} />} /></header>
    <div className="alert-detail-tags"><WorkflowTag value={alert.severity} /><WorkflowTag value={alert.status} /><span>Problem type: {alert.problem_type}</span></div>
    <div className="alert-meta-grid">
      <div><CalendarDays size={15} /><span><strong>{dayjs(alert.detected_at).format('MMM D, HH:mm')}</strong><small>Created</small></span></div>
      <div><UserRound size={15} /><span><strong>{alert.assigned_technician?.name ?? 'Unassigned'}</strong><small>Assigned technician</small></span></div>
      <div><ClipboardCheck size={15} /><span><strong>{alert.intervention?.reference ?? 'Field intervention'}</strong><small>Intervention</small></span></div>
    </div>
    <section className="alert-description"><h3>Description</h3><p>{alert.description}</p></section>
    {alert.ocpp_log && <section className="ocpp-context"><h3>OCPP context</h3><pre>{alert.ocpp_log}</pre></section>}
    {(alert.suggested_cause || alert.recommended_action) && <section className="field-suggestion"><strong>Diagnostic suggestion</strong><p>{alert.suggested_cause} {alert.recommended_action}</p></section>}
    <section className="workflow-timeline"><h3>Timeline</h3>{alert.events.map((event) => <div key={event.id}><span><CheckCircle2 size={13} /></span><p>{event.description}<small>{event.occurred_relative}</small></p></div>)}</section>
    <div className="alert-actions">
      {technicianMode ? (
        alert.intervention
          ? <Button type="primary" onClick={onOpenIntervention}>Open intervention</Button>
          : <Button disabled>No intervention assigned</Button>
      ) : <>
        {canAssignAlerts && alert.status !== 'resolved' && <Button icon={<Plus size={15} />} onClick={onAssign}>Assign technician</Button>}
        {canManageAlerts && alert.status === 'new' && <Button className="violet-button" loading={updating} onClick={() => onStatus('in-progress')}>Acknowledge alert</Button>}
        {canManageInterventions && alert.status !== 'resolved' && !hasActiveIntervention && <Button icon={<ClipboardCheck size={15} />} onClick={onCreateIntervention}>Create intervention</Button>}
        {canManageInterventions && hasActiveIntervention && <Button icon={<ClipboardCheck size={15} />} onClick={onOpenIntervention}>Open intervention</Button>}
        {canManageAlerts && alert.status !== 'resolved' && (
          hasActiveIntervention
            ? <Tooltip title="Complete or cancel the active intervention before resolving this alert."><span><Button type="primary" disabled>Resolve</Button></span></Tooltip>
            : <Button type="primary" loading={updating} onClick={() => onStatus('resolved')}>Resolve</Button>
        )}
      </>}
    </div>
  </div>
}

interface AlertFormValues {
  station_id: number
  assigned_technician_id?: number
  title: string
  problem_type: string
  severity: AlertSeverity
  description: string
  due_at?: dayjs.Dayjs
}

function CreateAlertDrawer({ open, stations, technicians, submitting, onClose, onSubmit }: {
  open: boolean
  stations: Array<{ id: number; name: string }>
  technicians: Array<{ id: number; name: string }>
  submitting: boolean
  onClose: () => void
  onSubmit: (values: Omit<AlertFormValues, 'due_at'> & { due_at?: string; source: 'operator' }) => void
}) {
  const [form] = Form.useForm<AlertFormValues>()
  return <Drawer title="Create manual alert" open={open} onClose={onClose} size={520} extra={<Button type="primary" loading={submitting} onClick={() => form.submit()}>Create alert</Button>}>
    <Form form={form} layout="vertical" requiredMark="optional" onFinish={(values) => onSubmit({ ...values, source: 'operator', due_at: values.due_at?.toISOString() })}>
      <Form.Item label="Station" name="station_id" rules={[{ required: true }]}><Select showSearch optionFilterProp="label" options={stations.map((station) => ({ value: station.id, label: station.name }))} /></Form.Item>
      <Form.Item label="Assigned technician" name="assigned_technician_id"><Select allowClear options={technicians.map((technician) => ({ value: technician.id, label: technician.name }))} /></Form.Item>
      <Form.Item label="Title" name="title" rules={[{ required: true }]}><Input placeholder="Station disconnected" /></Form.Item>
      <Form.Item label="Problem type" name="problem_type" rules={[{ required: true }]}><Input placeholder="No heartbeat received" /></Form.Item>
      <Form.Item label="Severity" name="severity" initialValue="warning" rules={[{ required: true }]}><Select options={['critical', 'warning', 'info'].map((value) => ({ value, label: value }))} /></Form.Item>
      <Form.Item
        label="Due date"
        name="due_at"
        extra="Optional. The platform applies an SLA automatically: critical 15 min, warning 1 h, information 4 h."
      ><DatePicker showTime style={{ width: '100%' }} /></Form.Item>
      <Form.Item label="Description" name="description" rules={[{ required: true }]}><Input.TextArea rows={5} /></Form.Item>
      <Button type="primary" htmlType="submit" loading={submitting} block>Create alert</Button>
    </Form>
  </Drawer>
}

function AssignDrawer({ open, alert, technicians, submitting, onClose, onSubmit }: {
  open: boolean
  alert: AlertItem | null
  technicians: Array<{ id: number; name: string }>
  submitting: boolean
  onClose: () => void
  onSubmit: (technicianId: number) => void
}) {
  const [form] = Form.useForm<{ technician_id: number }>()
  return <Drawer title="Assign technician" open={open} onClose={onClose} size={440} extra={<Button type="primary" loading={submitting} onClick={() => form.submit()}>Assign</Button>}>
    <p className="drawer-context">{alert?.reference} - {alert?.station.name}</p>
    <Form form={form} layout="vertical" onFinish={(values) => onSubmit(values.technician_id)} initialValues={{ technician_id: alert?.assigned_technician?.id }}>
      <Form.Item label="Technician" name="technician_id" rules={[{ required: true }]}><Select options={technicians.map((technician) => ({ value: technician.id, label: technician.name }))} /></Form.Item>
      <Button type="primary" htmlType="submit" loading={submitting} block>Assign technician</Button>
    </Form>
  </Drawer>
}

function InterventionDrawer({ open, alert, technicians, submitting, onClose, onSubmit }: {
  open: boolean
  alert: AlertItem | null
  technicians: Array<{ id: number; name: string }>
  submitting: boolean
  onClose: () => void
  onSubmit: (payload: InterventionPayload) => void
}) {
  const [form] = Form.useForm<InterventionPayload & { scheduled_at_picker?: dayjs.Dayjs }>()
  return <Drawer title="Create intervention" open={open} onClose={onClose} size={500} extra={<Button type="primary" loading={submitting} onClick={() => form.submit()}>Create</Button>}>
    <p className="drawer-context">{alert?.reference} - {alert?.station.name}</p>
    <Form form={form} layout="vertical" requiredMark="optional" onFinish={(values) => onSubmit({ ...values, scheduled_at: values.scheduled_at_picker?.toISOString() })} initialValues={{ assigned_technician_id: alert?.assigned_technician?.id, estimated_duration_minutes: 90, problem: alert?.description }}>
      <Form.Item label="Technician" name="assigned_technician_id" rules={[{ required: true }]}><Select options={technicians.map((technician) => ({ value: technician.id, label: technician.name }))} /></Form.Item>
      <Form.Item label="Scheduled visit" name="scheduled_at_picker"><DatePicker showTime style={{ width: '100%' }} /></Form.Item>
      <Form.Item label="Estimated duration (minutes)" name="estimated_duration_minutes"><InputNumber min={5} max={1440} style={{ width: '100%' }} /></Form.Item>
      <Form.Item label="Problem" name="problem"><Input.TextArea rows={4} /></Form.Item>
      <Form.Item label="Preparation notes" name="comments"><Input.TextArea rows={3} /></Form.Item>
      <Button type="primary" htmlType="submit" loading={submitting} block>Create intervention</Button>
    </Form>
  </Drawer>
}
