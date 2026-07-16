import { Alert, Button, Drawer, Form, Radio, Select } from 'antd'
import { CreditCard, FlaskConical, LockKeyhole } from 'lucide-react'
import type { ChargingSession, PaymentPayload, SimulatedPaymentMethod } from '../../types/charging'

interface PaymentDrawerProps {
  open: boolean
  session: ChargingSession | null
  submitting: boolean
  onClose: () => void
  onSubmit: (payload: PaymentPayload) => void
}

export function PaymentDrawer({ open, session, submitting, onClose, onSubmit }: PaymentDrawerProps) {
  const [form] = Form.useForm<{ method: SimulatedPaymentMethod; simulation_outcome: 'success' | 'declined' }>()

  return (
    <Drawer
      open={open}
      title="Pay charging session"
      size={480}
      onClose={onClose}
      afterOpenChange={(visible) => visible && form.setFieldsValue({ method: 'simulated_card', simulation_outcome: 'success' })}
    >
      {session && <>
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
          onFinish={(values) => onSubmit({ ...values, idempotency_key: crypto.randomUUID() })}
        >
          <Form.Item label="Simulated payment method" name="method" rules={[{ required: true }]}>
            <Select options={[
              { value: 'simulated_card', label: 'Payment card (simulated)' },
              { value: 'simulated_edinar', label: 'e-DINAR (simulated)' },
              { value: 'simulated_d17', label: 'D17 wallet (simulated)' },
            ]} />
          </Form.Item>
          <Form.Item label="Sandbox result" name="simulation_outcome" rules={[{ required: true }]}>
            <Radio.Group optionType="button" buttonStyle="solid" options={[
              { value: 'success', label: 'Successful payment' },
              { value: 'declined', label: 'Simulate decline' },
            ]} />
          </Form.Item>
          <Alert
            type="warning"
            showIcon
            icon={<FlaskConical size={16} />}
            title="Simulation only"
            description="No card number, D17 identifier, credential, or real money is requested or transmitted."
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
