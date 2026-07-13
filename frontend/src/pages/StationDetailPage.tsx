import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Alert, App, Button, Card, Drawer, Empty, Form, Input, InputNumber, Popconfirm, Select, Skeleton, Tabs } from 'antd'
import {
  Activity,
  AlertTriangle,
  ArrowLeft,
  LockOpen,
  MapPin,
  PencilLine,
  Plus,
  Power,
  RefreshCw,
  Settings,
  Trash2,
  Wrench,
  Zap,
} from 'lucide-react'
import { useNavigate, useParams } from 'react-router-dom'
import { Area, AreaChart, Bar, BarChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { createConnector, deleteConnector, getStation, updateConnector, updateStation } from '../features/stations/stationApi'
import { StationStatusTag } from '../features/stations/StationStatusTag'
import { useAuth } from '../features/auth/useAuth'
import type { Connector, ConnectorPayload, Station } from '../types/station'

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
  const { message } = App.useApp()
  const { user, primaryRole } = useAuth()
  const [connectorDrawerOpen, setConnectorDrawerOpen] = useState(false)
  const [selectedConnector, setSelectedConnector] = useState<Connector | null>(null)
  const canUpdate = user?.permissions.includes('stations.update') ?? false
  const canManageConnectors = canUpdate && (user?.permissions.includes('connectors.manage') ?? false)
  const isTechnician = primaryRole === 'technician'

  const stationQuery = useQuery({
    queryKey: ['station', numericStationId],
    queryFn: () => getStation(numericStationId),
    enabled: Number.isFinite(numericStationId),
  })

  const maintenanceMutation = useMutation({
    mutationFn: (station: Station) => updateStation(station.id, { status: station.status === 'maintenance' ? 'offline' : 'maintenance' }),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['station', numericStationId] })
      await queryClient.invalidateQueries({ queryKey: ['stations'] })
      void message.success('Station status updated.')
    },
    onError: () => void message.error('Station status could not be updated.'),
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
    return <Alert type="error" showIcon message="Unable to load this station" action={<Button onClick={() => navigate('/stations')}>Back to stations</Button>} />
  }

  const station = stationQuery.data
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
              <AreaChart data={utilizationData}><CartesianGrid strokeDasharray="3 3" stroke="#e5e7eb" /><XAxis dataKey="label" tickLine={false} axisLine={false} /><YAxis tickLine={false} axisLine={false} /><Tooltip /><Area type="monotone" dataKey="value" stroke="#7c3aed" fill="#ede9fe" strokeWidth={2} /></AreaChart>
            </ResponsiveContainer>
          </MetricChartCard>
          <MetricChartCard title="Energy delivered" subtitle="Recent daily energy output">
            <ResponsiveContainer width="100%" height="100%">
              <BarChart data={energyData}><CartesianGrid strokeDasharray="3 3" stroke="#e5e7eb" /><XAxis dataKey="label" tickLine={false} axisLine={false} /><YAxis tickLine={false} axisLine={false} /><Tooltip /><Bar dataKey="energy" fill="#22c55e" radius={[6, 6, 0, 0]} /></BarChart>
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
                {canManageConnectors && (
                  <div className="connector-actions">
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
                  </div>
                )}
              </div>
            ))}
          </div>
        </Card>
      ),
    },
    ...['Sessions', 'Alerts', 'Maintenance', 'Documents'].map((label) => ({
      key: label.toLowerCase(),
      label,
      children: <Card><Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description={`${label} will be connected in its dedicated vertical slice.`} /></Card>,
    })),
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
        {canUpdate ? (
          <div className="station-command-buttons">
            <Button icon={<RefreshCw size={15} />} onClick={() => void message.info('The OCPP restart command will be connected in the supervision slice.')}>Restart station</Button>
            <Button icon={<LockOpen size={15} />} onClick={() => void message.info('Choose a connector from the Connectors tab first.')}>Unlock connector</Button>
            <Button className="maintenance-button" icon={<Wrench size={15} />} loading={maintenanceMutation.isPending} onClick={() => maintenanceMutation.mutate(station)}>
              {station.status === 'maintenance' ? 'Leave maintenance mode' : 'Set maintenance mode'}
            </Button>
          </div>
        ) : isTechnician ? <Button icon={<Wrench size={15} />} onClick={() => navigate('/assigned-alerts')}>View assigned alerts</Button> : null}
      </section>

      <div className="station-info-grid">
        <InfoFact label="Location" value={station.location} />
        <InfoFact label="GPS" value={`${station.latitude.toFixed(4)}, ${station.longitude.toFixed(4)}`} />
        <InfoFact label="Model" value={station.model} />
        <InfoFact label="Manufacturer" value={station.manufacturer} />
        <InfoFact label="OCPP version" value={station.ocpp_version} />
        <InfoFact label="Power" value={`${station.max_power_kw} kW`} />
        <InfoFact label="Last heartbeat" value={station.last_heartbeat_relative} />
        <InfoFact label="Uptime" value={`${station.uptime_percent}%`} />
      </div>

      <Tabs className="station-detail-tabs" defaultActiveKey="overview" items={tabItems} />

      <ConnectorDrawer
        open={connectorDrawerOpen}
        connector={selectedConnector}
        submitting={connectorMutation.isPending}
        onClose={() => { setConnectorDrawerOpen(false); setSelectedConnector(null) }}
        onSubmit={(values) => connectorMutation.mutate(values)}
      />
    </div>
  )
}

function InfoFact({ label, value }: { label: string; value: string }) {
  return <div className="station-info-fact"><span>{label}</span><strong>{value}</strong></div>
}

function MetricChartCard({ title, subtitle, children }: { title: string; subtitle: string; children: React.ReactNode }) {
  return <Card className="station-chart-card" title={title} extra={<small>{subtitle}</small>}><div>{children}</div></Card>
}

function ConnectorDrawer({ open, connector, submitting, onClose, onSubmit }: {
  open: boolean
  connector: Connector | null
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
          type: connector.type,
          current_type: connector.current_type,
          max_power_kw: connector.max_power_kw,
          status: connector.status,
          error_code: connector.error_code,
        } : { type: 'CCS2', current_type: 'DC', status: 'offline' })
      }}
      extra={<Button type="primary" loading={submitting} onClick={() => form.submit()}>Save</Button>}
    >
      <Form form={form} layout="vertical" onFinish={onSubmit} requiredMark="optional">
        <Form.Item label="Connector identifier" name="external_id" rules={[{ required: true }]}><Input placeholder="A1" /></Form.Item>
        <Form.Item label="Connector type" name="type" rules={[{ required: true }]}><Select options={['CCS2', 'Type 2', 'CHAdeMO'].map((value) => ({ value }))} /></Form.Item>
        <Form.Item label="Current type" name="current_type" rules={[{ required: true }]}><Select options={[{ value: 'AC' }, { value: 'DC' }]} /></Form.Item>
        <Form.Item label="Maximum power (kW)" name="max_power_kw" rules={[{ required: true }]}><InputNumber min={1} max={1000} style={{ width: '100%' }} /></Form.Item>
        <Form.Item label="Status" name="status" rules={[{ required: true }]}><Select options={['available', 'charging', 'faulted', 'offline', 'maintenance'].map((value) => ({ value, label: value.charAt(0).toUpperCase() + value.slice(1) }))} /></Form.Item>
        <Form.Item label="Error code" name="error_code"><Input placeholder="Optional OCPP error code" /></Form.Item>
        <Button type="primary" htmlType="submit" loading={submitting} block>Save connector</Button>
      </Form>
    </Drawer>
  )
}
