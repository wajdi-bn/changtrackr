import { httpClient } from '../../api/httpClient'

export interface AccountSecurity {
  email: string
  email_verified: boolean
  password_login_enabled: boolean
  sign_in_providers: string[]
}

export interface ChangePasswordPayload {
  current_password: string
  password: string
  password_confirmation: string
}

export async function getAccountSecurity(): Promise<AccountSecurity> {
  const response = await httpClient.get<{ data: AccountSecurity }>('/account-security')
  return response.data.data
}

export async function changeAccountPassword(payload: ChangePasswordPayload): Promise<void> {
  await httpClient.put('/account-security/password', payload)
}
