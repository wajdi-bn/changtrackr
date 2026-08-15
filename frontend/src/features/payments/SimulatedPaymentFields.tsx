import { Alert, Form, Input, Radio, Select } from 'antd'
import { FlaskConical, ShieldCheck } from 'lucide-react'
import type { PaymentSimulationOutcome, SimulatedPaymentMethod } from '../../types/charging'
import { PaymentMethodBrand } from '../charging/PaymentMethodBrand'
import { paymentMethodTitle } from './simulatedPaymentModel'

export interface SimulatedPaymentFormValues {
  method: SimulatedPaymentMethod
  simulation_outcome: PaymentSimulationOutcome
  cardholder_name?: string
  card_number?: string
  card_expiry?: string
  card_cvc?: string
  edinar_card_number?: string
  edinar_expiry?: string
  edinar_code?: string
  d17_phone?: string
  d17_code?: string
}

interface SimulatedPaymentFieldsProps {
  method?: SimulatedPaymentMethod
  scenarioLabel?: string
  compact?: boolean
}

export function SimulatedPaymentFields({ method, scenarioLabel = 'External sandbox result', compact = false }: SimulatedPaymentFieldsProps) {
  const selectedMethod = method ?? 'simulated_card'

  return <div className={`simulated-payment-fields${compact ? ' is-compact' : ''}`}>
    <Form.Item label="Payment method" name="method" rules={[{ required: true }]}>
      <Radio.Group className="payment-method-grid">
        <Radio.Button value="simulated_card"><PaymentMethodBrand method="simulated_card" /><span><strong>Bank card</strong><small>Visa or Mastercard sandbox</small></span></Radio.Button>
        <Radio.Button value="simulated_edinar"><PaymentMethodBrand method="simulated_edinar" /><span><strong>e-DINAR</strong><small>Postal card sandbox</small></span></Radio.Button>
        <Radio.Button value="simulated_d17"><PaymentMethodBrand method="simulated_d17" /><span><strong>D17</strong><small>Mobile wallet sandbox</small></span></Radio.Button>
      </Radio.Group>
    </Form.Item>
    <div className="payment-details-panel">
      <header><span><ShieldCheck size={18} /></span><div><strong>{paymentMethodTitle(selectedMethod)}</strong><small>Sandbox fields are validated locally and never included in the API request.</small></div></header>
      {selectedMethod === 'simulated_card' && <div className="payment-details-grid">
        <Form.Item className="is-wide" label="Sandbox cardholder" name="cardholder_name" preserve={false} rules={[{ required: true, message: 'Enter the sandbox cardholder name' }]}><Input autoComplete="off" placeholder="Demo customer" /></Form.Item>
        <Form.Item className="is-wide" label="Sandbox card number" name="card_number" preserve={false} rules={[{ required: true }, { pattern: /^\d{16}$/, message: 'Enter 16 sandbox digits' }]}><Input inputMode="numeric" autoComplete="off" maxLength={16} placeholder="4242424242424242" /></Form.Item>
        <Form.Item label="Expiry" name="card_expiry" preserve={false} rules={[{ required: true }, { pattern: /^(0[1-9]|1[0-2])\/\d{2}$/, message: 'Use MM/YY' }]}><Input inputMode="numeric" autoComplete="off" maxLength={5} placeholder="12/30" /></Form.Item>
        <Form.Item label="Demo CVC" name="card_cvc" preserve={false} rules={[{ required: true }, { pattern: /^\d{3}$/, message: 'Enter 3 sandbox digits' }]}><Input.Password inputMode="numeric" autoComplete="off" maxLength={3} placeholder="123" /></Form.Item>
      </div>}
      {selectedMethod === 'simulated_edinar' && <div className="payment-details-grid">
        <Form.Item className="is-wide" label="e-DINAR sandbox card number" name="edinar_card_number" preserve={false} rules={[{ required: true }, { pattern: /^\d{16}$/, message: 'Enter 16 sandbox digits' }]}><Input inputMode="numeric" autoComplete="off" maxLength={16} placeholder="5359400000000000" /></Form.Item>
        <Form.Item label="Expiry" name="edinar_expiry" preserve={false} rules={[{ required: true }, { pattern: /^(0[1-9]|1[0-2])\/\d{2}$/, message: 'Use MM/YY' }]}><Input inputMode="numeric" autoComplete="off" maxLength={5} placeholder="12/30" /></Form.Item>
        <Form.Item label="Demo verification code" name="edinar_code" preserve={false} rules={[{ required: true }, { pattern: /^\d{4}$/, message: 'Enter 4 sandbox digits' }]}><Input.Password inputMode="numeric" autoComplete="off" maxLength={4} placeholder="0000" /></Form.Item>
      </div>}
      {selectedMethod === 'simulated_d17' && <div className="payment-details-grid">
        <Form.Item className="is-wide" label="D17 sandbox mobile number" name="d17_phone" preserve={false} rules={[{ required: true }, { pattern: /^\+216\d{8}$/, message: 'Use +216 followed by 8 digits' }]}><Input inputMode="tel" autoComplete="off" placeholder="+21620123456" /></Form.Item>
        <Form.Item className="is-wide" label="Demo confirmation code" name="d17_code" preserve={false} rules={[{ required: true }, { pattern: /^\d{6}$/, message: 'Enter 6 sandbox digits' }]}><Input.Password inputMode="numeric" autoComplete="off" maxLength={6} placeholder="000000" /></Form.Item>
      </div>}
    </div>
    {import.meta.env.DEV && <Form.Item label={scenarioLabel} name="simulation_outcome" rules={[{ required: true }]}><Select options={[
      { value: 'success', label: 'Authorize successfully' },
      { value: 'declined', label: 'Provider decline' },
      { value: 'timeout', label: 'Provider timeout' },
      { value: 'provider_error', label: 'Provider unavailable' },
    ]} /></Form.Item>}
    <Alert type="info" showIcon icon={<FlaskConical size={16} />} title="Simulation only" description="Use only demo values. ChargeTrackr does not send these fields to the backend or to a real payment provider." />
  </div>
}
