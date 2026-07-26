import { useEffect, useState } from 'react'
import { Button, Modal, Popconfirm } from 'antd'
import { motion } from 'framer-motion'
import { BatteryCharging, Clock3, Gauge, Radio, Square, Zap } from 'lucide-react'
import type { ChargingSession } from '../../types/charging'

interface ActiveSessionModalProps {
  open: boolean
  session: ChargingSession | null
  stopping: boolean
  onClose: () => void
  onStop: (session: ChargingSession) => void
}

export function ActiveSessionModal({ open, session, stopping, onClose, onStop }: ActiveSessionModalProps) {
  const [now, setNow] = useState(() => Date.now())

  useEffect(() => {
    if (!open) return
    setNow(Date.now())
    const interval = window.setInterval(() => setNow(Date.now()), 1000)

    return () => window.clearInterval(interval)
  }, [open])

  if (!session) return null

  const elapsedSeconds = Math.max(0, Math.floor((now - new Date(session.started_at).getTime()) / 1000))
  const elapsedMinutes = Math.floor(elapsedSeconds / 60)
  const elapsedRemainder = elapsedSeconds % 60

  return (
    <Modal
      className="active-charging-modal"
      open={open}
      centered
      width={680}
      title={null}
      onCancel={onClose}
      footer={(
        <div className="active-charging-modal-actions">
          <Button onClick={onClose}>Continue in background</Button>
          <Popconfirm
            title="Stop charging now?"
            description={session.source === 'ocpp' ? 'A secure RemoteStopTransaction command will be sent to the station.' : 'The final energy and amount will be calculated.'}
            okText="Stop session"
            okButtonProps={{ danger: true }}
            onConfirm={() => onStop(session)}
          >
            <Button danger icon={<Square size={14} />} loading={stopping}>Stop charging</Button>
          </Popconfirm>
        </div>
      )}
    >
      <div className="active-charging-modal-layout">
        <section className="active-charging-visual" aria-label="Charging in progress">
          <span className="active-charging-live"><Radio size={13} />Live</span>
          <div className="active-charging-orbit">
            <span />
            <span />
            <motion.img
              src="/assets/charger-terra-hp-150.png"
              alt="Charging station delivering energy"
              animate={{ y: [0, -5, 0] }}
              transition={{ duration: 2.4, repeat: Infinity, ease: 'easeInOut' }}
            />
            <motion.div
              className="active-charging-bolt"
              animate={{ scale: [1, 1.12, 1], opacity: [0.75, 1, 0.75] }}
              transition={{ duration: 1.2, repeat: Infinity }}
            >
              <Zap size={22} />
            </motion.div>
          </div>
          <p>The station is sending OCPP measurements while your vehicle charges.</p>
        </section>

        <section className="active-charging-details">
          <div className="active-charging-heading">
            <span><BatteryCharging size={16} />Charging now</span>
            <h2>{session.station.name}</h2>
            <p>Connector {session.connector.external_id} - {session.connector.type ?? 'EV connector'}</p>
          </div>
          <div className="active-charging-live-metrics">
            <article><Clock3 size={17} /><span><small>Elapsed</small><strong>{elapsedMinutes}:{String(elapsedRemainder).padStart(2, '0')}</strong></span></article>
            <article><Zap size={17} /><span><small>Energy</small><strong>{session.energy_kwh.toFixed(3)} kWh</strong></span></article>
            <article><Gauge size={17} /><span><small>Power</small><strong>{session.current_power_kw !== null ? `${session.current_power_kw.toFixed(1)} kW` : 'Waiting'}</strong></span></article>
            <article><span className="metric-currency">TND</span><span><small>Current estimate</small><strong>{session.total_amount}</strong></span></article>
          </div>
          <div className="active-charging-signal">
            <span className="connector-detection-pulse" />
            <div><strong>Live measurements enabled</strong><small>{session.last_meter_value_at ? `Last station signal at ${new Date(session.last_meter_value_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' })}` : 'Waiting for the first MeterValues signal'}</small></div>
          </div>
          <div className="active-charging-pricing">
            <span><small>Tariff</small><strong>{session.tariff.name}</strong></span>
            <span><small>Payment</small><strong>{session.payment_status.replaceAll('_', ' ')}</strong></span>
            {session.plan && <span><small>Plan</small><strong>{session.plan.name}</strong></span>}
          </div>
        </section>
      </div>
    </Modal>
  )
}
