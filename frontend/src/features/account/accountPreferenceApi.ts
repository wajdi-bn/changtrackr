import { httpClient } from '../../api/httpClient'

export interface AccountPreferences {
  timezone: string | null
}

export async function getAccountPreferences(): Promise<AccountPreferences> {
  const response = await httpClient.get<{ data: AccountPreferences }>('/account-preferences')
  return response.data.data
}

export async function updateAccountPreferences(payload: AccountPreferences): Promise<AccountPreferences> {
  const response = await httpClient.put<{ data: AccountPreferences }>('/account-preferences', payload)
  return response.data.data
}
