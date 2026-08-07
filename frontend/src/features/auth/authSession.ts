type SessionExpiredListener = () => void

const listeners = new Set<SessionExpiredListener>()
let expirationNotified = false

export function subscribeToSessionExpiration(listener: SessionExpiredListener): () => void {
  listeners.add(listener)
  return () => listeners.delete(listener)
}

export function notifySessionExpired(): void {
  if (expirationNotified) return

  expirationNotified = true
  listeners.forEach((listener) => listener())
}

export function markSessionActive(): void {
  expirationNotified = false
}
