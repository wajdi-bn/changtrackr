import { useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Alert, App, Avatar, Button, Card, Drawer, Empty, Form, Input, InputNumber, Modal, Popconfirm, Select, Skeleton, Table, Tag, Tabs, Tooltip } from 'antd'
import type { ColumnsType } from 'antd/es/table'
import { isAxiosError } from 'axios'
import dayjs from 'dayjs'
import { QRCodeSVG } from 'qrcode.react'
import {
  Activity,
  AlertTriangle,
  ArrowLeft,
  LockOpen,
  MapPin,
  PencilLine,
  Download,
  Printer,
  Plus,
  Power,
  QrCode,
  RefreshCw,
  Settings,
  Trash2,
  Wrench,
  Zap,
} from 'lucide-react'
import { useNavigate, useParams } from 'react-router-dom'
import { Area, AreaChart, Bar, BarChart, CartesianGrid, ResponsiveContainer, Tooltip as ChartTooltip, XAxis, YAxis } from 'recharts'
import { createConnector, deleteConnector, getStation, getStationCommands, restartStation, setStationMaintenanceMode, unlockStationConnector, updateConnector, updateStation } from '../features/stations/stationApi'
import { StationStatusTag } from '../features/stations/StationStatusTag'
import { availabilityReasonLabel } from '../features/stations/availabilityLabels'
import { useAuth } from '../features/auth/useAuth'
import { ConnectorTypeIcon } from '../features/charging/ConnectorTypeIcon'
import { DocumentManager } from '../features/documents/DocumentManager'
import { getMaintenances } from '../features/operations/operationsApi'
import { WorkflowTag } from '../features/operations/WorkflowTag'
import type { InterventionItem, InterventionStatus } from '../types/operations'
import type { Connector, ConnectorPayload, MaintenanceModeResponse, OcppCommand, OcppCommandStatus, Station } from '../types/station'

const utilizationData = [
  { label: '08:00', value: 42 }, { label: '10:00', value: 58 }, { label: '12:00', value: 76 },
  { label: '14:00', value: 68 }, { label: '16:00', value: 82 }, { label: '18:00', value: 71 },
]

const energyData = [
  { label: 'Mon', energy: 284 }, { label: 'Tue', energy: 316 }, { label: 'Wed', energy: 298 },
  { label: 'Thu', energy: 421 }, { label: 'Fri', energy: 388 }, { label: 'Sat', energy: 445 },
]

