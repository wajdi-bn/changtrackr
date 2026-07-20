import { httpClient } from '../../api/httpClient'
import type { DashboardData, DashboardPeriodKey } from '../../types/dashboard'

export async function getDashboard(period: DashboardPeriodKey): Promise<DashboardData> {
  const response = await httpClient.get<{ data: DashboardData }>('/dashboard', { params: { period } })
  return response.data.data
}
