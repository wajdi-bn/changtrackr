const CHUNK_RELOAD_STORAGE_KEY = 'chargetrackr:last-chunk-reload'
const CHUNK_RELOAD_COOLDOWN_MS = 30_000

export const shouldReloadAfterChunkFailure = (
  lastReloadValue: string | null,
  now: number,
  cooldownMs = CHUNK_RELOAD_COOLDOWN_MS,
) => {
  if (lastReloadValue === null) {
    return true
  }

  const lastReloadAt = Number(lastReloadValue)

  return !Number.isFinite(lastReloadAt) || now - lastReloadAt > cooldownMs
}

export const installChunkLoadRecovery = (targetWindow: Window = window) => {
  const handlePreloadError = (event: Event) => {
    event.preventDefault()

    const now = Date.now()
    let lastReloadValue: string | null = null

    try {
      lastReloadValue = targetWindow.sessionStorage.getItem(CHUNK_RELOAD_STORAGE_KEY)
    } catch {
      // Storage can be unavailable in privacy-restricted browser contexts.
    }

    if (!shouldReloadAfterChunkFailure(lastReloadValue, now)) {
      return
    }

    try {
      targetWindow.sessionStorage.setItem(CHUNK_RELOAD_STORAGE_KEY, String(now))
    } catch {
      // Reload recovery still works when the timestamp cannot be persisted.
    }

    targetWindow.location.reload()
  }

  targetWindow.addEventListener('vite:preloadError', handlePreloadError)

  return () => targetWindow.removeEventListener('vite:preloadError', handlePreloadError)
}