export function StationDetailPage() {
  const { stationId } = useParams()
  const numericStationId = Number(stationId)
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { message, modal } = App.useApp()
  const { user, primaryRole } = useAuth()
  const [connectorDrawerOpen, setConnectorDrawerOpen] = useState(false)
  const [selectedConnector, setSelectedConnector] = useState<Connector | null>(null)
  const [qrConnector, setQrConnector] = useState<Connector | null>(null)
  const canUpdate = user?.permissions.includes('stations.update') ?? false
  const canManageConnectors = canUpdate && (user?.permissions.includes('connectors.manage') ?? false)
  const canViewCommands = user?.permissions.includes('ocpp_commands.view') ?? false
  const canExecuteCommands = user?.permissions.includes('ocpp_commands.execute') ?? false
  const canViewMaintenance = user?.permissions.includes('maintenances.view') ?? false
  const isTechnician = primaryRole === 'technician'

  const stationQuery = useQuery({
    queryKey: ['station', numericStationId],
    queryFn: () => getStation(numericStationId),
    enabled: Number.isFinite(numericStationId),
  })

  const commandsQuery = useQuery({
    queryKey: ['station-commands', numericStationId],
    queryFn: () => getStationCommands(numericStationId),
    enabled: Number.isFinite(numericStationId) && canViewCommands,
    refetchInterval: (query) => query.state.data?.data.some((command) => ['queued', 'sent'].includes(command.status)) ? 2_000 : false,
  })

  const maintenanceQuery = useQuery({
    queryKey: ['maintenances', { station_id: numericStationId }],
    queryFn: () => getMaintenances({ station_id: numericStationId }),
    enabled: Number.isFinite(numericStationId) && canViewMaintenance,
  })

  const refreshStationData = async () => {
    await Promise.all([
      queryClient.invalidateQueries({ queryKey: ['station', numericStationId] }),
      queryClient.invalidateQueries({ queryKey: ['stations'] }),
      queryClient.invalidateQueries({ queryKey: ['station-commands', numericStationId] }),
      queryClient.invalidateQueries({ queryKey: ['maintenances'] }),
    ])
  }

  const resetMutation = useMutation({
    mutationFn: () => restartStation(numericStationId),
    onSuccess: async () => {
      await refreshStationData()
      void message.success('Soft restart command queued.')
    },
    onError: (error) => void message.error(apiErrorMessage(error, 'The restart command could not be queued.')),
  })

  const unlockMutation = useMutation({
    mutationFn: (connectorId: number) => unlockStationConnector(numericStationId, connectorId),
    onSuccess: async (_, connectorId) => {
      await refreshStationData()
      const connector = stationQuery.data?.connectors.find((item) => item.id === connectorId)
      void message.success(`Unlock command queued for connector ${connector?.external_id ?? connectorId}.`)
    },
    onError: (error) => void message.error(apiErrorMessage(error, 'The connector could not be unlocked.')),
  })

  const maintenanceMutation = useMutation<Station | MaintenanceModeResponse, unknown, Station>({
    mutationFn: (station: Station) => station.ocpp_managed
      ? setStationMaintenanceMode(station.id, station.availability_override !== 'maintenance')
      : updateStation(station.id, { status: station.status === 'maintenance' ? 'offline' : 'maintenance' }),
    onSuccess: async (result, station) => {
      await refreshStationData()
      if ('ocpp_sync' in result && result.ocpp_sync === 'not_connected') {
        void message.warning('Maintenance mode was updated locally. The station is offline, so no OCPP command was sent.')
        return
      }
      void message.success(station.availability_override === 'maintenance' ? 'Maintenance mode cleared.' : 'Maintenance mode enabled.')
    },
    onError: (error) => void message.error(apiErrorMessage(error, 'Station maintenance mode could not be updated.')),
  })

  const connectorMutation = useMutation({
    mutationFn: (values: ConnectorPayload) => selectedConnector
      ? updateConnector(numericStationId, selectedConnector.id, values)
      : createConnector(numericStationId, values),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['station', numericStationId] })
      await queryClient.invalidateQueries({ queryKey: ['stations'] })
      setConnectorDrawerOpen(false)
      setSelectedConnector(null)
      void message.success(selectedConnector ? 'Connector updated.' : 'Connector added.')
    },
    onError: () => void message.error('Connector could not be saved.'),
  })

  const deleteConnectorMutation = useMutation({
    mutationFn: (connectorId: number) => deleteConnector(numericStationId, connectorId),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['station', numericStationId] })
      await queryClient.invalidateQueries({ queryKey: ['stations'] })
      void message.success('Connector deleted.')
    },
    onError: () => void message.error('Connector could not be deleted.'),
  })

  if (stationQuery.isLoading) {
    return <div className="station-detail-loading"><Skeleton active paragraph={{ rows: 12 }} /></div>
  }

  if (stationQuery.isError || !stationQuery.data) {
    return <Alert type="error" showIcon title="Unable to load this station" action={<Button onClick={() => navigate('/stations')}>Back to stations</Button>} />
  }

  const station = stationQuery.data
  const confirmRestart = () => {
    modal.confirm({
      title: 'Restart this station?',
      content: 'A Soft Reset command will be sent through OCPP. Active charging sessions may be briefly interrupted by the station.',
      okText: 'Queue soft restart',
      cancelText: 'Cancel',
      okButtonProps: { danger: true },
      onOk: () => resetMutation.mutateAsync(),
    })
  }
  const confirmUnlock = (connector: Connector) => {
    modal.confirm({
      title: `Unlock connector ${connector.external_id}?`,
      content: 'The station will be asked to release the physical connector lock. Use this only when a cable remains locked.',
      okText: 'Queue unlock',
      cancelText: 'Cancel',
      onOk: () => unlockMutation.mutateAsync(connector.id),
    })
  }
  const confirmMaintenance = () => {
    const leaving = station.availability_override === 'maintenance' || (!station.ocpp_managed && station.status === 'maintenance')
    modal.confirm({
      title: leaving ? 'Return this station to service?' : 'Set this station to maintenance?',
      content: leaving
        ? 'The local maintenance override will be cleared. If the station is connected, OCPP will also request Operative availability.'
        : 'The platform will immediately make the station unavailable to clients. If connected, OCPP will also request Inoperative availability.',
      okText: leaving ? 'Leave maintenance' : 'Enable maintenance',
      cancelText: 'Cancel',
      onOk: () => maintenanceMutation.mutateAsync(station),
    })
  }
  const metrics = [
    { label: 'Energy today', value: `${station.energy_today_kwh} kWh`, icon: Zap },
    { label: 'Sessions today', value: station.sessions_today.toString(), icon: Activity },
    { label: 'Revenue today', value: `${station.revenue_today} TND`, icon: Power },
    { label: 'Open alerts', value: station.open_alerts_count.toString(), icon: Settings },
  ]

  const tabItems = [
    {
      key: 'overview',
      label: 'Overview',
      children: (
        <div className="station-overview-grid">
          <MetricChartCard title="Utilization rate" subtitle={`${station.utilization_percent}% live utilization`}>
            <ResponsiveContainer width="100%" height="100%">
              <AreaChart data={utilizationData}><CartesianGrid strokeDasharray="3 3" stroke="#e5e7eb" /><XAxis dataKey="label" tickLine={false} axisLine={false} /><YAxis tickLine={false} axisLine={false} /><ChartTooltip /><Area type="monotone" dataKey="value" stroke="#7c3aed" fill="#ede9fe" strokeWidth={2} /></AreaChart>
            </ResponsiveContainer>
          </MetricChartCard>
          <MetricChartCard title="Energy delivered" subtitle="Recent daily energy output">
            <ResponsiveContainer width="100%" height="100%">
              <BarChart data={energyData}><CartesianGrid strokeDasharray="3 3" stroke="#e5e7eb" /><XAxis dataKey="label" tickLine={false} axisLine={false} /><YAxis tickLine={false} axisLine={false} /><ChartTooltip /><Bar dataKey="energy" fill="#22c55e" radius={[6, 6, 0, 0]} /></BarChart>
            </ResponsiveContainer>
          </MetricChartCard>
          <Card title="Live metrics" className="station-live-card" extra={<small>Current operational counters</small>}>
            {metrics.map(({ label, value, icon: Icon }) => <div key={label}><span><Icon size={16} />{label}</span><strong>{value}</strong></div>)}
          </Card>
        </div>
      ),
    },
    {
      key: 'connectors',
      label: 'Connectors',
      children: (
        <Card
          className="connectors-panel"
          title={isTechnician ? 'Connector technical view' : 'Connector management'}
          extra={canManageConnectors && <Button type="primary" icon={<Plus size={15} />} onClick={() => { setSelectedConnector(null); setConnectorDrawerOpen(true) }}>Add connector</Button>}
        >
          <div className="connectors-grid">
            {station.connectors.map((connector) => (
              <div key={connector.id} className="connector-card">
                <div className="connector-heading">
                  <span className="connector-icon"><Zap size={18} /></span>
                  <div><h3>Connector {connector.external_id}</h3><p>{connector.type} - {connector.current_type}</p></div>
                  <StationStatusTag status={connector.status} />
                </div>
                <div className="connector-facts">
                  <InfoFact label="Power" value={`${connector.max_power_kw} kW`} />
                  <InfoFact label="Last update" value={connector.last_status_relative ?? 'Never'} />
                </div>
                <div className={`connector-fault ${connector.error_code ? 'has-error' : ''}`}><AlertTriangle size={14} />{connector.error_code ?? 'No active connector faults'}</div>
                {station.ocpp_managed && <div className="connector-availability-note"><Activity size={14} />{availabilityReasonLabel(connector.availability_reason)}</div>}
                <div className="connector-actions">
                  {canManageConnectors && <Button type="text" icon={<QrCode size={15} />} onClick={() => setQrConnector(connector)}>QR label</Button>}
                  {canExecuteCommands && station.ocpp_managed && (
                    <Tooltip title={!station.ocpp_is_connected ? 'The station must be online.' : connector.ocpp_connector_id === null ? 'No OCPP connector identifier is configured.' : undefined}>
                      <span>
                        <Button
                          type="text"
                          icon={<LockOpen size={15} />}
                          disabled={!station.ocpp_is_connected || connector.ocpp_connector_id === null}
                          loading={unlockMutation.isPending && unlockMutation.variables === connector.id}
                          onClick={() => confirmUnlock(connector)}
                        >Unlock</Button>
                      </span>
                    </Tooltip>
                  )}
                  {canManageConnectors && <>
                    <Button type="text" icon={<PencilLine size={15} />} onClick={() => { setSelectedConnector(connector); setConnectorDrawerOpen(true) }}>Edit connector</Button>
                    <Popconfirm
                      title="Delete this connector?"
                      description={`Connector ${connector.external_id} will be removed from this station.`}
                      okText="Delete"
                      okButtonProps={{ danger: true, loading: deleteConnectorMutation.isPending }}
                      cancelText="Cancel"
                      onConfirm={() => deleteConnectorMutation.mutate(connector.id)}
                    >
                      <Button type="text" danger icon={<Trash2 size={15} />}>Delete</Button>
                    </Popconfirm>
                  </>}
                </div>
              </div>
            ))}
          </div>
        </Card>
      ),
    },
    ...(canViewCommands ? [{
      key: 'command-history',
      label: 'Command history',
      children: (
        <Card className="ocpp-command-history" title="OCPP supervision history" extra={<small>Latest 20 commands</small>}>
          {commandsQuery.isError && <Alert type="error" showIcon title="Unable to load command history" action={<Button size="small" onClick={() => void commandsQuery.refetch()}>Retry</Button>} />}
          <Table<OcppCommand>
            rowKey="uuid"
            columns={commandColumns}
            dataSource={commandsQuery.data?.data ?? []}
            loading={commandsQuery.isLoading}
            pagination={false}
            scroll={{ x: 860 }}
            locale={{ emptyText: <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="No supervision command has been sent to this station" /> }}
          />
        </Card>
      ),
    }] : []),
    ...(canViewMaintenance ? [{
      key: 'maintenance',
      label: 'Maintenance',
      children: <Card className="station-maintenance-panel" title="Maintenance history and schedule" extra={canExecuteCommands && <Button size="small" icon={<Plus size={14} />} onClick={() => navigate('/maintenance')}>Plan maintenance</Button>}>
        <Table<InterventionItem>
          rowKey="id"
          loading={maintenanceQuery.isLoading}
          dataSource={maintenanceQuery.data?.data ?? []}
          pagination={{ pageSize: 6, showSizeChanger: false }}
          columns={[
            { title: 'Reference', dataIndex: 'reference', width: 150, render: (value: string, item) => <span className="station-maintenance-reference"><strong>{value}</strong><small>{item.maintenance_plan?.title ?? 'Maintenance'}</small></span> },
            { title: 'Type', key: 'type', width: 120, render: (_, item) => <Tag color={item.maintenance_plan?.type === 'preventive' ? 'green' : 'orange'}>{item.maintenance_plan?.type ?? 'corrective'}</Tag> },
            { title: 'Technician', key: 'technician', width: 170, render: (_, item) => item.assigned_technician?.name ?? 'Unassigned' },
            { title: 'Scheduled', dataIndex: 'scheduled_at', width: 170, render: (value: string | null) => value ? dayjs(value).format('DD MMM YYYY, HH:mm') : 'Not scheduled' },
            { title: 'Status', dataIndex: 'status', width: 130, render: (value: InterventionStatus) => value === 'assigned' ? <Tag color="purple">Planned</Tag> : value === 'resolved' ? <Tag color="success">Completed</Tag> : <WorkflowTag value={value} /> },
          ]}
          scroll={{ x: 760 }}
          locale={{ emptyText: <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="No maintenance has been scheduled for this station" /> }}
        />
      </Card>,
    }] : []),
    ...['Sessions', 'Alerts'].map((label) => ({
      key: label.toLowerCase(),
      label,
      children: <Card><Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description={`${label} will be connected in its dedicated vertical slice.`} /></Card>,
    })),
    {
      key: 'documents',
      label: 'Documents',
      children: <DocumentManager context="station" recordId={station.id} title="Station documents" subtitle="Manuals, certificates, installation records and maintenance references." />,
    },
  ]

  return (
    <div className="station-detail-page">
      <Button className="station-back-button" type="text" icon={<ArrowLeft size={16} />} onClick={() => navigate('/stations')}>Back to stations</Button>

      <section className="station-detail-hero">
        <div>
          <span className="station-detail-badges"><StationStatusTag status={station.status} /><span>{station.reference}</span></span>
          <h1>{station.name}</h1>
          <p><MapPin size={15} />{station.address}</p>
        </div>
        {canExecuteCommands ? (
          <div className="station-command-buttons">
            {station.ocpp_managed && (
              <Tooltip title={!station.ocpp_is_connected ? 'The station must be online to receive a restart command.' : undefined}>
                <span>
                  <Button disabled={!station.ocpp_is_connected} icon={<RefreshCw size={15} />} loading={resetMutation.isPending} onClick={confirmRestart}>Restart station</Button>
                </span>
              </Tooltip>
            )}
            <Tooltip title={station.maintenance_intervention_id ? 'Maintenance mode is controlled by the active technician intervention.' : undefined}>
              <span>
                <Button className="maintenance-button" disabled={station.maintenance_intervention_id !== null} icon={<Wrench size={15} />} loading={maintenanceMutation.isPending} onClick={confirmMaintenance}>
                  {station.maintenance_intervention_id
                    ? 'Maintenance in progress'
                    : station.ocpp_managed
                      ? (station.availability_override === 'maintenance' ? 'Leave maintenance mode' : 'Set maintenance mode')
                      : (station.status === 'maintenance' ? 'Leave maintenance mode' : 'Set maintenance mode')}
                </Button>
              </span>
            </Tooltip>
          </div>
        ) : isTechnician ? <Button icon={<Wrench size={15} />} onClick={() => navigate('/assigned-alerts')}>View assigned alerts</Button> : null}
      </section>

      <div className="station-info-grid">
        <InfoFact label="Location" value={station.location} />
        <InfoFact label="GPS" value={`${station.latitude.toFixed(4)}, ${station.longitude.toFixed(4)}`} />
        <InfoFact label="Model" value={station.model} />
        <InfoFact label="Manufacturer" value={station.manufacturer} />
        <InfoFact label="OCPP version" value={station.ocpp_version} />
        <InfoFact label="OCPP identity" value={station.ocpp_identity ?? 'Not configured'} />
        <InfoFact label="OCPP connection" value={station.ocpp_is_connected ? 'Connected' : 'Offline'} />
        <InfoFact label="Power" value={`${station.max_power_kw} kW`} />
        <InfoFact label="Last heartbeat" value={station.last_heartbeat_relative} />
        <InfoFact label="Availability rule" value={availabilityReasonLabel(station.availability_reason)} />
        <InfoFact label="Uptime" value={`${station.uptime_percent}%`} />
      </div>

      <Tabs className="station-detail-tabs" defaultActiveKey="overview" items={tabItems} />

      <ConnectorDrawer
        open={connectorDrawerOpen}
        connector={selectedConnector}
        managed={station.ocpp_managed}
        submitting={connectorMutation.isPending}
        onClose={() => { setConnectorDrawerOpen(false); setSelectedConnector(null) }}
        onSubmit={(values) => connectorMutation.mutate(values)}
      />
      <ConnectorQrModal station={station} connector={qrConnector} onClose={() => setQrConnector(null)} />
    </div>
  )
}

