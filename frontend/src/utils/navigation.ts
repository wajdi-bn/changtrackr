const internalOrigin = 'https://chargetrackr.invalid'

export function safeInternalPath(value: string | null | undefined): string | null {
  if (!value?.startsWith('/') || value.startsWith('//')) return null

  try {
    const url = new URL(value, internalOrigin)

    if (url.origin !== internalOrigin) return null

    return `${url.pathname}${url.search}${url.hash}`
  } catch {
    return null
  }
}
