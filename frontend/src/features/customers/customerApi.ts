import { httpClient } from '../../api/httpClient'
import type { ExportFormat } from '../../components/ExportDropdown'
import type { Customer, CustomerFilters, CustomersResponse } from '../../types/customer'

export async function getCustomers(filters: CustomerFilters): Promise<CustomersResponse> {
  const response = await httpClient.get<CustomersResponse>('/customers', { params: filters })
  return response.data
}

export async function getCustomer(customerId: number): Promise<Customer> {
  const response = await httpClient.get<{ data: Customer }>(`/customers/${customerId}`)
  return response.data.data
}

export async function exportCustomers(filters: CustomerFilters, format: ExportFormat): Promise<Blob> {
  const response = await httpClient.get<Blob>('/customers/export', {
    params: { ...filters, format, page: undefined, per_page: undefined },
    responseType: 'blob',
  })
  return response.data
}
