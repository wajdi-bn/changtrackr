import { useDeferredValue, useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Alert, App, Button, Card, Empty, Input, Popconfirm, Segmented, Skeleton, Tabs, Tooltip } from 'antd'
import {
  ChevronRight,
  Clock3,
  Filter,
  Gauge,
  Grid2X2,
  List,
  MapPin,
  PencilLine,
  Plus,
  Search,
  Trash2,
  Zap,
} from 'lucide-react'
import { useNavigate } from 'react-router-dom'
import { createStation, deleteStation, getStations, updateStation } from '../features/stations/stationApi'
import { StationFormDrawer } from '../features/stations/StationFormDrawer'
import { StationStatusTag } from '../features/stations/StationStatusTag'
import { useAuth } from '../features/auth/useAuth'
import type { Station, StationPayload, StationStatus } from '../types/station'

const statusTabs: Array<{ key: 'all' | StationStatus; label: string }> = [
  { key: 'all', label: 'All stations' },
  { key: 'available', label: 'Available' },
  { key: 'charging', label: 'Charging' },
  { key: 'faulted', label: 'Faulted' },
  { key: 'offline', label: 'Offline' },
  { key: 'maintenance', label: 'Maintenance' },
]

export function StationsPage() {
  const [status, setStatus] = useState<'all' | StationStatus>('all')
  const [search, setSearch] = useState('')
  const deferredSearch = useDeferredValue(search)
  const [view, setView] = useState<'grid' | 'list'>('grid')
  const [drawerOpen, setDrawerOpen] = useState(false)
  const [selectedStation, setSelectedStation] = useState<Station | null>(null)
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { message } = App.useApp()
  const { user } = useAuth()
  const canCreate = user?.permissions.includes('stations.create') ?? false
  const canUpdate = user?.permissions.includes('stations.update') ?? false
  const canDelete = user?.permissions.includes('stations.delete') ?? false

  const filters = useMemo(() => ({
    search: deferredSearch.trim() || undefined,
    status: status === 'all' ? undefined : status,
  }), [deferredSearch, status])

  const stationsQuery = useQuery({
    queryKey: ['stations', filters],
    queryFn: () => getStations(filters),
  })

  const saveStation = useMutation({
    mutationFn: (values: StationPayload) => selectedStation
      ? updateStation(selectedStation.id, values)
      : createStation(values),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['stations'] })
      setDrawerOpen(false)
      setSelectedStation(null)
      void message.success(selectedStation ? 'Station updated successfully.' : 'Station added successfully.')
    },
    onError: () => void message.error('The station could not be saved. Check the form and try again.'),
  })

  const removeStation = useMutation({
    mutationFn: deleteStation,
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['stations'] })
      void message.success('Station deleted successfully.')
    },
    onError: () => void message.error('The station could not be deleted.'),
  })

  const summary = stationsQuery.data?.summary
  const stations = stationsQuery.data?.data ?? []

  function openDrawer(station?: Station) {
    setSelectedStation(station ?? null)
    setDrawerOpen(true)
  }

  return (
    <div className="stations-page">
      <section className="stations-hero">
        <img src="/assets/charge-hero.png" alt="Charging station network" />
        <div className="stations-hero-overlay" />
        <div className="stations-hero-panel">
          <h1>Charging station inventory</h1>
          <p>Monitor station availability, connector capacity, heartbeat recency, and uptime across Tunisia.</p>
          <div>
            <HeroMetric value={summary?.stations ?? 0} label="Stations" />
            <HeroMetric value={summary?.connectors ?? 0} label="Connectors" />
            <HeroMetric value={`${summary?.availability_percent ?? 0}%`} label="Availability" />
          </div>
        </div>
      </section>

      <Tabs
        className="stations-status-tabs"
        activeKey={status}
        onChange={(key) => setStatus(key as 'all' | StationStatus)}
        items={statusTabs}
      />

      <div className="stations-toolbar">
        <Input
          value={search}
          onChange={(event) => setSearch(event.target.value)}
          prefix={<Search size={15} />}
          placeholder="Search stations"
          allowClear
        />
        <Tooltip title="Open geographic filters"><Button icon={<Filter size={16} />} onClick={() => navigate('/map')} /></Tooltip>
        <Segmented
          value={view}
          onChange={(value) => setView(value as 'grid' | 'list')}
          options={[
            { value: 'grid', icon: <Grid2X2 size={16} />, label: '' },
            { value: 'list', icon: <List size={16} />, label: '' },
          ]}
        />
        <span className="stations-toolbar-spacer" />
        {canCreate && <Button type="primary" icon={<Plus size={15} />} onClick={() => openDrawer()}>Add station</Button>}
      </div>

      {stationsQuery.isError && (
        <Alert className="stations-feedback" type="error" showIcon title="Unable to load stations" description="Make sure the Laravel API is running, then retry." action={<Button size="small" onClick={() => void stationsQuery.refetch()}>Retry</Button>} />
      )}

      {stationsQuery.isLoading ? (
        <div className="stations-grid">{Array.from({ length: 8 }, (_, index) => <Card key={index}><Skeleton active /></Card>)}</div>
      ) : stations.length === 0 ? (
        <Empty className="stations-empty" description="No station matches the current filters" />
      ) : view === 'grid' ? (
        <div className="stations-grid">
          {stations.map((station) => (
            <Card key={station.id} className="station-card" cover={station.model_image ? <img src={station.model_image} alt={`${station.model} charger`} /> : undefined}>
              <div className="station-card-heading">
                <div><h2>{station.name}</h2><p><MapPin size={12} />{station.location}</p></div>
                <StationStatusTag status={station.status} />
              </div>
              <div className="station-card-facts">
                <StationFact icon={<Zap size={13} />} label="Connectors" value={`${station.connectors_count}`} />
                <StationFact icon={<Gauge size={13} />} label="Power" value={`${station.max_power_kw} kW`} />
                <StationFact icon={<Clock3 size={13} />} label="Heartbeat" value={station.last_heartbeat_relative} />
                <StationFact label="Uptime" value={`${station.uptime_percent}%`} />
              </div>
              <div className="station-card-actions">
                {canUpdate && <Tooltip title="Edit station"><Button type="text" icon={<PencilLine size={15} />} onClick={() => openDrawer(station)} /></Tooltip>}
                {canDelete && (
                  <Popconfirm
                    title="Delete this station?"
                    description="The station will be removed from the active inventory."
                    okText="Delete"
                    okButtonProps={{ danger: true, loading: removeStation.isPending }}
                    cancelText="Cancel"
                    onConfirm={() => removeStation.mutate(station.id)}
                  >
                    <Tooltip title="Delete station"><Button type="text" danger icon={<Trash2 size={15} />} /></Tooltip>
                  </Popconfirm>
                )}
                <Button className="station-detail-button" onClick={() => navigate(`/stations/${station.id}`)}>View station detail <ChevronRight size={15} /></Button>
              </div>
            </Card>
          ))}
        </div>
      ) : (
        <div className="station-list-table">
          {stations.map((station) => (
            <div className="station-list-row" key={station.id}>
              <button className="station-list-open" type="button" onClick={() => navigate(`/stations/${station.id}`)}>
                <img src={station.model_image ?? '/assets/charger-terra-hp-150.png'} alt="" />
                <span className="station-list-name"><strong>{station.name}</strong><small>{station.model} - {station.location}</small></span>
                <StationStatusTag status={station.status} />
                <span>{station.connectors_count} connectors</span>
                <span>{station.max_power_kw} kW</span>
                <span>{station.uptime_percent}% uptime</span>
                <ChevronRight size={16} />
              </button>
              <div className="station-list-actions">
                {canUpdate && <Tooltip title="Edit station"><Button type="text" icon={<PencilLine size={15} />} onClick={() => openDrawer(station)} /></Tooltip>}
                {canDelete && (
                  <Popconfirm
                    title="Delete this station?"
                    description="The station will be removed from the active inventory."
                    okText="Delete"
                    okButtonProps={{ danger: true, loading: removeStation.isPending }}
                    cancelText="Cancel"
                    onConfirm={() => removeStation.mutate(station.id)}
                  >
                    <Tooltip title="Delete station"><Button type="text" danger icon={<Trash2 size={15} />} /></Tooltip>
                  </Popconfirm>
                )}
              </div>
            </div>
          ))}
        </div>
      )}

      <StationFormDrawer
        open={drawerOpen}
        station={selectedStation}
        submitting={saveStation.isPending}
        onClose={() => { setDrawerOpen(false); setSelectedStation(null) }}
        onSubmit={(values) => saveStation.mutate(values)}
      />
    </div>
  )
}

function HeroMetric({ value, label }: { value: string | number; label: string }) {
  return <div><strong>{value}</strong><span>{label}</span></div>
}

function StationFact({ icon, label, value }: { icon?: React.ReactNode; label: string; value: string }) {
  return <div><span>{icon}{label}</span><strong>{value}</strong></div>
}
