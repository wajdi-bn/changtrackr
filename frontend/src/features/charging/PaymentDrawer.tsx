import { Alert, Button, Drawer, Form, Radio, Select } from 'antd'
import { CreditCard, FlaskConical, LockKeyhole, ShieldCheck, Smartphone, WalletCards } from 'lucide-react'
import type { ChargingSession, PaymentPayload, PaymentSimulationOutcome, SimulatedPaymentMethod } from '../../types/charging'
import { createIdempotencyKey } from '../../lib/idempotency'

interface PaymentDrawerProps {
  open: boolean
  session: ChargingSession | null
  submitting: boolean
  onClose: () => void
  onSubmit: (payload: PaymentPayload) => void
}

export function PaymentDrawer({ open, session, submitting, onClose, onSubmit }: PaymentDrawerProps) {
  const [form] = Form.useForm<{ method: SimulatedPaymentMethod; simulation_outcome: PaymentSimulationOutcome }>()

  return (
    <Drawer
      open={open}
      title="Complete payment"
      size={520}
      onClose={onClose}
      afterOpenChange={(visible) => visible && form.setFieldsValue({ method: 'simulated_card', simulation_outcome: 'success' })}
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
          {session.minimum_adjustment_millimes > 0 && <p><span>Minimum charge adjustment</span><strong>{(session.minimum_adjustment_millimes / 1000).toFixed(3)} TND</strong></p>}
        </div>
        <Form
          form={form}
          layout="vertical"
          requiredMark="optional"
          onFinish={(values) => onSubmit({ ...values, simulation_outcome: values.simulation_outcome ?? 'success', idempotency_key: createIdempotencyKey() })}
        >
          <Form.Item label="Payment method" name="method" rules={[{ required: true }]}>
            <Radio.Group className="payment-checkout-methods">
              <Radio.Button value="simulated_card"><CreditCard size={18} /><span><strong>Bank card</strong><small>Visa or Mastercard adapter</small></span></Radio.Button>
              <Radio.Button value="simulated_edinar"><WalletCards size={18} /><span><strong>e-DINAR</strong><small>Postal payment adapter</small></span></Radio.Button>
              <Radio.Button value="simulated_d17"><Smartphone size={18} /><span><strong>D17 wallet</strong><small>Mobile wallet adapter</small></span></Radio.Button>
            </Radio.Group>
          </Form.Item>
          {import.meta.env.DEV && <Form.Item label="Sandbox scenario" name="simulation_outcome" rules={[{ required: true }]}>
            <Select options={[
              { value: 'success', label: 'Successful payment' },
              { value: 'declined', label: 'Provider decline' },
              { value: 'timeout', label: 'Provider timeout' },
              { value: 'provider_error', label: 'Provider unavailable' },
            ]} />
          </Form.Item>}
          <Alert
            type="info"
            showIcon
            icon={<FlaskConical size={16} />}
            title="Payment simulator"
            description="This checkout follows the future provider flow, but WireMock processes the transaction locally. No credential or real money is transmitted."
          />
          <Button className="payment-submit" type="primary" htmlType="submit" icon={<CreditCard size={16} />} loading={submitting} block>
            Pay {session.total_amount} {session.currency}
          </Button>
          <p className="payment-security-note"><LockKeyhole size={13} />Idempotency protection prevents duplicate payment processing.</p>
        </Form>
      </>}
    </Drawer>
  )
}