function ConnectorQrModal({ station, connector, onClose }: { station: Station; connector: Connector | null; onClose: () => void }) {
  const { message } = App.useApp()
  const labelRef = useRef<HTMLDivElement | null>(null)
  const configuredAppOrigin = import.meta.env.VITE_QR_APP_URL?.trim()
  const appOrigin = (configuredAppOrigin || window.location.origin).replace(/\/$/, '')
  const url = connector?.qr_token ? `${appOrigin}/charge/scan/${connector.qr_token}` : ''

  const downloadLabel = () => {
    const svg = labelRef.current?.querySelector('svg')
    if (!svg || !connector) return
    const blob = new Blob([svg.outerHTML], { type: 'image/svg+xml;charset=utf-8' })
    const downloadUrl = URL.createObjectURL(blob)
    const anchor = document.createElement('a')
    anchor.href = downloadUrl
    anchor.download = `chargetrackr-${station.reference}-${connector.external_id}-qr.svg`
    anchor.click()
    URL.revokeObjectURL(downloadUrl)
  }

  const printLabel = () => {
    if (!labelRef.current) return
    const printWindow = window.open('', '_blank', 'width=480,height=620')
    if (!printWindow) {
      void message.error('Allow pop-ups to print this QR label.')
      return
    }
    printWindow.document.write(`<html><head><title>ChargeTrackr connector label</title><style>body{font-family:Arial,sans-serif;padding:28px}.connector-qr-card{text-align:center}.connector-qr-brand{display:flex;gap:12px;align-items:center;text-align:left;margin-bottom:20px}.connector-qr-brand span{display:flex;flex-direction:column;gap:4px}.connector-qr-code{display:inline-block;padding:14px;border:1px solid #d9dfdb;border-radius:8px}.connector-qr-card p{font-size:13px;color:#506057}</style></head><body>${labelRef.current.outerHTML}<script>window.onload=()=>window.print()</script></body></html>`)
    printWindow.document.close()
  }

  return <Modal open={Boolean(connector)} title="Connector QR label" footer={<><Button icon={<Download size={15} />} onClick={downloadLabel}>Download SVG</Button><Button icon={<Printer size={15} />} onClick={printLabel}>Print label</Button><Button type="primary" onClick={onClose}>Done</Button></>} onCancel={onClose} width={420}>
    {connector && <div ref={labelRef} className="connector-qr-card">
      <div className="connector-qr-brand"><ConnectorTypeIcon type={connector.type} /><span><strong>{station.name}</strong><small>Connector {connector.external_id} · {connector.type} · {connector.max_power_kw} kW</small></span></div>
      <div className="connector-qr-code"><QRCodeSVG value={url} size={210} level="M" marginSize={2} /></div>
      <p>Print and attach this label to the physical connector. Clients scan it to start at this exact connector.</p>
      <Button icon={<QrCode size={15} />} onClick={async () => { await navigator.clipboard.writeText(url); void message.success('Charging link copied.') }}>Copy charging link</Button>
    </div>}
  </Modal>
}

