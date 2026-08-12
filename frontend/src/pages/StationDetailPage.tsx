import { useDeferredValue, useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Alert, App, Avatar, Button, Card, Drawer, Empty, Form, Input, InputNumber, Modal, Popconfirm, Segmented, Select, Skeleton, Table, Tag, Tabs, Tooltip } from 'antd'
import type { ColumnsType } from 'antd/es/table'
import { getApiErrorMessage } from '../api/apiErrors'
import dayjs from 'dayjs'
import { QRCodeSVG } from 'qrcode.react'
import { Area, AreaChart, Bar, CartesianGrid, ComposedChart, Line, ResponsiveContainer, Tooltip as ChartTooltip, XAxis, YAxis } from 'recharts'
import {
  Activity,
  AlertTriangle,
  ArrowLeft,
  BatteryCharging,
  Cable as CableIcon,
  Clock3,
  CreditCard,
  Eye,
  LockOpen,
  KeyRound,
  MapPin,
  PencilLine,
  Download,
  Printer,
  Plus,
  Power,
  QrCode,
  Radio,
  RefreshCw,
  Search,
  Settings,
  Trash2,
  Wrench,
  Zap,
} from 'lucide-react'
import { useNavigate, useParams } from 'react-router-dom'
import { IconSurface, type IconSurfaceTone } from '../components/IconSurface'
import { createConnector, deleteConnector, getStation, getStationCommands, getStationTelemetry, restartStation, rotateStationCredentials, setStationMaintenanceMode, unlockStationConnector, updateConnector, updateStation } from '../features/stations/stationApi'
import { StationCommissioningResultModal } from '../features/stations/StationCommissioningResultModal'
import { StationStatusTag } from '../features/stations/StationStatusTag'
import { availabilityReasonLabel } from '../features/stations/availabilityLabels'
import { useAuth } from '../features/auth/useAuth'
import { ChargingStatusTag } from '../features/charging/ChargingStatusTag'
import { ConnectorTypeIcon } from '../features/charging/ConnectorTypeIcon'
import { getChargingSessions } from '../features/charging/chargingApi'
import { DocumentManager } from '../features/documents/DocumentManager'
import { getAlerts, getMaintenances } from '../features/operations/operationsApi'
import { WorkflowTag } from '../features/operations/WorkflowTag'
import type { ChargingSession, ChargingSessionsResponse, ChargingSessionStatus } from '../types/charging'
import type { AlertItem, AlertSeverity, AlertsResponse, AlertStatus, InterventionItem, InterventionStatus } from '../types/operations'
import type { Connector, ConnectorPayload, MaintenanceModeResponse, OcppCommand, OcppCommandStatus, Station, StationCommissioningResult, StationTelemetry } from '../types/station'

