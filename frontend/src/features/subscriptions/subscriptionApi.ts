import { httpClient } from '../../api/httpClient'
import type {
  PlanSubscription,
  SubscriptionInvoicePage,
  SubscriptionPaymentMethod,
  SubscriptionPlan,
} from '../../types/subscription'

export async function getSubscriptionPlans(): Promise<SubscriptionPlan[]> {
  return (await httpClient.get<{ data: SubscriptionPlan[] }>('/subscription-plans')).data.data
}

export async function getSubscriptions(): Promise<PlanSubscription[]> {
  return (await httpClient.get<{ data: PlanSubscription[] }>('/subscriptions')).data.data
}

export async function getSubscriptionInvoices(page = 1): Promise<SubscriptionInvoicePage> {
  return (await httpClient.get<SubscriptionInvoicePage>('/subscription-invoices', { params: { page } })).data
}

export async function subscribeToPlan(payload: {
  charging_plan_id: number
  auto_renew: boolean
  payment_method: SubscriptionPaymentMethod
  idempotency_key: string
  simulation_outcome?: 'success' | 'declined'
}): Promise<PlanSubscription> {
  return (await httpClient.post<{ data: PlanSubscription }>('/subscriptions', payload)).data.data
}

export async function updateSubscription(subscriptionId: number, autoRenew: boolean): Promise<PlanSubscription> {
  return (await httpClient.patch<{ data: PlanSubscription }>(`/subscriptions/${subscriptionId}`, { auto_renew: autoRenew })).data.data
}

export async function cancelSubscription(subscriptionId: number): Promise<PlanSubscription> {
  return (await httpClient.delete<{ data: PlanSubscription }>(`/subscriptions/${subscriptionId}`)).data.data
}

export async function resumeSubscription(subscriptionId: number): Promise<PlanSubscription> {
  return (await httpClient.post<{ data: PlanSubscription }>(`/subscriptions/${subscriptionId}/resume`)).data.data
}

export async function retrySubscriptionPayment(subscriptionId: number, payload: {
  payment_method: SubscriptionPaymentMethod
  idempotency_key: string
  simulation_outcome?: 'success' | 'declined'
}): Promise<PlanSubscription> {
  return (await httpClient.post<{ data: PlanSubscription }>(`/subscriptions/${subscriptionId}/retry-payment`, payload)).data.data
}

export async function loadSubscriptionInvoice(invoiceId: number): Promise<Blob> {
  return (await httpClient.get<Blob>(`/subscription-invoices/${invoiceId}/document`, { responseType: 'blob', timeout: 30000 })).data
}
