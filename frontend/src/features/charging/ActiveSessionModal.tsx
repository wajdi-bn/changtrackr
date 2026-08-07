import { useEffect, useState } from 'react'
import { Button, Modal, Popconfirm } from 'antd'
import { motion } from 'framer-motion'
import { BatteryCharging, Check, CheckCircle2, Clock3, CreditCard, Gauge, Radio, ReceiptText, Square, Zap } from 'lucide-react'
import type { ChargingSession } from '../../types/charging'
import { isChargingSessionTerminal } from './sessionLifecycle'

interface ActiveSessionModalProps {
  open: boolean
  session: ChargingSession | null
  stopping: boolean
  onClose: () => void
  onStop: (session: ChargingSession) => void
  onPay: (session: ChargingSession) => void
  onViewReceipt: (session: ChargingSession) => void
}

export function ActiveSessionModal({ open, session, stopping, onClose, onStop, onPay, onViewReceipt }: ActiveSessionModalProps) {
  const [now, setNow] = useState(() => Date.now())

  useEffect(() => {
    if (!open) return
    setNow(Date.now())
    const interval = window.setInterval(() => setNow(Date.now()), 1000)

    return () => window.clearInterval(interval)
  }, [open])

  if (!session) return null

  const terminal = isChargingSessionTerminal(session)
  const paid = session.payment_status === 'paid' && session.payment !== null
  const paymentPending = session.payment_status === 'authorized'
  const elapsedUntil = session.ended_at ? new Date(session.ended_at).getTime() : now
  const elapsedSeconds = Math.max(0, Math.floor((elapsedUntil - new Date(session.started_at).getTime()) / 1000))
  const elapsedMinutes = Math.floor(elapsedSeconds / 60)
  const elapsedRemainder = elapsedSeconds % 60

  return (
    <Modal
      className={`active-charging-modal${terminal ? ' active-charging-modal--complete' : ''}`}
      open={open}
      centered
      width={680}
      title={null}
      onCancel={onClose}
      footer={null}
    >
      <div className="active-charging-modal-layout">
        <section className="active-charging-visual" aria-label={terminal ? 'Charging session completed' : 'Charging in progress'}>
          <span className="active-charging-live">{terminal ? <Check size={13} /> : <Radio size={13} />}{terminal ? 'Complete' : 'Live'}</span>
          {terminal ? (
            <motion.div
              className="active-charging-complete-mark"
              initial={{ opacity: 0, scale: 0.55, rotate: -12 }}
              animate={{ opacity: 1, scale: 1, rotate: 0 }}
              transition={{ type: 'spring', stiffness: 220, damping: 16 }}
            >
              <CheckCircle2 size={82} strokeWidth={1.7} />
            </motion.div>
          ) : (
            <div className="active-charging-orbit">
              <span />
              <span />
              <motion.img
                src="/assets/ChatGPT Image 27 juil. 2026, 00_06_12.png"
                alt="Electric vehicle connected to a charging station"
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
          )}
          <p>{terminal ? 'Your vehicle has stopped charging. The final measurements and billing status are ready.' : 'The station is sending OCPP measurements while your vehicle charges.'}</p>
        </section>

        <section className="active-charging-details">
          <div className="active-charging-heading">
            <span>{terminal ? <CheckCircle2 size={16} /> : <BatteryCharging size={16} />}{terminal ? 'Session complete' : session.status === 'stopping' ? 'Stopping safely' : 'Charging now'}</span>
            <h2>{session.station.name}</h2>
            <p>Connector {session.connector.external_id} - {session.connector.type ?? 'EV connector'}</p>
          </div>
          <div className="active-charging-live-metrics">
            <article><Clock3 size={17} /><span><small>Elapsed</small><strong>{elapsedMinutes}:{String(elapsedRemainder).padStart(2, '0')}</strong></span></article>
            <article><Zap size={17} /><span><small>Energy</small><strong>{session.energy_kwh.toFixed(3)} kWh</strong></span></article>
            <article><Gauge size={17} /><span><small>Power</small><strong>{session.current_power_kw !== null ? `${session.current_power_kw.toFixed(1)} kW` : 'Waiting'}</strong></span></article>
            <article><span className="metric-currency">TND</span><span><small>Current estimate</small><strong>{session.total_amount}</strong></span></article>
          </div>
          <div className={`active-charging-signal${terminal ? ' active-charging-signal--complete' : ''}`}>
            {terminal ? <CheckCircle2 size={18} /> : <span className="connector-detection-pulse" />}
            <div>
              <strong>{terminal ? paid ? 'Payment confirmed' : paymentPending ? 'Finalizing payment' : 'Charging measurements finalized' : 'Live measurements enabled'}</strong>
              <small>{terminal ? paid ? 'Your PDF receipt is available.' : paymentPending ? 'The authorized amount is being captured securely.' : 'Review the final amount and complete payment.' : session.last_meter_value_at ? `Last station signal at ${new Date(session.last_meter_value_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' })}` : 'Waiting for the first MeterValues signal'}</small>
            </div>
          </div>
          <div className="active-charging-pricing">
            <span><small>Tariff</small><strong>{session.tariff.name}</strong></span>
            <span><small>Payment</small><strong>{session.payment_status.replaceAll('_', ' ')}</strong></span>
            {session.plan && <span><small>Plan</small><strong>{session.plan.name}</strong></span>}
          </div>
          <div className="active-charging-modal-actions">
            <Button onClick={onClose}>{terminal ? 'Close' : 'Continue in background'}</Button>
            {terminal ? paid ? (
              <Button type="primary" icon={<ReceiptText size={15} />} onClick={() => onViewReceipt(session)}>View receipt</Button>
            ) : paymentPending ? (
              <Button type="primary" icon={<CreditCard size={15} />} loading>Finalizing payment</Button>
            ) : (
              <Button type="primary" icon={<CreditCard size={15} />} onClick={() => onPay(session)}>Complete payment</Button>
            ) : (
              <Popconfirm
                title="Stop charging now?"
                description={session.source === 'ocpp' ? 'A secure RemoteStopTransaction command will be sent to the station.' : 'The final energy and amount will be calculated.'}
                okText="Stop session"
                okButtonProps={{ danger: true }}
                onConfirm={() => onStop(session)}
              >
                <Button danger icon={<Square size={14} />} loading={stopping} disabled={session.status === 'stopping'}>{session.status === 'stopping' ? 'Waiting for station' : 'Stop charging'}</Button>
              </Popconfirm>
            )}
          </div>
        </section>
      </div>
    </Modal>
  )
}