function InfoFact({ label, value }: { label: string; value: string }) {
  return <div className="station-info-fact"><span>{label}</span><strong>{value}</strong></div>
}

function MetricChartCard({ title, subtitle, children }: { title: string; subtitle: string; children: React.ReactNode }) {
  return <Card className="station-chart-card" title={title} extra={<small>{subtitle}</small>}><div>{children}</div></Card>
}

const commandStatusConfig: Record<OcppCommandStatus, { color: string; label: string }> = {
  queued: { color: 'default', label: 'Queued' },
  sent: { color: 'processing', label: 'Sent' },
  accepted: { color: 'success', label: 'Accepted' },
  rejected: { color: 'error', label: 'Rejected' },
  failed: { color: 'error', label: 'Failed' },
  timed_out: { color: 'warning', label: 'Timed out' },
}

const commandActionLabels: Record<OcppCommand['action'], string> = {
  Reset: 'Soft restart',
  UnlockConnector: 'Unlock connector',
  ChangeAvailability: 'Change availability',
}

const commandColumns: ColumnsType<OcppCommand> = [
  {
    title: 'Command',
    dataIndex: 'action',
    render: (action: OcppCommand['action'], command) => <span className="ocpp-command-name"><strong>{commandActionLabels[action]}</strong><small>{command.connector ? `Connector ${command.connector.external_id}` : 'Whole station'}</small></span>,
  },
  {
    title: 'Requested by',
    dataIndex: 'requested_by',
    render: (requestedBy: OcppCommand['requested_by']) => requestedBy
      ? <span className="ocpp-command-user"><Avatar size={26} src={requestedBy.avatar_url}>{requestedBy.name.charAt(0)}</Avatar>{requestedBy.name}</span>
      : <span>System</span>,
  },
  {
    title: 'Queued',
    dataIndex: 'queued_at',
    render: (value: string) => <span className="ocpp-command-date"><strong>{dayjs(value).format('DD MMM YYYY')}</strong><small>{dayjs(value).format('HH:mm:ss')}</small></span>,
  },
  {
    title: 'Status',
    dataIndex: 'status',
    render: (status: OcppCommandStatus) => <Tag color={commandStatusConfig[status].color}>{commandStatusConfig[status].label}</Tag>,
  },
  {
    title: 'Station response',
    key: 'result',
    render: (_, command) => command.failure_message
      ?? (command.result?.ocppStatus ? String(command.result.ocppStatus) : ['queued', 'sent'].includes(command.status) ? 'Waiting for station' : 'No details'),
  },
]

