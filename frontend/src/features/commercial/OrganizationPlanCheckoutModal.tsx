import { useEffect, useState } from 'react'
import { Alert, Button, Form, Modal, Radio, Result, Steps } from 'antd'
import { Building2, CheckCircle2, CreditCard, ReceiptText, ShieldCheck, XCircle } from 'lucide-react'
import type { BillingCycle, OrganizationBillingWorkspace, OrganizationInvoice, SaasPlan } from '../../types/commercial'
import { SimulatedPaymentFields, type SimulatedPaymentFormValues } from '../payments/SimulatedPaymentFields'
import { paymentMethodLabel, simulatedPaymentFieldNames } from '../payments/simulatedPaymentModel'

interface OrganizationPlanCheckoutModalProps {
  plan: SaasPlan | null
  organization: OrganizationBillingWorkspace['organization']
  cycle: BillingCycle
  invoice: OrganizationInvoice | null
  submitting: boolean
  onCycleChange: (cycle: BillingCycle) => void
  onClose: () => void
  onConfirm: (payment: SimulatedPaymentFormValues) => void
  onViewInvoice: (invoice: OrganizationInvoice) => void
}

export function OrganizationPlanCheckoutModal({ plan, organization, cycle, invoice, submitting, onCycleChange, onClose, onConfirm, onViewInvoice }: OrganizationPlanCheckoutModalProps) {
  const [form] = Form.useForm<SimulatedPaymentFormValues>()
  const [step, setStep] = useState(0)
  const method = Form.useWatch('method', { form, preserve: true })

  useEffect(() => {
    if (!plan) return
    setStep(0)
    form.resetFields()
    form.setFieldsValue({ method: 'simulated_card', simulation_outcome: 'success' })
  }, [form, plan])

  useEffect(() => {
    if (invoice) setStep(4)
  }, [invoice])

  const amount = plan ? (cycle === 'annual' ? plan.annual_price_millimes : plan.monthly_price_millimes) : 0
  const next = async () => {
    if (step === 2) await form.validateFields(['method', 'simulation_outcome', ...simulatedPaymentFieldNames(method)])
    setStep((value) => Math.min(3, value + 1))
  }

  return <Modal className="organization-checkout-modal" width={820} open={Boolean(plan)} footer={null} onCancel={onClose} title="Organization plan checkout" destroyOnHidden>
    {plan && <Form form={form} layout="vertical" initialValues={{ method: 'simulated_card', simulation_outcome: 'success' }} onFinish={onConfirm}>
      <Steps current={step} size="small" items={[{ title: 'Plan' }, { title: 'Billing' }, { title: 'Payment' }, { title: 'Review' }, { title: 'Result' }]} />
      <div className="organization-checkout-stage">
        {step === 0 && <section className="organization-checkout-plan"><span className="checkout-stage-icon"><ShieldCheck size={22} /></span><small>COMMERCIAL PLAN</small><h2>{plan.name}</h2><p>{plan.description}</p><Radio.Group value={cycle} optionType="button" buttonStyle="solid" onChange={(event) => onCycleChange(event.target.value)} options={[{ value: 'monthly', label: 'Monthly' }, { value: 'annual', label: 'Annual' }]} /><strong>{formatMoney(amount)}<small>/{cycle === 'annual' ? 'year' : 'month'}</small></strong><div><span>{plan.max_stations ?? 'Unlimited'} stations</span><span>{plan.max_employees ?? 'Unlimited'} employees</span></div></section>}
        {step === 1 && <section className="organization-checkout-billing"><header><span className="checkout-stage-icon"><Building2 size={22} /></span><div><small>BILLING IDENTITY</small><h2>Verify the organization</h2></div></header><p>These organization details identify the payer on the commercial invoice. Payment fields remain separate.</p><dl><div><dt>Legal or workspace name</dt><dd>{organization.name}</dd></div><div><dt>Billing contact</dt><dd>{organization.contact_email || 'Not configured'}</dd></div><div><dt>Invoice currency</dt><dd>TND</dd></div><div><dt>Billing cycle</dt><dd>{cycle === 'annual' ? 'Annual' : 'Monthly'}</dd></div></dl><Alert type="info" showIcon title="Organization purchase" description="The administrator confirms this payment on behalf of the organization. It is not a driver charging payment." /></section>}
        {step === 2 && <section className="organization-checkout-payment"><header><span className="checkout-stage-icon"><CreditCard size={22} /></span><div><small>PAYMENT SANDBOX</small><h2>Enter payment details</h2></div></header><SimulatedPaymentFields method={method} scenarioLabel="Simulator outcome" compact /></section>}
        {step === 3 && <section className="organization-checkout-review"><header><span className="checkout-stage-icon"><ReceiptText size={22} /></span><div><small>FINAL REVIEW</small><h2>Confirm activation</h2></div></header><dl><div><dt>Organization</dt><dd>{organization.name}</dd></div><div><dt>Plan</dt><dd>{plan.name}</dd></div><div><dt>Cycle</dt><dd>{cycle === 'annual' ? 'Annual' : 'Monthly'}</dd></div><div><dt>Payment</dt><dd>{paymentMethodLabel(method ?? 'simulated_card')}</dd></div><div><dt>Amount</dt><dd>{formatMoney(amount)}</dd></div></dl><Alert type="success" showIcon title="Automatic activation" description="A successful simulator response activates the plan immediately and creates a paid invoice. A failure keeps the current trial or grace state unchanged." /></section>}
        {step === 4 && invoice && <Result status={invoice.status === 'paid' ? 'success' : 'error'} icon={invoice.status === 'paid' ? <CheckCircle2 /> : <XCircle />} title={invoice.status === 'paid' ? `${plan.name} is active` : 'The simulated payment failed'} subTitle={invoice.status === 'paid' ? `Invoice ${invoice.number} was paid and the organization plan is active.` : invoice.failure_reason ?? `Invoice ${invoice.number} records the failed attempt; your previous access state is unchanged.`} extra={[<Button key="close" onClick={onClose}>Close</Button>, <Button key="invoice" type="primary" onClick={() => onViewInvoice(invoice)}>View invoice</Button>]} />}
      </div>
      {step < 4 && <footer className="organization-checkout-actions"><Button onClick={step === 0 ? onClose : () => setStep((value) => Math.max(0, value - 1))}>{step === 0 ? 'Cancel' : 'Back'}</Button>{step < 3 ? <Button type="primary" onClick={() => void next()}>Continue</Button> : <Button type="primary" htmlType="submit" loading={submitting}>Pay {formatMoney(amount)} and activate</Button>}</footer>}
    </Form>}
  </Modal>
}

function formatMoney(value: number): string {
  return new Intl.NumberFormat('en-TN', { style: 'currency', currency: 'TND', minimumFractionDigits: 3 }).format(value / 1000)
}
