import { useDeferredValue, useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Alert, App, Button, Checkbox, Empty, Input, InputNumber, Select, Skeleton } from 'antd'
import { Copy, Crosshair, MapPin, Navigation, Plus, Search, Zap } from 'lucide-react'
import { useNavigate } from 'react-router-dom'
import { MountainBanner } from '../components/MountainBanner'
import { useAuth } from '../features/auth/useAuth'
import { copyCoordinates, formatCoordinates, googleMapsDirectionsUrl, stationStatusColors } from '../features/maps/mapUtils'
import { StationMap, StationPopupDetailButton } from '../features/maps/StationMap'
import { createStation, getStationMap } from '../features/stations/stationApi'
import { StationFormDrawer } from '../features/stations/StationFormDrawer'
import { StationStatusTag } from '../features/stations/StationStatusTag'
import type { ConnectorType, StationMapFilters, StationMapMarker, StationPayload, StationStatus } from '../types/station'

const statusOptions: Array<{ value: StationStatus; label: string }> = [
  { value: 'available', label: 'Available' },
  { value: 'charging', label: 'Charging' },
  { value: 'faulted', label: 'Faulted' },
  { value: 'offline', label: 'Offline' },
  { value: 'maintenance', label: 'Maintenance' },
]

const connectorOptions: Array<{ value: ConnectorType; label: ConnectorType }> = [
  { value: 'CCS2', label: 'CCS2' },
  { value: 'Type 2', label: 'Type 2' },
  { value: 'CHAdeMO', label: 'CHAdeMO' },
]

