import { useQuery } from '@tanstack/react-query'
import { App, Button, Card, Col, Progress, Row, Statistic, Table, Tag, Typography } from 'antd'
import type { ColumnsType } from 'antd/es/table'
import type { ReactNode } from 'react'
import {
  Activity,
  AlertTriangle,
  BatteryCharging,
  Building2,
  MapPinned,
  Users,
} from 'lucide-react'
import { useNavigate } from 'react-router-dom'
import { useAuth } from '../features/auth/useAuth'
import { getRoleConfig } from '../features/auth/roleConfig'
import { copyCoordinates } from '../features/maps/mapUtils'
import { StationMap, StationPopupDetailButton } from '../features/maps/StationMap'
import { getStationMap } from '../features/stations/stationApi'
import type { UserRole } from '../types/auth'

interface DashboardStat {
  title: string
  value: string | number
  icon: ReactNode
}

const statsByRole: Record<UserRole, DashboardStat[]> = {
  super_admin: [
    { title: 'Organizations', value: 18, icon: <Building2 size={22} /> },
    { title: 'Platform users', value: 126, icon: <Users size={22} /> },
    { title: 'Stations', value: 1248, icon: <MapPinned size={22} /> },
    { title: 'Platform uptime', value: '99.95%', icon: <Activity size={22} /> },
  ],
  admin: [
    { title: 'Organization users', value: 42, icon: <Users size={22} /> },
    { title: 'Stations managed', value: 86, icon: <MapPinned size={22} /> },
    { title: 'Tariff profiles', value: 4, icon: <BatteryCharging size={22} /> },
    { title: 'Reports ready', value: 12, icon: <Activity size={22} /> },
  ],
  operator: [
    { title: 'Stations', value: 1248, icon: <MapPinned size={22} /> },
    { title: 'Available', value: 756, icon: <BatteryCharging size={22} /> },
    { title: 'Active sessions', value: 341, icon: <Activity size={22} /> },
    { title: 'Open alerts', value: 54, icon: <AlertTriangle size={22} /> },
  ],
  technician: [
    { title: 'Assigned alerts', value: 7, icon: <AlertTriangle size={22} /> },
    { title: 'Critical tasks', value: 2, icon: <Activity size={22} /> },
    { title: 'Stations to inspect', value: 4, icon: <MapPinned size={22} /> },
    { title: 'Resolved this week', value: 18, icon: <BatteryCharging size={22} /> },
  ],
  client: [
    { title: 'Nearby stations', value: 6, icon: <MapPinned size={22} /> },
    { title: 'Active session', value: '1', icon: <BatteryCharging size={22} /> },
    { title: 'Sessions this month', value: 12, icon: <Activity size={22} /> },
    { title: 'Paid invoices', value: 10, icon: <Users size={22} /> },
  ],
}

interface EventRow {
  key: string
  station: string
  event: string
  status: 'success' | 'warning' | 'error'
}

const events: EventRow[] = [
  { key: '1', station: 'Lac 1 Supercharger', event: 'Charging session started', status: 'success' },
  { key: '2', station: 'Ariana City Station', event: 'Connector fault detected', status: 'error' },
  { key: '3', station: 'Bizerte Marina', event: 'Heartbeat received', status: 'success' },
  { key: '4', station: 'Sfax Highway Stop', event: 'No heartbeat for 15 minutes', status: 'warning' },
]

const columns: ColumnsType<EventRow> = [
  { title: 'Station', dataIndex: 'station' },
  { title: 'Event', dataIndex: 'event' },
  {
    title: 'Status',
    dataIndex: 'status',
    render: (status: EventRow['status']) => {
      const color = status === 'success' ? 'green' : status === 'warning' ? 'orange' : 'red'
      return <Tag color={color}>{status}</Tag>
    },
  },
]

export function HomePage() {
  const { user, primaryRole } = useAuth()
  const { message } = App.useApp()
  const navigate = useNavigate()
  const roleConfig = getRoleConfig(primaryRole)
  const stats = statsByRole[primaryRole ?? 'operator']
  const showNetworkMap = primaryRole === 'admin' || primaryRole === 'operator'
  const mapQuery = useQuery({
    queryKey: ['stations', 'dashboard-map'],
    queryFn: () => getStationMap({}),
    enabled: showNetworkMap,
  })

  async function handleCopy(latitude: number, longitude: number) {
    try {
      await copyCoordinates(latitude, longitude)
      void message.success('Coordinates copied.')
    } catch {
      void message.error('The coordinates could not be copied.')
    }
  }

  return (
    <div className="page-stack">
      <section className="workspace-hero">
        <Typography.Text className="breadcrumb">Workspace / {roleConfig.shortLabel}</Typography.Text>
        <Typography.Title level={1}>{roleConfig.shortLabel} dashboard</Typography.Title>
        <Typography.Paragraph>
          Connected as {user?.name}. This screen establishes the real frontend shell while
          the business modules are implemented incrementally.
        </Typography.Paragraph>
      </section>

      <Row gutter={[16, 16]}>
        {stats.map((item) => (
          <Col xs={24} sm={12} lg={6} key={item.title}>
            <Card>
              <div className="stat-icon">{item.icon}</div>
              <Statistic title={item.title} value={item.value} />
            </Card>
          </Col>
        ))}
      </Row>

      {showNetworkMap && <Card className="dashboard-map-card" title="Network map" extra={<Button type="link" onClick={() => navigate('/map')}>Open full map</Button>}>
        {mapQuery.isLoading ? <div className="dashboard-map-loading" /> : <StationMap
          className="dashboard-station-map"
          stations={mapQuery.data?.data ?? []}
          onCopyCoordinates={(station) => void handleCopy(station.latitude, station.longitude)}
          popupExtra={(station) => <StationPopupDetailButton onClick={() => navigate(`/stations/${station.id}`)} />}
        />}
      </Card>}

      <Row gutter={[16, 16]}>
        <Col xs={24} lg={15}>
          <Card title="Recent operational events">
            <Table columns={columns} dataSource={events} pagination={false} size="middle" />
          </Card>
        </Col>
        <Col xs={24} lg={9}>
          <Card title="Availability score">
            <div className="score-panel">
              <Progress type="dashboard" percent={98.7} strokeColor="#159a63" />
              <Typography.Paragraph type="secondary">
                Mock score until station heartbeat ingestion and OCPP telemetry are implemented.
              </Typography.Paragraph>
            </div>
          </Card>
        </Col>
      </Row>
    </div>
  )
}
