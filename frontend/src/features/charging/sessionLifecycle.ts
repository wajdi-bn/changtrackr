import type { ChargingSession } from '../../types/charging'

const activeStatuses = new Set(['pending', 'charging', 'stopping'])
const terminalStatuses = new Set(['completed', 'interrupted', 'failed', 'cancelled'])

export function isChargingSessionActive(session: ChargingSession): boolean {
  return activeStatuses.has(session.status)
}

export function isChargingSessionTerminal(session: ChargingSession): boolean {
  return terminalStatuses.has(session.status)
}

export function resolveTrackedSession(
  activeSession: ChargingSession | null,
  latestSession: ChargingSession | null,
  trackedSessionId: number | null,
): ChargingSession | null {
  if (activeSession) return activeSession
  if (trackedSessionId !== null && latestSession?.id === trackedSessionId) return latestSession
  return null
}

export function shouldPresentCompletion(
  latestSession: ChargingSession | null,
  trackedSessionId: number | null,
  presentedSessionId: number | null,
): boolean {
  return Boolean(
    latestSession
    && latestSession.id === trackedSessionId
    && latestSession.id !== presentedSessionId
    && isChargingSessionTerminal(latestSession),
  )
}
