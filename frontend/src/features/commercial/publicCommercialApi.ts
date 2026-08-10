import { httpClient } from '../../api/httpClient'

export interface PublicSaasPlan {
  name: string
  code: string
  description: string | null
  monthly_price_millimes: number
  annual_price_millimes: number
  max_stations: number | null
  max_employees: number | null
  features: string[]
  is_featured: boolean
}

export async function getPublicSaasPlans(): Promise<PublicSaasPlan[]> {
  return (await httpClient.get<{ data: PublicSaasPlan[] }>('/public/commercial-plans')).data.data
}
