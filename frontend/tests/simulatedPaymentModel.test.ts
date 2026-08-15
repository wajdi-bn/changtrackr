import assert from 'node:assert/strict'
import test from 'node:test'
import { paymentMethodLabel, simulatedPaymentFieldNames, toSimulatedPaymentSelection } from '../src/features/payments/simulatedPaymentModel.ts'

test('each simulated payment method validates only its own local detail fields', () => {
  assert.deepEqual(simulatedPaymentFieldNames('simulated_card'), ['cardholder_name', 'card_number', 'card_expiry', 'card_cvc'])
  assert.deepEqual(simulatedPaymentFieldNames('simulated_edinar'), ['edinar_card_number', 'edinar_expiry', 'edinar_code'])
  assert.deepEqual(simulatedPaymentFieldNames('simulated_d17'), ['d17_phone', 'd17_code'])
})

test('payment method labels remain consistent across checkout workflows', () => {
  assert.equal(paymentMethodLabel('simulated_card'), 'Visa / Mastercard')
  assert.equal(paymentMethodLabel('simulated_edinar'), 'e-DINAR Smart')
  assert.equal(paymentMethodLabel('simulated_d17'), 'D17 mobile wallet')
})

test('API selection excludes all browser-only simulated credentials', () => {
  const selection = toSimulatedPaymentSelection({
    method: 'simulated_card',
    simulation_outcome: 'success',
    card_number: '4242424242424242',
    card_cvc: '123',
  } as Parameters<typeof toSimulatedPaymentSelection>[0] & { card_number: string; card_cvc: string })

  assert.deepEqual(selection, { method: 'simulated_card', simulation_outcome: 'success' })
  assert.equal('card_number' in selection, false)
  assert.equal('card_cvc' in selection, false)
})
