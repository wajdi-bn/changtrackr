import { useEffect } from 'react'
import { useLocation } from 'react-router-dom'
import {
  PUBLIC_STRUCTURED_DATA,
  SITE_TITLE,
  SITE_URL,
  SOCIAL_IMAGE_URL,
  resolveSeoConfig,
} from './seoConfig'

const OPEN_GRAPH_META = [
  ['og:type', 'website'],
  ['og:site_name', 'ChargeTrackr'],
  ['og:locale', 'en_US'],
  ['og:url', `${SITE_URL}/`],
  ['og:title', SITE_TITLE],
  ['og:image', SOCIAL_IMAGE_URL],
  ['og:image:secure_url', SOCIAL_IMAGE_URL],
  ['og:image:type', 'image/webp'],
  ['og:image:width', '1200'],
  ['og:image:height', '630'],
  ['og:image:alt', 'Electric vehicle charging at a ChargeTrackr-managed station'],
] as const

const TWITTER_META = [
  ['twitter:card', 'summary_large_image'],
  ['twitter:title', SITE_TITLE],
  ['twitter:image', SOCIAL_IMAGE_URL],
  ['twitter:image:alt', 'Electric vehicle charging at a ChargeTrackr-managed station'],
] as const

export function SeoRouteController() {
  const { pathname } = useLocation()

  useEffect(() => {
    const config = resolveSeoConfig(pathname)
    document.title = config.title
    setMeta('name', 'description', config.description)
    setMeta('name', 'robots', config.robots)
    setMeta('name', 'googlebot', config.robots)

    if (config.indexable && config.canonical) {
      setCanonical(config.canonical)
      for (const [property, content] of OPEN_GRAPH_META) setMeta('property', property, content)
      setMeta('property', 'og:description', config.description)
      for (const [name, content] of TWITTER_META) setMeta('name', name, content)
      setMeta('name', 'twitter:description', config.description)
      setStructuredData()
      return
    }

    document.head.querySelector('link[rel="canonical"]')?.remove()
    for (const [property] of OPEN_GRAPH_META) removeMeta('property', property)
    removeMeta('property', 'og:description')
    for (const [name] of TWITTER_META) removeMeta('name', name)
    removeMeta('name', 'twitter:description')
    document.getElementById('chargetrackr-structured-data')?.remove()
  }, [pathname])

  return null
}

function setMeta(attribute: 'name' | 'property', key: string, content: string) {
  let element = document.head.querySelector<HTMLMetaElement>(`meta[${attribute}="${key}"]`)
  if (!element) {
    element = document.createElement('meta')
    element.setAttribute(attribute, key)
    document.head.append(element)
  }
  element.content = content
}

function removeMeta(attribute: 'name' | 'property', key: string) {
  document.head.querySelector(`meta[${attribute}="${key}"]`)?.remove()
}

function setCanonical(href: string) {
  let element = document.head.querySelector<HTMLLinkElement>('link[rel="canonical"]')
  if (!element) {
    element = document.createElement('link')
    element.rel = 'canonical'
    document.head.append(element)
  }
  element.href = href
}

function setStructuredData() {
  let element = document.getElementById('chargetrackr-structured-data') as HTMLScriptElement | null
  if (!element) {
    element = document.createElement('script')
    element.id = 'chargetrackr-structured-data'
    element.type = 'application/ld+json'
    document.head.append(element)
  }
  element.text = JSON.stringify(PUBLIC_STRUCTURED_DATA)
}
