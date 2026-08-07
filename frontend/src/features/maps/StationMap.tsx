import { Button, Tag } from 'antd'
import { Copy, ExternalLink, MapPin, Navigation, PlugZap, Zap } from 'lucide-react'
import L, { type LatLngBoundsExpression, type LatLngExpression } from 'leaflet'
import { useEffect, useMemo, type ReactNode } from 'react'
import { MapContainer, Marker, Popup, TileLayer, useMap, useMapEvents } from 'react-leaflet'
import type { StationMapMarker, StationStatus } from '../../types/station'
import { formatCoordinates, googleMapsDirectionsUrl, stationStatusColors, tunisiaMapCenter } from './mapUtils'

interface StationMapProps {
  stations: StationMapMarker[]
  selectedStationId?: number | null
  userPosition?: { latitude: number; longitude: number } | null
  className?: string
  onStationSelect?: (station: StationMapMarker) => void
  onMapClick?: (coordinates: { latitude: number; longitude: number }) => void
  onCopyCoordinates?: (station: StationMapMarker) => void
  popupExtra?: (station: StationMapMarker) => ReactNode
}

export function StationMap({ stations, selectedStationId, userPosition, className, onStationSelect, onMapClick, onCopyCoordinates, popupExtra }: StationMapProps) {
  return <MapContainer className={className ?? 'station-map'} center={tunisiaMapCenter} zoom={7} minZoom={5} scrollWheelZoom>
    <TileLayer
      attribution='&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
      url="https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png"
    />
    <MapViewport stations={stations} selectedStationId={selectedStationId} userPosition={userPosition} />
    <MapClickHandler onMapClick={onMapClick} />
    {stations.map((station) => <Marker
      key={station.id}
      position={[station.latitude, station.longitude]}
      icon={stationMarkerIcon(station.status, station.id === selectedStationId)}
      eventHandlers={{ click: () => onStationSelect?.(station) }}
    >
      <Popup minWidth={260}>
        <div className="station-map-popup">
          <header><div><strong>{station.name}</strong><small>{station.organization?.name ?? 'Charging network'}</small></div><Tag color={stationStatusColors[station.status]}>{station.status}</Tag></header>
          <p><MapPin size={14} />{station.location_name}, {station.city}</p>
          <p className="station-map-address">{station.address}</p>
          <div className="station-map-popup-facts"><span><PlugZap size={14} /><b>{station.available_connectors_count}/{station.connectors_count}</b> available</span><span><Zap size={14} /><b>{station.max_power_kw} kW</b> power</span></div>
          <small className="station-map-coordinates">{formatCoordinates(station.latitude, station.longitude)}</small>
          <div className="station-map-popup-actions">
            <Button size="small" icon={<Copy size={13} />} onClick={() => onCopyCoordinates?.(station)}>Copy</Button>
            <Button size="small" icon={<Navigation size={13} />} href={googleMapsDirectionsUrl(station.latitude, station.longitude)} target="_blank" rel="noreferrer">Itinerary</Button>
            {popupExtra?.(station)}
          </div>
        </div>
      </Popup>
    </Marker>)}
    {userPosition && <Marker position={[userPosition.latitude, userPosition.longitude]} icon={userPositionIcon()}>
      <Popup><div className="station-map-user-popup"><Navigation size={14} />Your current position</div></Popup>
    </Marker>}
  </MapContainer>
}

function MapClickHandler({ onMapClick }: Pick<StationMapProps, 'onMapClick'>) {
  useMapEvents({
    click: (event) => onMapClick?.({ latitude: event.latlng.lat, longitude: event.latlng.lng }),
  })
  return null
}

function MapViewport({ stations, selectedStationId, userPosition }: Pick<StationMapProps, 'stations' | 'selectedStationId' | 'userPosition'>) {
  const map = useMap()
  const points = useMemo<LatLngExpression[]>(() => [
    ...stations.map((station) => [station.latitude, station.longitude] as LatLngExpression),
    ...(userPosition ? [[userPosition.latitude, userPosition.longitude] as LatLngExpression] : []),
  ], [stations, userPosition])

  useEffect(() => {
    const timer = window.setTimeout(() => map.invalidateSize(), 80)
    return () => window.clearTimeout(timer)
  }, [map])

  useEffect(() => {
    const selected = stations.find((station) => station.id === selectedStationId)
    if (selected) {
      map.flyTo([selected.latitude, selected.longitude], Math.max(map.getZoom(), 13), { duration: 0.5 })
      return
    }

    const firstPoint = points[0]
    if (points.length === 1 && firstPoint) {
      map.setView(firstPoint, 12)
    } else if (points.length > 1) {
      map.fitBounds(points as LatLngBoundsExpression, { padding: [36, 36], maxZoom: 12 })
    }
  }, [map, points, selectedStationId, stations])

  return null
}

function stationMarkerIcon(status: StationStatus, selected: boolean): L.DivIcon {
  return L.divIcon({
    className: 'station-map-marker-shell',
    html: `<span class="station-map-marker${selected ? ' is-selected' : ''}" style="--marker-color:${stationStatusColors[status]}"><span></span></span>`,
    iconSize: selected ? [34, 42] : [30, 38],
    iconAnchor: selected ? [17, 40] : [15, 36],
    popupAnchor: [0, -34],
  })
}

function userPositionIcon(): L.DivIcon {
  return L.divIcon({
    className: 'station-map-marker-shell',
    html: '<span class="station-user-marker"><span></span></span>',
    iconSize: [28, 28],
    iconAnchor: [14, 14],
  })
}

export function StationPopupDetailButton({ onClick }: { onClick: () => void }) {
  return <Button size="small" type="primary" icon={<ExternalLink size={13} />} onClick={onClick}>Details</Button>
}
