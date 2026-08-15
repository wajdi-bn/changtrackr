import { Button, Drawer, Form } from 'antd'
import { CreditCard, LockKeyhole, ShieldCheck } from 'lucide-react'
import type { ChargingSession, PaymentPayload } from '../../types/charging'
import { createIdempotencyKey } from '../../lib/idempotency'
import { SimulatedPaymentFields, type SimulatedPaymentFormValues } from '../payments/SimulatedPaymentFields'
import { toSimulatedPaymentSelection } from '../payments/simulatedPaymentModel'

interface PaymentDrawerProps {
  open: boolean
  session: ChargingSession | null
  submitting: boolean
  onClose: () => void
  onSubmit: (payload: PaymentPayload) => void
}

export function PaymentDrawer({ open, session, submitting, onClose, onSubmit }: PaymentDrawerProps) {
  const [form] = Form.useForm<SimulatedPaymentFormValues>()
  const paymentMethod = Form.useWatch('method', { form, preserve: true })

  return (
    <Drawer
      open={open}
      title="Complete payment"
      size={520}
      onClose={onClose}
      afterOpenChange={(visible) => {
        if (!visible) return
        form.resetFields()
        form.setFieldsValue({ method: 'simulated_card', simulation_outcome: 'success' })
      }}
    >
      {session && <>
        <div className="payment-checkout-heading">
          <span><ShieldCheck size={19} /></span>
          <div><strong>Review and confirm</strong><p>Choose how to settle this completed charging session.</p></div>
          <b>{session.total_amount} {session.currency}</b>
        </div>
        <div className="payment-summary-card">
          <div><small>Session</small><strong>{session.reference}</strong></div>
          <div><small>Station</small><strong>{session.station.name}</strong></div>
          <div><small>Energy</small><strong>{session.energy_kwh.toFixed(3)} kWh</strong></div>
          <div className="payment-total"><small>Total</small><strong>{session.total_amount} {session.currency}</strong></div>
        </div>
        <div className="invoice-breakdown">
          <header><span>Pricing breakdown</span><strong>{session.tariff.name}</strong></header>
          <p><span>Energy · {session.energy_kwh.toFixed(3)} kWh × {(session.price_per_kwh_millimes / 1000).toFixed(3)} TND</span><strong>{(session.energy_cost_millimes / 1000).toFixed(3)} TND</strong></p>
          <p><span>Session fee</span><strong>{(session.session_fee_millimes / 1000).toFixed(3)} TND</strong></p>
          {session.idle_fee_millimes > 0 && <p><span>Idle fee · {session.idle_minutes} min after grace period</span><strong>{(session.idle_fee_millimes / 1000).toFixed(3)} TND</strong></p>}
          {session.minimum_adjustment_millimes > 0 && <p><span>Minimum charge adjustment</span><strong>{(session.minimum_adjustment_millimes / 1000).toFixed(3)} TND</strong></p>}
        </div>
        <Form
          form={form}
          layout="vertical"
          requiredMark="optional"
          onFinish={(values) => onSubmit({ ...toSimulatedPaymentSelection(values), idempotency_key: createIdempotencyKey() })}
        >
          <SimulatedPaymentFields method={paymentMethod} scenarioLabel="Sandbox scenario" compact />
          <Button className="payment-submit" type="primary" htmlType="submit" icon={<CreditCard size={16} />} loading={submitting} block>
            Pay {session.total_amount} {session.currency}
          </Button>
          <p className="payment-security-note"><LockKeyhole size={13} />Idempotency protection prevents duplicate payment processing.</p>
        </Form>
      </>}
    </Drawer>
  )
}
