export const SITE_URL = 'https://chargetrackr.me'
export const SITE_TITLE = 'ChargeTrackr | EV Charging Station Management Platform'
export const SITE_DESCRIPTION = 'Monitor EV charging stations, OCPP availability, sessions, payments, alerts, maintenance and reports from one operational platform.'
export const SOCIAL_IMAGE_URL = `${SITE_URL}/assets/seo/charge-trackr-social.webp`
export const PUBLIC_ROBOTS = 'index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1'
export const PRIVATE_ROBOTS = 'noindex, nofollow, noarchive'

export const PUBLIC_STRUCTURED_DATA = {
  '@context': 'https://schema.org',
  '@graph': [
    {
      '@type': 'WebSite',
      '@id': `${SITE_URL}/#website`,
      url: `${SITE_URL}/`,
      name: 'ChargeTrackr',
      description: 'EV charging station supervision and operations platform.',
      inLanguage: 'en',
    },
    {
      '@type': 'WebApplication',
      '@id': `${SITE_URL}/#application`,
      name: 'ChargeTrackr',
      url: `${SITE_URL}/`,
      applicationCategory: 'BusinessApplication',
      applicationSubCategory: 'EV charging station management',
      operatingSystem: 'Any',
      browserRequirements: 'Requires JavaScript and a modern web browser.',
      description: SITE_DESCRIPTION,
      image: SOCIAL_IMAGE_URL,
      offers: {
        '@type': 'Offer',
        price: '0',
        priceCurrency: 'TND',
        category: 'Trial',
        description: '14-day evaluation workspace',
      },
    },
  ],
} as const

const PRIVATE_PAGE_TITLES: Record<string, string> = {
  '/login': 'Sign in',
  '/register': 'Create account',
  '/verify-email': 'Verify email',
  '/forgot-password': 'Reset password',
  '/reset-password': 'Choose a new password',
  '/activate-invitation': 'Activate account',
  '/auth/google/callback': 'Google sign in',
  '/welcome': 'Welcome',
}

export interface SeoConfig {
  title: string
  description: string
  robots: string
  canonical: string | null
  indexable: boolean
}

export function resolveSeoConfig(pathname: string): SeoConfig {
  const normalizedPath = pathname.replace(/\/+$/, '') || '/'

  if (normalizedPath === '/') {
    return {
      title: SITE_TITLE,
      description: SITE_DESCRIPTION,
      robots: PUBLIC_ROBOTS,
      canonical: `${SITE_URL}/`,
      indexable: true,
    }
  }

  const pageTitle = PRIVATE_PAGE_TITLES[normalizedPath] ?? 'Secure workspace'

  return {
    title: `${pageTitle} | ChargeTrackr`,
    description: 'Private ChargeTrackr account and operations workspace.',
    robots: PRIVATE_ROBOTS,
    canonical: null,
    indexable: false,
  }
}