function apiErrorMessage(error: unknown, fallback: string): string {
  if (!isAxiosError(error)) return fallback
  const data = error.response?.data as { message?: string; errors?: Record<string, string[]> } | undefined
  const validationMessage = data?.errors ? Object.values(data.errors).flat()[0] : undefined
  return validationMessage ?? data?.message ?? fallback
}

function ConnectorDrawer({ open, connector, managed, submitting, onClose, onSubmit }: {
  open: boolean
  connector: Connector | null
  managed: boolean
  submitting: boolean
  onClose: () => void
  onSubmit: (values: ConnectorPayload) => void
}) {
  const [form] = Form.useForm<ConnectorPayload>()

  return (
    <Drawer
      open={open}
      title={connector ? 'Edit connector' : 'Add connector'}
      size={460}
      onClose={onClose}
      afterOpenChange={(visible) => {
        if (!visible) return
        form.setFieldsValue(connector ? {
          external_id: connector.external_id,
          ocpp_connector_id: connector.ocpp_connector_id ?? undefined,
          type: connector.type,
          current_type: connector.current_type,
          max_power_kw: connector.max_power_kw,
          status: connector.status,
          error_code: connector.error_code,
        } : { type: 'CCS2', current_type: 'DC', ...(managed ? {} : { status: 'offline' }) })
      }}
      extra={<Button type="primary" loading={submitting} onClick={() => form.submit()}>Save</Button>}
    >
      <Form form={form} layout="vertical" onFinish={(values) => {
        if (!managed) {
          onSubmit(values)
          return
        }
        const payload = { ...values }
        delete payload.status
        delete payload.error_code
        onSubmit(payload)
      }} requiredMark="optional">
      <Form.Item label="Connector identifier" name="external_id" rules={[{ required: true }]}><Input placeholder="A1" /></Form.Item>
      <Form.Item label="OCPP connector ID" name="ocpp_connector_id" extra="Must match the connectorId sent by the physical station or simulator. Leave blank to assign the next available ID."><InputNumber min={1} max={65535} style={{ width: '100%' }} placeholder="1" /></Form.Item>
        <Form.Item label="Connector type" name="type" rules={[{ required: true }]}><Select options={['CCS2', 'Type 2', 'CHAdeMO'].map((value) => ({ value }))} /></Form.Item>
        <Form.Item label="Current type" name="current_type" rules={[{ required: true }]}><Select options={[{ value: 'AC' }, { value: 'DC' }]} /></Form.Item>
        <Form.Item label="Maximum power (kW)" name="max_power_kw" rules={[{ required: true }]}><InputNumber min={1} max={1000} style={{ width: '100%' }} /></Form.Item>
        {managed ? <Alert type="info" showIcon title="Connector status managed by OCPP" description="Status and error code are updated from StatusNotification events." /> : <>
          <Form.Item label="Status" name="status" rules={[{ required: true }]}><Select options={['available', 'charging', 'faulted', 'offline', 'maintenance', 'reserved', 'unavailable'].map((value) => ({ value, label: value.charAt(0).toUpperCase() + value.slice(1) }))} /></Form.Item>
          <Form.Item label="Error code" name="error_code"><Input placeholder="Optional OCPP error code" /></Form.Item>
        </>}
        <Button type="primary" htmlType="submit" loading={submitting} block>Save connector</Button>
      </Form>
    </Drawer>
  )
}
