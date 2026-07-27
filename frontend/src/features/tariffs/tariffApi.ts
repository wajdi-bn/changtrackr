import { httpClient } from '../../api/httpClient'
import type { ChargingPlan, ChargingPlanPayload, ChargingPlanSubscribers, EffectivePricing, PricingSimulation, PricingSimulationPayload, Tariff, TariffPayload, TariffsResponse, TariffStatus } from '../../types/tariff'

export async function getTariffs(filters: { search?: string; status?: TariffStatus } = {}): Promise<TariffsResponse> {
  const response = await httpClient.get<TariffsResponse>('/tariffs', { params: filters })
  return response.data
}

export async function createTariff(payload: TariffPayload): Promise<Tariff> {
  const response = await httpClient.post<{ data: Tariff }>('/tariffs', payload)
  return response.data.data
}

export async function updateTariff(tariffId: number, payload: Partial<TariffPayload>): Promise<Tariff> {
  const response = await httpClient.patch<{ data: Tariff }>(`/tariffs/${tariffId}`, payload)
  return response.data.data
}

export async function deleteTariff(tariffId: number): Promise<void> {
  await httpClient.delete(`/tariffs/${tariffId}`)
}

export async function assignTariff(tariffId: number, payload: { station_id?: number; connector_id?: number }): Promise<Tariff> {
  const response = await httpClient.post<{ data: Tariff }>(`/tariffs/${tariffId}/assignments`, payload)
  return response.data.data
}

export async function removeTariffAssignment(assignmentId: number): Promise<void> {
  await httpClient.delete(`/tariff-assignments/${assignmentId}`)
}

export async function getEffectivePricing(stationId: number, connectorId?: number): Promise<EffectivePricing> {
  const response = await httpClient.get<{ data: EffectivePricing }>(`/stations/${stationId}/pricing`, { params: { connector_id: connectorId } })
  return response.data.data
}

export async function getChargingPlans(): Promise<ChargingPlan[]> {
  const response = await httpClient.get<{ data: ChargingPlan[] }>('/charging-plans')
  return response.data.data
}

export async function createChargingPlan(payload: ChargingPlanPayload): Promise<ChargingPlan> {
  const response = await httpClient.post<{ data: ChargingPlan }>('/charging-plans', payload)
  return response.data.data
}

export async function updateChargingPlan(planId: number, payload: Partial<ChargingPlanPayload>): Promise<ChargingPlan> {
  const response = await httpClient.patch<{ data: ChargingPlan }>(`/charging-plans/${planId}`, payload)
  return response.data.data
}

export async function deleteChargingPlan(planId: number): Promise<void> {
  await httpClient.delete(`/charging-plans/${planId}`)
}

export async function getChargingPlanSubscribers(planId: number): Promise<ChargingPlanSubscribers> {
  return (await httpClient.get<ChargingPlanSubscribers>(`/charging-plans/${planId}/subscribers`)).data
}

export async function simulatePricing(payload: PricingSimulationPayload): Promise<PricingSimulation> {
  const response = await httpClient.post<{ data: PricingSimulation }>('/pricing/simulate', payload)
  return response.data.data
}
