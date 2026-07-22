import type { AuthUser } from './auth'

export interface ProfileData {
  user: AuthUser
  personal: {
    name: string
    phone: string | null
    job_title: string | null
    bio: string | null
    locale: 'en' | 'fr' | 'ar'
    timezone: string
  }
  address: {
    address_line_1: string | null
    address_line_2: string | null
    city: string | null
    region: string | null
    postal_code: string | null
    country_code: string | null
  }
  professional_links: {
    linkedin_url: string | null
    website_url: string | null
  }
  metadata: {
    account_created_at: string | null
    profile_updated_at: string | null
    last_login_at: string | null
    email_verified_at: string | null
    sign_in_providers: string[]
    local_password_configured: boolean
  }
}

export interface UpdateProfilePayload {
  name: string
  phone?: string | null
  job_title?: string | null
  bio?: string | null
  address_line_1?: string | null
  address_line_2?: string | null
  city?: string | null
  region?: string | null
  postal_code?: string | null
  country_code?: string | null
  locale?: 'en' | 'fr' | 'ar'
  timezone?: string | null
  linkedin_url?: string | null
  website_url?: string | null
}
