import type { ChargingSession } from './charging'

export type CustomerStatus = 'active' | 'inactive' | 'pending'
export type CustomerActivityFilter = 'today' | 'week' | 'month'
export type CustomerSort = 'latest' | 'name' | 'sessions' | 'energy' | 'spent'

export interface Customer {
  id: number
  name: string
  email: string
  phone: string | null
  avatar_url: string | null
  address: string | null
  status: CustomerStatus
  last_login_at: string | null
  activity: {
    sessions: number
    stations: number
    energy_kwh: number
    paid_millimes: number
    outstanding_millimes: number
    first_session_at: string | null
    last_session_at: string | null
  }
  recent_sessions?: ChargingSession[]
  created_at: string | null
}

export interface CustomerFilters {
  search?: string
  status?: CustomerStatus
  last_activity?: CustomerActivityFilter
  sort?: CustomerSort
  page?: number
  per_page?: number
}

export interface CustomersResponse {
  data: Customer[]
  summary: {
    total: number
    active_30_days: number
    sessions: number
    energy_kwh: number
    revenue_millimes: number
  }
  meta: {
    current_page: number
    last_page: number
    per_page: number
    total: number
  }
}