export function MapPage() {
  const [search, setSearch] = useState('')
  const deferredSearch = useDeferredValue(search)
  const [status, setStatus] = useState<StationStatus>()
  const [city, setCity] = useState<string>()
  const [connectorType, setConnectorType] = useState<ConnectorType>()
  const [minimumPower, setMinimumPower] = useState<number>()
  const [availableOnly, setAvailableOnly] = useState(false)
  const [selectedStation, setSelectedStation] = useState<StationMapMarker | null>(null)
  const [placementMode, setPlacementMode] = useState(false)
  const [drawerOpen, setDrawerOpen] = useState(false)
  const [initialCoordinates, setInitialCoordinates] = useState<{ latitude: number; longitude: number } | null>(null)
  const { user, primaryRole } = useAuth()
  const { message } = App.useApp()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const canCreate = primaryRole !== 'super_admin' && (user?.permissions.includes('stations.create') ?? false)

  const filters = useMemo<StationMapFilters>(() => ({
    search: deferredSearch.trim() || undefined,
    status,
    city,
    connector_type: connectorType,
    min_power_kw: minimumPower,
    available_only: availableOnly || undefined,
  }), [availableOnly, city, connectorType, deferredSearch, minimumPower, status])

  const stationsQuery = useQuery({
    queryKey: ['stations', 'map', filters],
    queryFn: () => getStationMap(filters),
  })
  const createMutation = useMutation({
    mutationFn: createStation,
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['stations'] })
      setDrawerOpen(false)
      setInitialCoordinates(null)
      void message.success('Station added at the selected position.')
    },
    onError: () => void message.error('The station could not be created. Check the form fields.'),
  })

  const stations = stationsQuery.data?.data ?? []
  const summary = stationsQuery.data?.summary

  function openStationCreator(coordinates?: { latitude: number; longitude: number }) {
    setPlacementMode(false)
    setInitialCoordinates(coordinates ?? null)
    setDrawerOpen(true)
  }

  async function handleCopy(station: StationMapMarker) {
    try {
      await copyCoordinates(station.latitude, station.longitude)
      void message.success('Coordinates copied.')
    } catch {
      void message.error('The coordinates could not be copied.')
    }
  }

  return <div className="network-map-page">
    <MountainBanner
      color="green"
      breadcrumb={['Network', 'Map']}
      title="Charging network map"
      count={summary?.stations ?? 0}
      subtitle={primaryRole === 'technician' ? 'Consult station locations and open technical details.' : 'Monitor stations geographically and place new charging sites directly on the map.'}
    />

    <div className="map-summary-strip">
      <MapSummary label="Visible stations" value={summary?.stations ?? 0} color="#6d28d9" />
      <MapSummary label="Available connectors" value={summary?.available_connectors ?? 0} color="#17a768" />
      {statusOptions.slice(0, 3).map((item) => <MapSummary key={item.value} label={item.label} value={summary?.by_status[item.value] ?? 0} color={stationStatusColors[item.value]} />)}
    </div>

    <div className="network-map-toolbar">
      <Input value={search} onChange={(event) => setSearch(event.target.value)} prefix={<Search size={15} />} placeholder="Search station, city, or reference" allowClear />
      <Select value={city} onChange={setCity} options={(stationsQuery.data?.facets.cities ?? []).map((value) => ({ value, label: value }))} placeholder="All cities" allowClear showSearch />
      <Select value={status} onChange={setStatus} options={statusOptions} placeholder="All statuses" allowClear />
      <Select value={connectorType} onChange={setConnectorType} options={connectorOptions} placeholder="All connectors" allowClear />
      <InputNumber value={minimumPower} onChange={(value) => setMinimumPower(value ?? undefined)} min={0} max={1000} addonAfter="kW min" placeholder="Power" />
      <Checkbox checked={availableOnly} onChange={(event) => setAvailableOnly(event.target.checked)}>Available only</Checkbox>
      {canCreate && <Button type={placementMode ? 'primary' : 'default'} icon={<Crosshair size={15} />} onClick={() => setPlacementMode((value) => !value)}>{placementMode ? 'Click a position' : 'Place on map'}</Button>}
      {canCreate && <Button type="primary" icon={<Plus size={15} />} onClick={() => openStationCreator()}>Add station</Button>}
    </div>

    {placementMode && <Alert className="map-placement-alert" type="info" showIcon title="Choose the new station position" description="Click anywhere on the map. You can fine-tune the marker or enter coordinates manually in the form." closable onClose={() => setPlacementMode(false)} />}
    {stationsQuery.isError && <Alert type="error" showIcon title="Unable to load the network map" action={<Button size="small" onClick={() => void stationsQuery.refetch()}>Retry</Button>} />}

    <div className="network-map-workspace">
      <div className={`network-map-canvas ${placementMode ? 'is-placing' : ''}`}>
        {stationsQuery.isLoading ? <Skeleton active className="map-loading-skeleton" /> : <StationMap
          stations={stations}
          selectedStationId={selectedStation?.id}
          onStationSelect={setSelectedStation}
          onMapClick={placementMode ? openStationCreator : undefined}
          onCopyCoordinates={handleCopy}
          popupExtra={(station) => <StationPopupDetailButton onClick={() => navigate(`/stations/${station.id}`)} />}
        />}
      </div>
      <aside className="network-map-list">
        <header><div><h2>Stations in view</h2><p>{stations.length} results after filters</p></div><MapPin size={19} /></header>
        {stations.length === 0 ? <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="No station matches these filters" /> : <div>{stations.map((station) => <button type="button" className={station.id === selectedStation?.id ? 'is-selected' : ''} key={station.id} onClick={() => setSelectedStation(station)}>
          <span className="map-list-status" style={{ background: stationStatusColors[station.status] }} />
          <span><strong>{station.name}</strong><small>{station.location_name}, {station.city}</small></span>
          <span><b>{station.available_connectors_count}/{station.connectors_count}</b><small>available</small></span>
        </button>)}</div>}
      </aside>
    </div>

    {selectedStation && <section className="selected-map-station">
      <div><StationStatusTag status={selectedStation.status} /><div><h2>{selectedStation.name}</h2><p>{selectedStation.address}</p></div></div>
      <div className="selected-map-facts"><span><Zap size={15} /><b>{selectedStation.max_power_kw} kW</b></span><span><MapPin size={15} />{formatCoordinates(selectedStation.latitude, selectedStation.longitude)}</span></div>
      <div><Button icon={<Copy size={14} />} onClick={() => void handleCopy(selectedStation)}>Copy coordinates</Button><Button icon={<Navigation size={14} />} href={googleMapsDirectionsUrl(selectedStation.latitude, selectedStation.longitude)} target="_blank" rel="noreferrer">Itinerary</Button><Button type="primary" onClick={() => navigate(`/stations/${selectedStation.id}`)}>View details</Button></div>
    </section>}

    <StationFormDrawer
      open={drawerOpen}
      submitting={createMutation.isPending}
      initialCoordinates={initialCoordinates}
      onClose={() => { setDrawerOpen(false); setInitialCoordinates(null) }}
      onSubmit={(values: StationPayload) => createMutation.mutate(values)}
    />
  </div>
}

function MapSummary({ label, value, color }: { label: string; value: number; color: string }) {
  return <div><span style={{ background: color }} /><strong>{value}</strong><small>{label}</small></div>
}
