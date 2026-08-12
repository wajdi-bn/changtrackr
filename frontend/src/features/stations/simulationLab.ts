import type { OcppSimulatorSignalCategory, OcppSimulatorSignalEvent } from '../../types/station'

export type SignalTone = 'success' | 'warning' | 'danger' | 'neutral'

const actionLabels: Record<string, string> = {
  ConnectionOpened: 'WebSocket opened',
  ConnectionClosed: 'WebSocket closed',
  BootNotification: 'Boot registered',
  Heartbeat: 'Heartbeat received',
  StatusNotification: 'Connector status',
  StartTransaction: 'Transaction started',
  MeterValues: 'Meter values received',
  StopTransaction: 'Transaction stopped',
  Authorize: 'Identifier authorized',
}

export function signalLabel(event: Pick<OcppSimulatorSignalEvent, 'action' | 'status'>): string {
  const label = actionLabels[event.action] ?? event.action.replace(/([a-z])([A-Z])/g, '$1 $2')
  return event.status ? `${label}: ${event.status}` : label
}

export function signalTone(category: OcppSimulatorSignalCategory, status?: string | null, errorCode?: string | null): SignalTone {
  if (errorCode && errorCode !== 'NoError') return 'danger'
  if (status === 'Faulted' || status === 'Unavailable') return 'danger'
  if (status === 'Preparing' || status === 'Finishing' || category === 'transaction') return 'warning'
  if (category === 'heartbeat' || category === 'meter' || status === 'Available') return 'success'
  return 'neutral'
}

export function connectorEvents(events: readonly OcppSimulatorSignalEvent[], connectorId: number): OcppSimulatorSignalEvent[] {
  return events.filter((event) => event.connector_id === connectorId)
}
