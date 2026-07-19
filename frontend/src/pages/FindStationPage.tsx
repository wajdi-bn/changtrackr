import { useDeferredValue, useEffect, useMemo, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { App, Button, Empty, Input, Segmented, Skeleton, Tooltip } from 'antd'
import { Copy, Gauge, Grid2X2, Map as MapIcon, MapPin, Navigation, Search, Zap } from 'lucide-react'
import { useNavigate, useParams } from 'react-router-dom'
import { MountainBanner } from '../components/MountainBanner'
import { StartSessionDrawer } from '../features/charging/StartSessionDrawer'
import { ConnectorTypeIcon } from '../features/charging/ConnectorTypeIcon'
import { copyCoordinates, distanceInKilometers, formatCoordinates, googleMapsDirectionsUrl, stationStatusColors } from '../features/maps/mapUtils'
import { StationMap } from '../features/maps/StationMap'
import { getStationMap } from '../features/stations/stationApi'
import { StationStatusTag } from '../features/stations/StationStatusTag'
import type { StationMapMarker } from '../types/station'

export function FindStationPage() {
  const [search, setSearch] = useState('')
  const [view, setView] = useState<'cards' | 'map'>('cards')
  const deferredSearch = useDeferredValue(search)
  const [selectedStationId, setSelectedStationId] = useState<number | null>(null)
  const [mapSelectedStationId, setMapSelectedStationId] = useState<number | null>(null)
  const [userPosition, setUserPosition] = useState<{ latitude: number; longitude: number } | null>(null)
  const [locating, setLocating] = useState(false)
  const params = useParams<{ stationId?: string; connectorId?: string }>()
  const navigate = useNavigate()
  const { message } = App.useApp()
  const stationsQuery = useQuery({
    queryKey: ['stations', 'client-finder', deferredSearch],
    queryFn: () => getStationMap({ search: deferredSearch.trim() || undefined, available_only: true }),
  })
  const stations = useMemo(() => {
    const filtered = stationsQuery.data?.data ?? []

    return userPosition ? [...filtered].sort((first, second) => (
      distanceInKilometers(userPosition, first) - distanceInKilometers(userPosition, second)
    )) : filtered
  }, [stationsQuery.data?.data, userPosition])
  const mapSelectedStation = stations.find((station) => station.id === mapSelectedStationId) ?? null
  const deepLinkedStationId = params.stationId ? Number(params.stationId) : null
  const deepLinkedConnectorId = params.connectorId ? Number(params.connectorId) : null

  useEffect(() => {
    if (deepLinkedStationId && Number.isFinite(deepLinkedStationId)) setSelectedStationId(deepLinkedStationId)
  }, [deepLinkedStationId])

  function locateUser() {
    if (!navigator.geolocation) {
      void message.error('Geolocation is not supported by this browser.')
      return
    }

    setLocating(true)
    navigator.geolocation.getCurrentPosition(
      (position) => {
        setUserPosition({ latitude: position.coords.latitude, longitude: position.coords.longitude })
        setLocating(false)
        void message.success('Stations are now sorted by distance.')
      },
      () => {
        setLocating(false)
        void message.error('Your position could not be accessed. Check the browser permission.')
      },
      { enableHighAccuracy: true, timeout: 10000, maximumAge: 60000 },
    )
  }

  async function handleCopy(latitude: number, longitude: number) {
    try {
      await copyCoordinates(latitude, longitude)
      void message.success('Coordinates copied.')
    } catch {
      void message.error('The coordinates could not be copied.')
    }
  }

  return <div className="find-station-page">
    <MountainBanner color="green" breadcrumb={['Driver', 'Find station']} title="Find an available station" count={stations.length} subtitle="Compare connector availability and power, then start charging without leaving the platform." />
    <div className="station-finder-toolbar"><Input value={search} onChange={(event) => setSearch(event.target.value)} prefix={<Search size={15} />} placeholder="Search by station, city, or location" allowClear /><Button icon={<Navigation size={15} />} loading={locating} onClick={locateUser}>{userPosition ? 'Location active' : 'Near me'}</Button><Segmented className="finder-view-switcher" value={view} onChange={(value) => setView(value as 'cards' | 'map')} options={[{ value: 'cards', icon: <Grid2X2 size={15} />, label: 'Cards' }, { value: 'map', icon: <MapIcon size={15} />, label: 'Map' }]} /></div>
    {!stationsQuery.isLoading && stations.length > 0 && view === 'map' && <section className="client-map-view">
      <div className="network-map-workspace client-network-map-workspace">
        <div className="network-map-canvas">
          <StationMap
            stations={stations}
            selectedStationId={mapSelectedStationId}
            userPosition={userPosition}
            onStationSelect={(station) => setMapSelectedStationId(station.id)}
            onCopyCoordinates={(station) => void handleCopy(station.latitude, station.longitude)}
            popupExtra={(station) => <RemoteChargeButton station={station} label="Charge" size="small" onCharge={() => setSelectedStationId(station.id)} />}
          />
        </div>
        <aside className="network-map-list">
          <header><div><h2>Available stations</h2><p>{stations.length} charging locations</p></div><MapPin size={19} /></header>
          <div>{stations.map((station) => <button type="button" className={station.id === mapSelectedStationId ? 'is-selected' : ''} key={station.id} onClick={() => setMapSelectedStationId(station.id)}>
            <span className="map-list-status" style={{ background: stationStatusColors[station.status] }} />
            <span><strong>{station.name}</strong><small>{userPosition ? `${distanceInKilometers(userPosition, station).toFixed(1)} km away` : station.location}</small></span>
            <span><b>{station.available_connectors_count}/{station.connectors_count}</b><small>available</small></span>
          </button>)}</div>
        </aside>
      </div>
      {mapSelectedStation && <section className="selected-map-station client-selected-station">
        <div><StationStatusTag status={mapSelectedStation.status} /><div><h2>{mapSelectedStation.name}</h2><p>{mapSelectedStation.organization?.name ?? 'Charging network'} - {mapSelectedStation.location}</p></div></div>
        <div className="selected-map-facts"><span><Zap size={15} /><b>{mapSelectedStation.max_power_kw} kW</b></span><span><MapPin size={15} />{formatCoordinates(mapSelectedStation.latitude, mapSelectedStation.longitude)}</span></div>
        <div><Button icon={<Copy size={14} />} onClick={() => void handleCopy(mapSelectedStation.latitude, mapSelectedStation.longitude)}>Copy coordinates</Button><Button icon={<Navigation size={14} />} href={googleMapsDirectionsUrl(mapSelectedStation.latitude, mapSelectedStation.longitude)} target="_blank" rel="noreferrer">Itinerary</Button><RemoteChargeButton station={mapSelectedStation} label="Charge" onCharge={() => setSelectedStationId(mapSelectedStation.id)} /></div>
      </section>}
    </section>}
    {stationsQuery.isLoading ? <div className={view === 'map' ? 'finder-map-loading' : 'finder-grid'}>{Array.from({ length: view === 'map' ? 1 : 6 }, (_, index) => <Skeleton key={index} active />)}</div> : stations.length === 0 ? <Empty description="No available connector matches your search" /> : view === 'cards' && (
      <div className="finder-grid">{stations.map((station) => <article className="finder-card" key={station.id}>
        <img src={station.model_image ?? '/assets/charger-terra-hp-150.png'} alt={`${station.name} charging station`} />
        <div className="finder-card-body">
          <div className="finder-card-title"><div><h2>{station.name}</h2><small>{station.organization?.name ?? 'Charging network'}</small><p><MapPin size={13} />{station.location}</p></div><strong>{userPosition ? `${distanceInKilometers(userPosition, station).toFixed(1)} km` : `${station.available_connectors_count} available`}</strong></div>
          <div className="finder-card-facts"><span><Zap size={14} /><b>{station.max_power_kw} kW</b>Maximum power</span><span><Gauge size={14} /><b>{station.uptime_percent}%</b>Uptime</span></div>
          <div className="finder-connectors">{station.connectors.filter((connector) => connector.status === 'available').slice(0, 4).map((connector) => <span key={connector.id}><ConnectorTypeIcon type={connector.type} />{connector.external_id} - {connector.type}</span>)}</div>
          <div className="finder-card-actions"><Button icon={<Copy size={14} />} onClick={() => void handleCopy(station.latitude, station.longitude)} aria-label={`Copy coordinates for ${station.name}`} /><Button icon={<Navigation size={14} />} href={googleMapsDirectionsUrl(station.latitude, station.longitude)} target="_blank" rel="noreferrer">Itinerary</Button><RemoteChargeButton station={station} label="Start charging" onCharge={() => setSelectedStationId(station.id)} /></div>
        </div>
      </article>)}</div>
    )}
    <StartSessionDrawer open={selectedStationId !== null} stations={stationsQuery.data?.data ?? []} initialStationId={selectedStationId} initialConnectorId={deepLinkedStationId === selectedStationId ? deepLinkedConnectorId : null} onClose={() => setSelectedStationId(null)} onSessionStarted={() => navigate('/my-sessions')} />
  </div>
}

function RemoteChargeButton({ station, label, size, onCharge }: { station: StationMapMarker; label: string; size?: 'small'; onCharge: () => void }) {
  const button = <Button size={size} type="primary" disabled={!station.remote_start_available} onClick={onCharge}>{label}</Button>

  return station.remote_start_available
    ? button
    : <Tooltip title={remoteStartUnavailableLabel(station.remote_start_unavailable_reason)}><span>{button}</span></Tooltip>
}

function remoteStartUnavailableLabel(reason: StationMapMarker['remote_start_unavailable_reason']) {
  return ({
    not_ocpp_managed: 'Remote charging is not configured for this station.',
    organization_inactive: 'This charging network is currently inactive.',
    maintenance: 'This station is under maintenance.',
    disabled: 'This station has been disabled by the operator.',
    station_offline: 'This station is offline and cannot receive a start command.',
    station_unavailable: 'This station is not currently ready to start a session.',
    no_available_connector: 'No connector is currently ready for remote charging.',
  } as const)[reason ?? 'station_unavailable']
}
