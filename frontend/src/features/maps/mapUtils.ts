import type { LatLngExpression } from 'leaflet'
import type { StationStatus } from '../../types/station'

export const tunisiaMapCenter: LatLngExpression = [35.4, 9.6]

export const stationStatusColors: Record<StationStatus, string> = {
  available: '#17a768',
  charging: '#7c3aed',
  faulted: '#ef4444',
  offline: '#64748b',
  maintenance: '#f59e0b',
}

export function formatCoordinates(latitude: number, longitude: number): string {
  return `${latitude.toFixed(6)}, ${longitude.toFixed(6)}`
}

export function googleMapsDirectionsUrl(latitude: number, longitude: number): string {
  const url = new URL('https://www.google.com/maps/dir/')
  url.searchParams.set('api', '1')
  url.searchParams.set('destination', `${latitude},${longitude}`)
  url.searchParams.set('travelmode', 'driving')
  url.searchParams.set('dir_action', 'navigate')
  return url.toString()
}

export async function copyCoordinates(latitude: number, longitude: number): Promise<void> {
  await navigator.clipboard.writeText(formatCoordinates(latitude, longitude))
}

export function distanceInKilometers(
  from: { latitude: number; longitude: number },
  to: { latitude: number; longitude: number },
): number {
  const earthRadius = 6371
  const toRadians = (degrees: number) => degrees * Math.PI / 180
  const latitudeDelta = toRadians(to.latitude - from.latitude)
  const longitudeDelta = toRadians(to.longitude - from.longitude)
  const firstLatitude = toRadians(from.latitude)
  const secondLatitude = toRadians(to.latitude)
  const haversine = Math.sin(latitudeDelta / 2) ** 2
    + Math.cos(firstLatitude) * Math.cos(secondLatitude) * Math.sin(longitudeDelta / 2) ** 2

  return earthRadius * 2 * Math.atan2(Math.sqrt(haversine), Math.sqrt(1 - haversine))
}
