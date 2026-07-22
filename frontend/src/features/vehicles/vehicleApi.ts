import { httpClient } from '../../api/httpClient'
import type { Vehicle, VehiclePayload } from '../../types/vehicle'

export async function getVehicles(): Promise<Vehicle[]> {
  const response = await httpClient.get<{ data: Vehicle[] }>('/vehicles')
  return response.data.data
}

export async function createVehicle(payload: VehiclePayload): Promise<Vehicle> {
  const response = await httpClient.post<{ data: Vehicle }>('/vehicles', payload)
  return response.data.data
}

export async function updateVehicle(vehicleId: number, payload: Partial<VehiclePayload>): Promise<Vehicle> {
  const response = await httpClient.patch<{ data: Vehicle }>(`/vehicles/${vehicleId}`, payload)
  return response.data.data
}

export async function deleteVehicle(vehicleId: number): Promise<void> {
  await httpClient.delete(`/vehicles/${vehicleId}`)
}
