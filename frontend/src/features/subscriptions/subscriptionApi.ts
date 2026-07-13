import { httpClient } from '../../api/httpClient'
import type { PlanSubscription, SubscriptionPlan } from '../../types/subscription'

export async function getSubscriptionPlans(): Promise<SubscriptionPlan[]> {
  const response = await httpClient.get<{ data: SubscriptionPlan[] }>('/subscription-plans')
  return response.data.data
}

export async function getSubscriptions(): Promise<PlanSubscription[]> {
  const response = await httpClient.get<{ data: PlanSubscription[] }>('/subscriptions')
  return response.data.data
}

export async function subscribeToPlan(payload: { charging_plan_id: number; auto_renew: boolean }): Promise<PlanSubscription> {
  const response = await httpClient.post<{ data: PlanSubscription }>('/subscriptions', payload)
  return response.data.data
}

export async function updateSubscription(subscriptionId: number, autoRenew: boolean): Promise<PlanSubscription> {
  const response = await httpClient.patch<{ data: PlanSubscription }>(`/subscriptions/${subscriptionId}`, { auto_renew: autoRenew })
  return response.data.data
}

export async function cancelSubscription(subscriptionId: number): Promise<PlanSubscription> {
  const response = await httpClient.delete<{ data: PlanSubscription }>(`/subscriptions/${subscriptionId}`)
  return response.data.data
}
