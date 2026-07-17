import L, { type Marker as LeafletMarker } from 'leaflet'
import { useEffect } from 'react'
import { MapContainer, Marker, TileLayer, useMap, useMapEvents } from 'react-leaflet'
import { tunisiaMapCenter } from './mapUtils'

interface Coordinates {
  latitude: number
  longitude: number
}

interface LocationPickerMapProps {
  value: Coordinates
  onChange: (coordinates: Coordinates) => void
}

const pickerIcon = L.divIcon({
  className: 'station-map-marker-shell',
  html: '<span class="station-map-marker is-picker" style="--marker-color:#6d28d9"><span></span></span>',
  iconSize: [34, 42],
  iconAnchor: [17, 40],
})

export function LocationPickerMap({ value, onChange }: LocationPickerMapProps) {
  return <MapContainer className="location-picker-map" center={value ? [value.latitude, value.longitude] : tunisiaMapCenter} zoom={value ? 14 : 7} scrollWheelZoom>
    <TileLayer
      attribution='&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
      url="https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png"
    />
    <PickerEvents onChange={onChange} />
    <PickerViewport value={value} />
    <Marker
      position={[value.latitude, value.longitude]}
      icon={pickerIcon}
      draggable
      eventHandlers={{
        dragend: (event) => {
          const position = (event.target as LeafletMarker).getLatLng()
          onChange({ latitude: position.lat, longitude: position.lng })
        },
      }}
    />
  </MapContainer>
}

function PickerEvents({ onChange }: Pick<LocationPickerMapProps, 'onChange'>) {
  useMapEvents({
    click: (event) => onChange({ latitude: event.latlng.lat, longitude: event.latlng.lng }),
  })
  return null
}

function PickerViewport({ value }: Pick<LocationPickerMapProps, 'value'>) {
  const map = useMap()

  useEffect(() => {
    const timer = window.setTimeout(() => map.invalidateSize(), 120)
    return () => window.clearTimeout(timer)
  }, [map])

  useEffect(() => {
    map.panTo([value.latitude, value.longitude])
  }, [map, value.latitude, value.longitude])

  return null
}
