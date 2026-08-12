import type { OcppSimulatorActionStatus } from '../../types/station'

export type ClientTerminalState = 'ready' | 'requesting' | 'waiting_ocpp' | 'connected' | 'failed' | 'unavailable'

export function resolveClientTerminalState(
  rawOcppStatus: string | null | undefined,
  actionStatus?: OcppSimulatorActionStatus,
  actionCompletedAt?: string | null,
  now = Date.now(),
): ClientTerminalState {
  if (rawOcppStatus === 'Preparing') return 'connected'
  if (actionStatus === 'failed') return 'failed'
  if (actionStatus === 'succeeded' && actionCompletedAt && now - Date.parse(actionCompletedAt) > 12_000) return 'failed'
  if (actionStatus === 'queued' || actionStatus === 'running' || actionStatus === 'succeeded') return 'waiting_ocpp'
  if (rawOcppStatus === 'Available') return 'ready'
  if (rawOcppStatus == null || rawOcppStatus === 'Waiting') return 'requesting'
  return 'unavailable'
}

export function canInsertVirtualCable(state: ClientTerminalState): boolean {
  return state === 'ready' || state === 'failed'
}
