import { httpClient } from '../../api/httpClient'
import type { BillingCycle, CommercialPortfolio, OrganizationBillingWorkspace, OrganizationCommercialSubscription, OrganizationInvoice, SaasPlan } from '../../types/commercial'

export type SaasPlanPayload = Omit<SaasPlan, 'id'>

export async function getSaasPlans(): Promise<SaasPlan[]> {
  return (await httpClient.get<{ data: SaasPlan[] }>('/commercial/plans')).data.data
}

export async function createSaasPlan(payload: SaasPlanPayload): Promise<SaasPlan> {
  return (await httpClient.post<{ data: SaasPlan }>('/commercial/plans', payload)).data.data
}

export async function updateSaasPlan(id: number, payload: Partial<SaasPlanPayload>): Promise<SaasPlan> {
  return (await httpClient.patch<{ data: SaasPlan }>(`/commercial/plans/${id}`, payload)).data.data
}

export async function getCommercialPortfolio(): Promise<CommercialPortfolio> {
  return (await httpClient.get<CommercialPortfolio>('/commercial/portfolio')).data
}

export async function getOrganizationBilling(): Promise<OrganizationBillingWorkspace> {
  return (await httpClient.get<OrganizationBillingWorkspace>('/organization-billing')).data
}

export async function requestOrganizationPlan(saasPlanId: number, billingCycle: BillingCycle): Promise<OrganizationInvoice> {
  return (await httpClient.post<{ data: OrganizationInvoice }>('/organization-billing/requests', { saas_plan_id: saasPlanId, billing_cycle: billingCycle })).data.data
}

export async function extendOrganizationTrial(subscriptionId: number, days: number, note?: string): Promise<OrganizationCommercialSubscription> {
  return (await httpClient.post<{ data: OrganizationCommercialSubscription }>(`/commercial/subscriptions/${subscriptionId}/extend-trial`, { days, note })).data.data
}

export async function suspendOrganizationSubscription(subscriptionId: number, note?: string): Promise<OrganizationCommercialSubscription> {
  return (await httpClient.post<{ data: OrganizationCommercialSubscription }>(`/commercial/subscriptions/${subscriptionId}/suspend`, { note })).data.data
}

export async function restoreOrganizationSubscription(subscriptionId: number, note?: string): Promise<OrganizationCommercialSubscription> {
  return (await httpClient.post<{ data: OrganizationCommercialSubscription }>(`/commercial/subscriptions/${subscriptionId}/restore`, { note })).data.data
}

export async function settleOrganizationInvoice(invoiceId: number): Promise<OrganizationInvoice> {
  return (await httpClient.post<{ data: OrganizationInvoice }>(`/commercial/invoices/${invoiceId}/settle`)).data.data
}

export async function voidOrganizationInvoice(invoiceId: number): Promise<OrganizationInvoice> {
  return (await httpClient.post<{ data: OrganizationInvoice }>(`/commercial/invoices/${invoiceId}/void`)).data.data
}

export async function downloadOrganizationInvoice(invoiceId: number): Promise<Blob> {
  return (await httpClient.get<Blob>(`/commercial/invoices/${invoiceId}/document`, { responseType: 'blob' })).data
}