export function StationDetailPage() {
  const { stationId } = useParams()
  const numericStationId = Number(stationId)
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { message, modal } = App.useApp()
  const { user, primaryRole } = useAuth()
  const [connectorDrawerOpen, setConnectorDrawerOpen] = useState(false)
  const [activeTab, setActiveTab] = useState('overview')
  const [overviewView, setOverviewView] = useState<'snapshot' | 'telemetry'>('snapshot')
  const [selectedConnector, setSelectedConnector] = useState<Connector | null>(null)
  const [qrConnector, setQrConnector] = useState<Connector | null>(null)
  const [rotatedCredentials, setRotatedCredentials] = useState<StationCommissioningResult | null>(null)
  const [telemetryDays, setTelemetryDays] = useState<1 | 7 | 30>(7)
  const [sessionSearch, setSessionSearch] = useState('')
  const deferredSessionSearch = useDeferredValue(sessionSearch)
  const [sessionStatus, setSessionStatus] = useState<'all' | ChargingSessionStatus>('all')
  const [sessionPage, setSessionPage] = useState(1)
  const [alertSearch, setAlertSearch] = useState('')
  const deferredAlertSearch = useDeferredValue(alertSearch)
  const [alertSeverity, setAlertSeverity] = useState<'all' | AlertSeverity>('all')
  const [alertStatus, setAlertStatus] = useState<'all' | AlertStatus>('all')
  const [alertPage, setAlertPage] = useState(1)
  const canUpdate = user?.permissions.includes('stations.update') ?? false
  const canManageConnectors = canUpdate && (user?.permissions.includes('connectors.manage') ?? false)
  const canViewCommands = user?.permissions.includes('ocpp_commands.view') ?? false
  const canExecuteCommands = user?.permissions.includes('ocpp_commands.execute') ?? false
  const canViewSimulation = user?.permissions.includes('ocpp_simulation.view') ?? false
  const canViewMaintenance = user?.permissions.includes('maintenances.view') ?? false
  const canViewSessions = user?.permissions.includes('sessions.view') ?? false
  const canViewAlerts = user?.permissions.includes('alerts.view') ?? false
  const isTechnician = primaryRole === 'technician'
  const isClient = primaryRole === 'client'

  const stationQuery = useQuery({
    queryKey: ['station', numericStationId],
    queryFn: () => getStation(numericStationId),
    enabled: Number.isFinite(numericStationId),
  })

  const telemetryQuery = useQuery({
    queryKey: ['station-telemetry', numericStationId, telemetryDays],
    queryFn: () => getStationTelemetry(numericStationId, telemetryDays),
    enabled: Number.isFinite(numericStationId)
      && stationQuery.isSuccess
      && activeTab === 'overview'
      && overviewView === 'telemetry',
    refetchInterval: stationQuery.data?.ocpp_is_connected ? 10_000 : false,
  })

  const commandsQuery = useQuery({
    queryKey: ['station-commands', numericStationId],
    queryFn: () => getStationCommands(numericStationId),
    enabled: Number.isFinite(numericStationId) && canViewCommands && activeTab === 'command-history',
    refetchInterval: (query) => query.state.data?.data.some((command) => ['queued', 'sent'].includes(command.status)) ? 2_000 : false,
  })

  const maintenanceQuery = useQuery({
    queryKey: ['maintenances', { station_id: numericStationId }],
    queryFn: () => getMaintenances({ station_id: numericStationId }),
    enabled: Number.isFinite(numericStationId) && canViewMaintenance && activeTab === 'maintenance',
  })

  const sessionsQuery = useQuery({
    queryKey: ['charging-sessions', {
      station_id: numericStationId,
      search: deferredSessionSearch.trim() || undefined,
      status: sessionStatus === 'all' ? undefined : sessionStatus,
      page: sessionPage,
      per_page: 7,
    }],
    queryFn: () => getChargingSessions({
      station_id: numericStationId,
      search: deferredSessionSearch.trim() || undefined,
      status: sessionStatus === 'all' ? undefined : sessionStatus,
      page: sessionPage,
      per_page: 7,
    }),
    enabled: Number.isFinite(numericStationId) && canViewSessions && activeTab === 'sessions',
    refetchInterval: (query) => (query.state.data?.summary.active ?? 0) > 0 ? 2_500 : false,
  })

  const alertsQuery = useQuery({
    queryKey: ['alerts', {
      station_id: numericStationId,
      search: deferredAlertSearch.trim() || undefined,
      severity: alertSeverity === 'all' ? undefined : alertSeverity,
      status: alertStatus === 'all' ? undefined : alertStatus,
      page: alertPage,
      per_page: 7,
    }],
    queryFn: () => getAlerts({
      station_id: numericStationId,
      search: deferredAlertSearch.trim() || undefined,
      severity: alertSeverity === 'all' ? undefined : alertSeverity,
      status: alertStatus === 'all' ? undefined : alertStatus,
      page: alertPage,
      per_page: 7,
    }),
    enabled: Number.isFinite(numericStationId) && canViewAlerts && activeTab === 'alerts',
    refetchInterval: 15_000,
  })

  const refreshStationData = async () => {
    await Promise.all([
      queryClient.invalidateQueries({ queryKey: ['station', numericStationId] }),
      queryClient.invalidateQueries({ queryKey: ['station-telemetry', numericStationId] }),
      queryClient.invalidateQueries({ queryKey: ['stations'] }),
      queryClient.invalidateQueries({ queryKey: ['station-commands', numericStationId] }),
      queryClient.invalidateQueries({ queryKey: ['station-simulator', numericStationId] }),
      queryClient.invalidateQueries({ queryKey: ['maintenances'] }),
      queryClient.invalidateQueries({ queryKey: ['charging-sessions'] }),
      queryClient.invalidateQueries({ queryKey: ['alerts'] }),
    ])
  }

  const resetMutation = useMutation({
    mutationFn: () => restartStation(numericStationId),
    onSuccess: async () => {
      await refreshStationData()
      void message.success('Soft restart command queued.')
    },
    onError: (error) => void message.error(getApiErrorMessage(error, 'The restart command could not be queued.')),
  })

  const unlockMutation = useMutation({
    mutationFn: (connectorId: number) => unlockStationConnector(numericStationId, connectorId),
    onSuccess: async (_, connectorId) => {
      await refreshStationData()
      const connector = stationQuery.data?.connectors.find((item) => item.id === connectorId)
      void message.success(`Unlock command queued for connector ${connector?.external_id ?? connectorId}.`)
    },
    onError: (error) => void message.error(getApiErrorMessage(error, 'The connector could not be unlocked.')),
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
    onError: (error) => void message.error(getApiErrorMessage(error, 'Station maintenance mode could not be updated.')),
  })

  const rotateCredentialsMutation = useMutation({
    mutationFn: () => rotateStationCredentials(numericStationId),
    onSuccess: async (result) => {
      await refreshStationData()
      setRotatedCredentials(result)
    },
    onError: (error) => void message.error(getApiErrorMessage(error, 'The OCPP credentials could not be rotated.')),
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
  const confirmCredentialRotation = () => {
    modal.confirm({
      title: 'Rotate the station credentials?',
      content: 'The current Basic Auth password will stop working. Configure the new one on the physical station before its next connection.',
      okText: 'Rotate credentials',
      cancelText: 'Cancel',
      okButtonProps: { danger: true },
      onOk: () => rotateCredentialsMutation.mutateAsync(),
    })
  }
  const tabItems = [
    {
      key: 'overview',
      label: 'Overview',
      children: (
        <div className="station-overview-workspace">
          <div className="station-overview-viewbar">
            <Segmented
              value={overviewView}
              options={[
                { label: 'Operational snapshot', value: 'snapshot' },
                { label: 'Telemetry', value: 'telemetry' },
              ]}
              onChange={(value) => setOverviewView(value as 'snapshot' | 'telemetry')}
            />
          </div>
          {overviewView === 'snapshot' ? (
            <section className="station-verified-snapshot">
              <header>
                <IconSurface tone={station.ocpp_is_connected ? 'green' : 'red'} size="large"><Activity size={19} /></IconSurface>
                <div>
                  <h2>Verified station snapshot</h2>
                  <p>Current projections returned by the backend. No synthetic historical series are displayed.</p>
                </div>
                <Tag color={station.ocpp_is_connected ? 'green' : 'default'}>{station.ocpp_is_connected ? 'Connected' : 'Offline'}</Tag>
              </header>
              <div className="station-snapshot-grid">
                <SnapshotGroup title="Today's activity" description="Completed sessions and settled payments">
                  <SnapshotMetric icon={<Zap size={16} />} label="Energy delivered" value={`${station.energy_today_kwh} kWh`} />
                  <SnapshotMetric icon={<Activity size={16} />} label="Charging sessions" value={station.sessions_today.toString()} />
                  <SnapshotMetric icon={<Power size={16} />} label="Settled revenue" value={`${station.revenue_today} TND`} />
                </SnapshotGroup>
                <SnapshotGroup title="OCPP health" description="Latest gateway projection">
                  <SnapshotMetric icon={<Activity size={16} />} label="Connection" value={station.ocpp_is_connected ? 'Connected' : 'Offline'} />
                  <SnapshotMetric icon={<Zap size={16} />} label="Station status" value={humanizeValue(station.status)} />
                  <SnapshotMetric icon={<RefreshCw size={16} />} label="Last station signal" value={station.last_heartbeat_relative} />
                </SnapshotGroup>
                <SnapshotGroup title="Operational attention" description="Current station-side workload">
                  <SnapshotMetric icon={<CableIcon size={16} />} label="Available connectors" value={`${station.available_connectors_count ?? 0} / ${station.connectors_count ?? station.connectors.length}`} />
                  <SnapshotMetric icon={<AlertTriangle size={16} />} label="Open alerts" value={station.open_alerts_count.toString()} />
                  <SnapshotMetric icon={<Settings size={16} />} label="Commissioning" value={humanizeValue(station.commissioning_status)} />
                </SnapshotGroup>
              </div>
            </section>
          ) : (
            <StationTelemetryPanel
              standalone
              telemetry={telemetryQuery.data}
              days={telemetryDays}
              loading={telemetryQuery.isLoading}
              fetching={telemetryQuery.isFetching}
              error={telemetryQuery.isError}
              onDaysChange={setTelemetryDays}
              onRetry={() => void telemetryQuery.refetch()}
            />
          )}
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
                  <IconSurface className="connector-icon"><Zap size={18} /></IconSurface>
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
    ...(canViewSessions ? [{
      key: 'sessions',
      label: 'Sessions',
      children: (
        <StationSessionsPanel
          data={sessionsQuery.data}
          loading={sessionsQuery.isLoading}
          error={sessionsQuery.isError}
          clientMode={isClient}
          search={sessionSearch}
          status={sessionStatus}
          page={sessionPage}
          onSearchChange={(value) => { setSessionSearch(value); setSessionPage(1) }}
          onStatusChange={(value) => { setSessionStatus(value); setSessionPage(1) }}
          onPageChange={setSessionPage}
          onRetry={() => void sessionsQuery.refetch()}
          onOpenSession={(session) => navigate(`${isClient ? '/my-sessions' : '/sessions'}?search=${encodeURIComponent(session.reference)}`)}
          onOpenAll={() => navigate(`${isClient ? '/my-sessions' : '/sessions'}?search=${encodeURIComponent(station.name)}`)}
        />
      ),
    }] : []),
    ...(canViewAlerts ? [{
      key: 'alerts',
      label: 'Alerts',
      children: (
        <StationAlertsPanel
          data={alertsQuery.data}
          loading={alertsQuery.isLoading}
          error={alertsQuery.isError}
          search={alertSearch}
          severity={alertSeverity}
          status={alertStatus}
          page={alertPage}
          onSearchChange={(value) => { setAlertSearch(value); setAlertPage(1) }}
          onSeverityChange={(value) => { setAlertSeverity(value); setAlertPage(1) }}
          onStatusChange={(value) => { setAlertStatus(value); setAlertPage(1) }}
          onPageChange={setAlertPage}
          onRetry={() => void alertsQuery.refetch()}
          onOpenAlert={(alert) => navigate(`${isTechnician ? '/assigned-alerts' : '/alerts'}?alert=${alert.id}`)}
          onOpenAll={() => navigate(isTechnician ? '/assigned-alerts' : '/alerts')}
        />
      ),
    }] : []),
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
        {(canExecuteCommands || isTechnician || (canViewSimulation && station.ocpp_commissioning_target === 'simulator')) && (
          <div className="station-command-buttons">
            {canViewSimulation && station.ocpp_commissioning_target === 'simulator' && <Button type="primary" icon={<Radio size={15} />} onClick={() => navigate(`/simulation-lab?station=${station.id}`)}>Open Simulation Lab</Button>}
            {canExecuteCommands && <>
            {station.ocpp_managed && (
              <Tooltip title={!station.ocpp_is_connected ? 'The station must be online to receive a restart command.' : undefined}>
                <span>
                  <Button disabled={!station.ocpp_is_connected} icon={<RefreshCw size={15} />} loading={resetMutation.isPending} onClick={confirmRestart}>Restart station</Button>
                </span>
              </Tooltip>
            )}
            {canManageConnectors && station.ocpp_commissioning_target === 'external' && (
              <Button icon={<KeyRound size={15} />} loading={rotateCredentialsMutation.isPending} onClick={confirmCredentialRotation}>Rotate credentials</Button>
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
            </>}
            {!canExecuteCommands && isTechnician && <Button icon={<Wrench size={15} />} onClick={() => navigate('/assigned-alerts')}>View assigned alerts</Button>}
          </div>
        )}
      </section>

      <div className="station-info-grid">
        <InfoFact label="Location" value={station.location} />
        <InfoFact label="GPS" value={`${station.latitude.toFixed(4)}, ${station.longitude.toFixed(4)}`} />
        <InfoFact label="Model" value={station.model} />
        <InfoFact label="Manufacturer" value={station.manufacturer} />
        <InfoFact label="OCPP version" value={station.ocpp_version} />
        <InfoFact label="OCPP identity" value={station.ocpp_identity ?? 'Not configured'} />
        <InfoFact label="Commissioning target" value={commissioningTargetLabel(station.ocpp_commissioning_target)} />
        <InfoFact label="Commissioning status" value={commissioningStatusLabel(station.commissioning_status)} />
        <InfoFact label="OCPP connection" value={station.ocpp_is_connected ? 'Connected' : 'Offline'} />
        <InfoFact label="Power" value={`${station.max_power_kw} kW`} />
        <InfoFact label="Last heartbeat" value={station.last_heartbeat_relative} />
        <InfoFact label="Availability rule" value={availabilityReasonLabel(station.availability_reason)} />
        <InfoFact label="Uptime" value={`${station.uptime_percent}%`} />
      </div>

      <Tabs className="station-detail-tabs" activeKey={activeTab} onChange={setActiveTab} items={tabItems} />

      <ConnectorDrawer
        open={connectorDrawerOpen}
        connector={selectedConnector}
        managed={station.ocpp_managed}
        submitting={connectorMutation.isPending}
        onClose={() => { setConnectorDrawerOpen(false); setSelectedConnector(null) }}
        onSubmit={(values) => connectorMutation.mutate(values)}
      />
      <ConnectorQrModal station={station} connector={qrConnector} onClose={() => setQrConnector(null)} />
      <StationCommissioningResultModal mode="rotated" result={rotatedCredentials} onClose={() => setRotatedCredentials(null)} />
    </div>
  )
}

function StationSessionsPanel({
  data,
  loading,
  error,
  clientMode,
  search,
  status,
  page,
  onSearchChange,
  onStatusChange,
  onPageChange,
  onRetry,
  onOpenSession,
  onOpenAll,
}: {
  data?: ChargingSessionsResponse
  loading: boolean
  error: boolean
  clientMode: boolean
  search: string
  status: 'all' | ChargingSessionStatus
  page: number
  onSearchChange: (value: string) => void
  onStatusChange: (value: 'all' | ChargingSessionStatus) => void
  onPageChange: (page: number) => void
  onRetry: () => void
  onOpenSession: (session: ChargingSession) => void
  onOpenAll: () => void
}) {
  const columns: ColumnsType<ChargingSession> = [
    {
      title: 'Session',
      dataIndex: 'reference',
      width: 190,
      render: (value: string, item) => (
        <span className="station-related-primary">
          <strong>{value}</strong>
          <small>{dayjs(item.started_at).format('DD MMM YYYY, HH:mm')}</small>
        </span>
      ),
    },
    ...(!clientMode ? [{
      title: 'Driver',
      key: 'client',
      width: 150,
      render: (_: unknown, item: ChargingSession) => item.client.name,
    }] : []),
    {
      title: 'Connector',
      key: 'connector',
      width: 130,
      render: (_: unknown, item) => (
        <span className="station-related-primary">
          <strong>{item.connector.external_id}</strong>
          <small>{item.connector.type ?? 'Type unavailable'}</small>
        </span>
      ),
    },
    {
      title: 'Usage',
      key: 'usage',
      width: 150,
      render: (_: unknown, item) => (
        <span className="station-related-primary">
          <strong>{item.energy_kwh.toFixed(3)} kWh</strong>
          <small>{stationSessionIsActive(item) ? `${Math.max(1, dayjs().diff(dayjs(item.started_at), 'minute'))} min live` : `${item.duration_minutes} min`}</small>
        </span>
      ),
    },
    { title: 'Status', dataIndex: 'status', width: 125, render: (value: ChargingSessionStatus) => <ChargingStatusTag value={value} /> },
    {
      title: 'Payment',
      key: 'payment',
      width: 150,
      render: (_: unknown, item) => (
        <span className="station-related-primary">
          <ChargingStatusTag value={item.payment_status} />
          <small>{item.total_amount} {item.currency}</small>
        </span>
      ),
    },
    {
      title: '',
      key: 'actions',
      width: 70,
      align: 'right',
      render: (_: unknown, item) => <Tooltip title="Open in session workspace"><Button type="text" icon={<Eye size={16} />} onClick={() => onOpenSession(item)} /></Tooltip>,
    },
  ]

  return (
    <section className="station-related-panel">
      <header className="station-related-header">
        <div>
          <span className="station-related-eyebrow"><BatteryCharging size={15} />Charging activity</span>
          <h2>Station sessions</h2>
          <p>Measured charging sessions and payment outcomes for this station only.</p>
        </div>
        <Button onClick={onOpenAll}>Open session workspace</Button>
      </header>
      <div className="station-related-summary">
        <StationRelatedMetric icon={<BatteryCharging size={17} />} label="All sessions" value={data?.summary.total ?? 0} />
        <StationRelatedMetric icon={<Activity size={17} />} label="Active now" value={data?.summary.active ?? 0} tone="green" />
        <StationRelatedMetric icon={<Zap size={17} />} label="Energy delivered" value={`${data?.summary.energy_kwh ?? 0} kWh`} tone="blue" />
        <StationRelatedMetric icon={<CreditCard size={17} />} label={clientMode ? 'Paid value' : 'Settled revenue'} value={`${((data?.summary.revenue_millimes ?? 0) / 1000).toFixed(3)} TND`} tone="amber" />
      </div>
      <div className="station-related-toolbar">
        <Input value={search} onChange={(event) => onSearchChange(event.target.value)} prefix={<Search size={15} />} placeholder="Search reference, driver or connector" allowClear />
        <Select
          value={status}
          onChange={onStatusChange}
          options={(['all', 'pending', 'charging', 'stopping', 'completed', 'interrupted', 'failed', 'cancelled'] as const).map((value) => ({
            value,
            label: value === 'all' ? 'All statuses' : humanizeValue(value),
          }))}
        />
      </div>
      {error && <Alert type="error" showIcon title="Unable to load station sessions" action={<Button size="small" onClick={onRetry}>Retry</Button>} />}
      <Table<ChargingSession>
        className="station-related-table"
        rowKey="id"
        loading={loading}
        columns={columns}
        dataSource={data?.data ?? []}
        pagination={{
          current: data?.meta.current_page ?? page,
          pageSize: data?.meta.per_page ?? 7,
          total: data?.meta.total ?? 0,
          hideOnSinglePage: true,
          showSizeChanger: false,
          onChange: onPageChange,
        }}
        scroll={{ x: 900 }}
        locale={{ emptyText: <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="No session has been recorded for this station" /> }}
      />
    </section>
  )
}

function StationAlertsPanel({
  data,
  loading,
  error,
  search,
  severity,
  status,
  page,
  onSearchChange,
  onSeverityChange,
  onStatusChange,
  onPageChange,
  onRetry,
  onOpenAlert,
  onOpenAll,
}: {
  data?: AlertsResponse
  loading: boolean
  error: boolean
  search: string
  severity: 'all' | AlertSeverity
  status: 'all' | AlertStatus
  page: number
  onSearchChange: (value: string) => void
  onSeverityChange: (value: 'all' | AlertSeverity) => void
  onStatusChange: (value: 'all' | AlertStatus) => void
  onPageChange: (page: number) => void
  onRetry: () => void
  onOpenAlert: (alert: AlertItem) => void
  onOpenAll: () => void
}) {
  const columns: ColumnsType<AlertItem> = [
    {
      title: 'Alert',
      key: 'alert',
      width: 260,
      render: (_: unknown, item) => (
        <span className="station-related-primary">
          <strong>{item.title}</strong>
          <small>{item.reference} · {item.problem_type}</small>
        </span>
      ),
    },
    {
      title: 'Connector',
      key: 'connector',
      width: 125,
      render: (_: unknown, item) => item.connector
        ? <span className="station-related-primary"><strong>{item.connector.external_id}</strong><small>{item.connector.type}</small></span>
        : 'Station-wide',
    },
    { title: 'Severity', dataIndex: 'severity', width: 120, render: (value: AlertSeverity) => <WorkflowTag value={value} /> },
    { title: 'Status', dataIndex: 'status', width: 130, render: (value: AlertStatus) => <WorkflowTag value={value} /> },
    {
      title: 'Assigned to',
      key: 'technician',
      width: 170,
      render: (_: unknown, item) => item.assigned_technician?.name ?? 'Unassigned',
    },
    {
      title: 'Detected',
      key: 'detected',
      width: 145,
      render: (_: unknown, item) => <span className="station-related-primary"><strong>{item.detected_relative}</strong><small>{dayjs(item.detected_at).format('DD MMM, HH:mm')}</small></span>,
    },
    {
      title: '',
      key: 'actions',
      width: 70,
      align: 'right',
      render: (_: unknown, item) => <Tooltip title="Open alert workflow"><Button type="text" icon={<Eye size={16} />} onClick={() => onOpenAlert(item)} /></Tooltip>,
    },
  ]

  return (
    <section className="station-related-panel">
      <header className="station-related-header">
        <div>
          <span className="station-related-eyebrow station-related-eyebrow--alert"><AlertTriangle size={15} />Operational attention</span>
          <h2>Station alerts</h2>
          <p>Availability incidents, OCPP faults and technician assignment for this station only.</p>
        </div>
        <Button onClick={onOpenAll}>Open alert workspace</Button>
      </header>
      <div className="station-related-summary">
        <StationRelatedMetric icon={<AlertTriangle size={17} />} label="All alerts" value={data?.summary.total ?? 0} />
        <StationRelatedMetric icon={<AlertTriangle size={17} />} label="Critical open" value={data?.summary.critical ?? 0} tone="red" />
        <StationRelatedMetric icon={<Clock3 size={17} />} label="New" value={data?.summary.new ?? 0} tone="amber" />
        <StationRelatedMetric icon={<Wrench size={17} />} label="In progress" value={data?.summary.in_progress ?? 0} tone="blue" />
      </div>
      <div className="station-related-toolbar station-related-toolbar--alerts">
        <Input value={search} onChange={(event) => onSearchChange(event.target.value)} prefix={<Search size={15} />} placeholder="Search alert, reference or problem" allowClear />
        <Select
          value={severity}
          onChange={onSeverityChange}
          options={(['all', 'critical', 'warning', 'info'] as const).map((value) => ({ value, label: value === 'all' ? 'All severities' : humanizeValue(value) }))}
        />
        <Select
          value={status}
          onChange={onStatusChange}
          options={(['all', 'new', 'in-progress', 'resolved'] as const).map((value) => ({ value, label: value === 'all' ? 'All statuses' : humanizeValue(value) }))}
        />
      </div>
      {error && <Alert type="error" showIcon title="Unable to load station alerts" action={<Button size="small" onClick={onRetry}>Retry</Button>} />}
      <Table<AlertItem>
        className="station-related-table"
        rowKey="id"
        loading={loading}
        columns={columns}
        dataSource={data?.data ?? []}
        pagination={{
          current: data?.meta.current_page ?? page,
          pageSize: data?.meta.per_page ?? 7,
          total: data?.meta.total ?? 0,
          hideOnSinglePage: true,
          showSizeChanger: false,
          onChange: onPageChange,
        }}
        scroll={{ x: 980 }}
        locale={{ emptyText: <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="No alert matches this station view" /> }}
      />
    </section>
  )
}

function StationRelatedMetric({ icon, label, value, tone = 'purple' }: {
  icon: React.ReactNode
  label: string
  value: string | number
  tone?: 'purple' | 'green' | 'blue' | 'amber' | 'red'
}) {
  const iconTone: IconSurfaceTone = {
    purple: 'green',
    green: 'green',
    blue: 'green',
    amber: 'yellow',
    red: 'red',
  }[tone] as IconSurfaceTone

  return (
    <div className={`station-related-metric station-related-metric--${tone}`}>
      <IconSurface tone={iconTone}>{icon}</IconSurface>
      <div><small>{label}</small><strong>{value}</strong></div>
    </div>
  )
}

function stationSessionIsActive(session: ChargingSession): boolean {
  return ['pending', 'charging', 'stopping'].includes(session.status)
}

function commissioningTargetLabel(target: Station['ocpp_commissioning_target']): string {
  return {
    external: 'Physical / external',
    simulator: 'Local SAP simulator',
    inventory: 'Inventory only',
  }[target]
}

function commissioningStatusLabel(status: Station['commissioning_status']): string {
  return {
    not_provisioned: 'Not provisioned',
    provisioning: 'Provisioning simulator',
    provisioning_failed: 'Provisioning failed',
    awaiting_connection: 'Awaiting connection',
    connected: 'Connected',
    offline: 'Provisioned, offline',
    rejected: 'Registration rejected',
  }[status]
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

function SnapshotGroup({ title, description, children }: { title: string; description: string; children: React.ReactNode }) {
  return <article className="station-snapshot-group"><header><h3>{title}</h3><p>{description}</p></header><div>{children}</div></article>
}

function SnapshotMetric({ icon, label, value }: { icon: React.ReactNode; label: string; value: string }) {
  return <div className="station-snapshot-metric"><span>{icon}{label}</span><strong>{value}</strong></div>
}

function StationTelemetryPanel({
  telemetry,
  days,
  loading,
  fetching,
  error,
  onDaysChange,
  onRetry,
  standalone = false,
}: {
  telemetry: StationTelemetry | undefined
  days: 1 | 7 | 30
  loading: boolean
  fetching: boolean
  error: boolean
  onDaysChange: (days: 1 | 7 | 30) => void
  onRetry: () => void
  standalone?: boolean
}) {
  const daily = telemetry?.daily ?? []
  const power = telemetry?.power ?? []
  const hasDailyActivity = daily.some((item) => item.sessions > 0 || item.energy_kwh > 0)
  const dailyChartData = daily.map((item) => ({
    ...item,
    label: days === 1 ? 'Today' : dayjs(item.date).format(days === 7 ? 'ddd' : 'DD MMM'),
  }))
  const powerChartData = power.map((item) => ({
    ...item,
    label: dayjs(item.sampled_at).format(days === 1 ? 'HH:mm:ss' : 'DD MMM HH:mm'),
  }))

  return <section className={`station-telemetry${standalone ? ' is-standalone' : ''}`}>
    <header className="station-telemetry-header">
      <div>
        <span className={`station-live-indicator${fetching ? ' is-refreshing' : ''}`}><i />{fetching ? 'Refreshing' : 'Verified telemetry'}</span>
        <h2>Energy and OCPP measurements</h2>
        <p>Session totals and station-reported power, without generated history.</p>
      </div>
      <Segmented
        value={days}
        options={[
          { label: 'Today', value: 1 },
          { label: '7 days', value: 7 },
          { label: '30 days', value: 30 },
        ]}
        onChange={(value) => onDaysChange(value as 1 | 7 | 30)}
      />
    </header>

    {error ? (
      <Alert
        className="station-telemetry-error"
        type="error"
        showIcon
        title="Telemetry could not be loaded"
        action={<Button size="small" onClick={onRetry}>Retry</Button>}
      />
    ) : loading ? (
      <div className="station-telemetry-loading"><Skeleton active paragraph={{ rows: 5 }} /></div>
    ) : telemetry ? (
      <>
        <div className="station-telemetry-summary">
          <TelemetrySummary label="Energy delivered" value={`${telemetry.summary.energy_kwh.toFixed(3)} kWh`} detail={`${telemetry.summary.sessions} session${telemetry.summary.sessions === 1 ? '' : 's'}`} />
          <TelemetrySummary label="Latest reported power" value={telemetry.summary.latest_power_kw === null ? 'No signal' : `${telemetry.summary.latest_power_kw.toFixed(1)} kW`} detail={telemetry.summary.last_sample_at ? dayjs(telemetry.summary.last_sample_at).format('DD MMM, HH:mm:ss') : 'Waiting for MeterValues'} />
          {telemetry.sources.financials_visible && <TelemetrySummary label="Settled revenue" value={`${((telemetry.summary.revenue_millimes ?? 0) / 1000).toFixed(3)} TND`} detail="Paid sessions in period" />}
          <TelemetrySummary label="Data provenance" value="Verified" detail="Sessions + OCPP 1.6J" />
        </div>
        <div className="station-telemetry-charts">
          <TelemetryChart title="Daily charging activity" description="Energy delivered and session starts">
            {hasDailyActivity ? (
              <ResponsiveContainer width="100%" height="100%">
                <ComposedChart data={dailyChartData} margin={{ top: 8, right: 8, bottom: 0, left: -14 }}>
                  <CartesianGrid stroke="#e7eeea" strokeDasharray="3 3" vertical={false} />
                  <XAxis dataKey="label" axisLine={false} tickLine={false} />
                  <YAxis yAxisId="energy" axisLine={false} tickLine={false} width={44} unit=" kWh" />
                  <YAxis yAxisId="sessions" orientation="right" axisLine={false} tickLine={false} allowDecimals={false} width={24} />
                  <ChartTooltip contentStyle={chartTooltipStyle} formatter={(value, name) => name === 'Energy' ? [`${Number(value).toFixed(3)} kWh`, name] : [value, name]} />
                  <Bar yAxisId="energy" dataKey="energy_kwh" name="Energy" fill="#19aa70" radius={[5, 5, 0, 0]} maxBarSize={34} />
                  <Line yAxisId="sessions" type="monotone" dataKey="sessions" name="Sessions" stroke="#7148f5" strokeWidth={2} dot={{ r: 3, fill: '#7148f5' }} />
                </ComposedChart>
              </ResponsiveContainer>
            ) : <TelemetryEmpty title="No charging activity in this period" detail="Completed or active sessions will appear here." />}
          </TelemetryChart>

          <TelemetryChart title="Station-reported power" description="Power.Active.Import from MeterValues">
            {powerChartData.length > 0 ? (
              <ResponsiveContainer width="100%" height="100%">
                <AreaChart data={powerChartData} margin={{ top: 8, right: 8, bottom: 0, left: -14 }}>
                  <defs>
                    <linearGradient id="stationPowerFill" x1="0" y1="0" x2="0" y2="1">
                      <stop offset="0%" stopColor="#7148f5" stopOpacity={0.28} />
                      <stop offset="100%" stopColor="#7148f5" stopOpacity={0.02} />
                    </linearGradient>
                  </defs>
                  <CartesianGrid stroke="#e7eeea" strokeDasharray="3 3" vertical={false} />
                  <XAxis dataKey="label" axisLine={false} tickLine={false} minTickGap={34} />
                  <YAxis axisLine={false} tickLine={false} width={42} unit=" kW" />
                  <ChartTooltip contentStyle={chartTooltipStyle} formatter={(value) => [`${Number(value).toFixed(3)} kW`, 'Power']} />
                  <Area type="monotone" dataKey="power_kw" stroke="#7148f5" strokeWidth={2.4} fill="url(#stationPowerFill)" activeDot={{ r: 4 }} />
                </AreaChart>
              </ResponsiveContainer>
            ) : <TelemetryEmpty title="No OCPP power samples yet" detail="Start a simulated transaction to receive MeterValues." />}
          </TelemetryChart>
        </div>
      </>
    ) : null}
  </section>
}

function TelemetrySummary({ label, value, detail }: { label: string; value: string; detail: string }) {
  return <div><span>{label}</span><strong>{value}</strong><small>{detail}</small></div>
}

function TelemetryChart({ title, description, children }: { title: string; description: string; children: React.ReactNode }) {
  return <article className="station-telemetry-chart">
    <header><h3>{title}</h3><p>{description}</p></header>
    <div>{children}</div>
  </article>
}

function TelemetryEmpty({ title, detail }: { title: string; detail: string }) {
  return <div className="station-telemetry-empty"><Activity size={23} /><strong>{title}</strong><span>{detail}</span></div>
}

const chartTooltipStyle = {
  border: '1px solid #dfe8e3',
  borderRadius: 7,
  boxShadow: '0 8px 24px rgba(19, 46, 33, 0.08)',
  fontSize: 11,
}

function humanizeValue(value: string): string {
  return value.replaceAll('_', ' ').replace(/\b\w/g, (character) => character.toUpperCase())
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
        {!managed && <>
          <Form.Item label="Status" name="status" rules={[{ required: true }]}><Select options={['available', 'charging', 'faulted', 'offline', 'maintenance', 'reserved', 'unavailable'].map((value) => ({ value, label: value.charAt(0).toUpperCase() + value.slice(1) }))} /></Form.Item>
          <Form.Item label="Error code" name="error_code"><Input placeholder="Optional OCPP error code" /></Form.Item>
        </>}
        <Button type="primary" htmlType="submit" loading={submitting} block>Save connector</Button>
      </Form>
    </Drawer>
  )
}
