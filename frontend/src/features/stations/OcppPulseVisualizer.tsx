import dayjs from 'dayjs'
import { Activity, Cable, HeartPulse, Radio, Server, Wifi, WifiOff, Zap } from 'lucide-react'
import type { OcppSimulatorConsoleResponse, Station } from '../../types/station'
import { connectorEvents, signalLabel, signalTone } from './simulationLab'

export function OcppPulseVisualizer({ station, snapshot }: { station: Station; snapshot?: OcppSimulatorConsoleResponse }) {
  const state = snapshot?.state
  const events = snapshot?.signals.events ?? []
  const latestEvent = events.at(-1)
  const pulseKey = latestEvent?.id ?? 'idle'

  return (
    <section className="lab-pulse-panel">
      <header className="lab-section-heading">
        <div>
          <span>LIVE SIGNAL MAP</span>
          <h2>Station and connector pulses</h2>
          <p>Each moving pulse represents a sanitized OCPP event received from the simulated charge point.</p>
        </div>
        <div className={`lab-live-badge ${state?.connected ? 'is-live' : ''}`}>
          {state?.connected ? <Wifi size={17} /> : <WifiOff size={17} />}
          {state?.connected ? 'Live link' : 'Link offline'}
        </div>
      </header>

      <div className="lab-pulse-canvas">
        <div className="lab-pulse-node lab-pulse-node--gateway">
          <span><Server size={23} /></span>
          <strong>OCPP gateway</strong>
          <small>{snapshot?.adapter.available ? 'Adapter operational' : 'Adapter unavailable'}</small>
        </div>

        <div className={`lab-signal-line lab-signal-line--main ${state?.connected ? 'is-active' : ''}`}>
          {latestEvent && <i key={pulseKey} className={`lab-signal-packet is-${signalTone(latestEvent.category, latestEvent.status, latestEvent.error_code)}`} />}
        </div>

        <div className="lab-pulse-node lab-pulse-node--station">
          <span><Radio size={25} /></span>
          <strong>{station.name}</strong>
          <small>{station.ocpp_identity}</small>
          <em>{snapshot?.signals.recent_count ?? 0} signals / min</em>
        </div>

        <div className="lab-connector-branches">
          {station.connectors.map((connector, index) => {
            const ocppId = connector.ocpp_connector_id ?? 0
            const connectorState = state?.connectors.find((item) => item.connector_id === ocppId)
            const connectorSignal = connectorEvents(events, ocppId).at(-1)
            const tone = signalTone('status', connectorState?.status, connectorState?.error_code)
            return (
              <article key={connector.id} className={`lab-connector-node is-${tone}`}>
                <div className="lab-connector-line">
                  {connectorSignal && <i key={`${connectorSignal.id}-${index}`} className={`lab-connector-packet is-${signalTone(connectorSignal.category, connectorSignal.status, connectorSignal.error_code)}`} />}
                </div>
                <span><Cable size={19} /></span>
                <div>
                  <strong>{connector.external_id} - {connector.type}</strong>
                  <small>{connectorState?.status ?? connector.ocpp_status ?? 'Awaiting signal'}</small>
                </div>
                {connectorState?.transaction_started && <em><Zap size={12} /> Charging</em>}
              </article>
            )
          })}
        </div>
      </div>

      <div className="lab-signal-feed">
        <header><strong>Protocol stream</strong><small>{events.length} recent events, bounded by the API</small></header>
        <div>
          {[...events].reverse().slice(0, 12).map((event) => (
            <article key={event.id}>
              <span className={`lab-feed-icon is-${signalTone(event.category, event.status, event.error_code)}`}>
                {event.category === 'heartbeat' ? <HeartPulse size={15} /> : event.category === 'meter' ? <Activity size={15} /> : <Radio size={15} />}
              </span>
              <div><strong>{signalLabel(event)}</strong><small>{event.connector_id ? `Connector ${event.connector_id}` : 'Station'} - {event.processing_status}</small></div>
              <time>{event.occurred_at ? dayjs(event.occurred_at).format('HH:mm:ss') : '--:--:--'}</time>
            </article>
          ))}
          {events.length === 0 && <p className="lab-empty-stream">No OCPP event has been received for this station yet.</p>}
        </div>
      </div>
    </section>
  )
}
