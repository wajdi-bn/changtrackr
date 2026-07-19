import { useMemo, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Alert, App, Button, Checkbox, Empty, Select, Skeleton } from 'antd'
import { Copy, Crosshair, MapPin, Navigation, Zap } from 'lucide-react'
import { useNavigate } from 'react-router-dom'
import { CompactInputNumber } from '../../components/CompactInputNumber'
import { getStationMap } from '../stations/stationApi'
import { StationStatusTag } from '../stations/StationStatusTag'
import { availabilityReasonLabel } from '../stations/availabilityLabels'
import type { ConnectorType, StationMapFilters, StationMapMarker, StationStatus } from '../../types/station'
import { copyCoordinates, formatCoordinates, googleMapsDirectionsUrl, stationStatusColors } from './mapUtils'
import { StationMap, StationPopupDetailButton } from './StationMap'

interface StationMapViewProps {
  search?: string
  status?: StationStatus
  canCreate: boolean
  onCreateAt: (coordinates: { latitude: number; longitude: number }) => void
}

const connectorOptions: Array<{ value: ConnectorType; label: ConnectorType }> = [
  { value: 'CCS2', label: 'CCS2' },
  { value: 'Type 2', label: 'Type 2' },
  { value: 'CHAdeMO', label: 'CHAdeMO' },
]

export function StationMapView({ search, status, canCreate, onCreateAt }: StationMapViewProps) {
  const [city, setCity] = useState<string>()
  const [connectorType, setConnectorType] = useState<ConnectorType>()
  const [minimumPower, setMinimumPower] = useState<number>()
  const [availableOnly, setAvailableOnly] = useState(false)
  const [selectedStation, setSelectedStation] = useState<StationMapMarker | null>(null)
  const [placementMode, setPlacementMode] = useState(false)
  const { message } = App.useApp()
  const navigate = useNavigate()

  const filters = useMemo<StationMapFilters>(() => ({
    search,
    status,
    city,
    connector_type: connectorType,
    min_power_kw: minimumPower,
    available_only: availableOnly || undefined,
  }), [availableOnly, city, connectorType, minimumPower, search, status])

  const stationsQuery = useQuery({
    queryKey: ['stations', 'map', filters],
    queryFn: () => getStationMap(filters),
  })
  const stations = stationsQuery.data?.data ?? []
  const summary = stationsQuery.data?.summary

  function createAt(coordinates: { latitude: number; longitude: number }) {
    setPlacementMode(false)
    onCreateAt(coordinates)
  }

  async function handleCopy(station: StationMapMarker) {
    try {
      await copyCoordinates(station.latitude, station.longitude)
      void message.success('Coordinates copied.')
    } catch {
      void message.error('The coordinates could not be copied.')
    }
  }

  return (
    <section className="station-map-view" aria-label="Station map view">
      <div className="map-summary-strip">
        <MapSummary label="Visible stations" value={summary?.stations ?? 0} color="#6d28d9" />
        <MapSummary label="Available connectors" value={summary?.available_connectors ?? 0} color="#17a768" />
        <MapSummary label="Available" value={summary?.by_status.available ?? 0} color={stationStatusColors.available} />
        <MapSummary label="Charging" value={summary?.by_status.charging ?? 0} color={stationStatusColors.charging} />
        <MapSummary label="Faulted" value={summary?.by_status.faulted ?? 0} color={stationStatusColors.faulted} />
      </div>

      <div className="network-map-toolbar station-map-filters">
        <Select value={city} onChange={setCity} options={(stationsQuery.data?.facets.cities ?? []).map((value) => ({ value, label: value }))} placeholder="All cities" allowClear showSearch />
        <Select value={connectorType} onChange={setConnectorType} options={connectorOptions} placeholder="All connectors" allowClear />
        <CompactInputNumber value={minimumPower} onChange={(value) => setMinimumPower(value === null ? undefined : Number(value))} min={0} max={1000} addon="kW min" placeholder="Power" />
        <Checkbox checked={availableOnly} onChange={(event) => setAvailableOnly(event.target.checked)}>Available only</Checkbox>
        {canCreate && <Button type={placementMode ? 'primary' : 'default'} icon={<Crosshair size={15} />} onClick={() => setPlacementMode((value) => !value)}>{placementMode ? 'Cancel placement' : 'Place on map'}</Button>}
      </div>

      {placementMode && <Alert className="map-placement-alert" type="info" showIcon title="Choose the new station position" description="Click anywhere on the map. You can fine-tune the marker or enter coordinates manually in the form." closable onClose={() => setPlacementMode(false)} />}
      {stationsQuery.isError && <Alert type="error" showIcon title="Unable to load the station map" action={<Button size="small" onClick={() => void stationsQuery.refetch()}>Retry</Button>} />}

      <div className="network-map-workspace">
        <div className={`network-map-canvas ${placementMode ? 'is-placing' : ''}`}>
          {stationsQuery.isLoading ? <Skeleton active className="map-loading-skeleton" /> : <StationMap
            stations={stations}
            selectedStationId={selectedStation?.id}
            onStationSelect={setSelectedStation}
            onMapClick={placementMode ? createAt : undefined}
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
        <div><StationStatusTag status={selectedStation.status} /><div><h2>{selectedStation.name}</h2><p>{selectedStation.address}</p>{selectedStation.ocpp_managed && <small>Live rule: {availabilityReasonLabel(selectedStation.availability_reason)}</small>}</div></div>
        <div className="selected-map-facts"><span><Zap size={15} /><b>{selectedStation.max_power_kw} kW</b></span><span><MapPin size={15} />{formatCoordinates(selectedStation.latitude, selectedStation.longitude)}</span></div>
        <div><Button icon={<Copy size={14} />} onClick={() => void handleCopy(selectedStation)}>Copy coordinates</Button><Button icon={<Navigation size={14} />} href={googleMapsDirectionsUrl(selectedStation.latitude, selectedStation.longitude)} target="_blank" rel="noreferrer">Itinerary</Button><Button type="primary" onClick={() => navigate(`/stations/${selectedStation.id}`)}>View details</Button></div>
      </section>}
    </section>
  )
}

function MapSummary({ label, value, color }: { label: string; value: number; color: string }) {
  return <div><span style={{ background: color }} /><strong>{value}</strong><small>{label}</small></div>
}
