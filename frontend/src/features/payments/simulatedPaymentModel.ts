import type { PaymentSimulationOutcome, SimulatedPaymentMethod } from '../../types/charging'

export interface SimulatedPaymentSelection {
  method: SimulatedPaymentMethod
  simulation_outcome: PaymentSimulationOutcome
}

export type SimulatedPaymentDetailField =
  | 'cardholder_name'
  | 'card_number'
  | 'card_expiry'
  | 'card_cvc'
  | 'edinar_card_number'
  | 'edinar_expiry'
  | 'edinar_code'
  | 'd17_phone'
  | 'd17_code'

export function simulatedPaymentFieldNames(method?: SimulatedPaymentMethod): SimulatedPaymentDetailField[] {
  if (method === 'simulated_edinar') return ['edinar_card_number', 'edinar_expiry', 'edinar_code']
  if (method === 'simulated_d17') return ['d17_phone', 'd17_code']
  return ['cardholder_name', 'card_number', 'card_expiry', 'card_cvc']
}

export function paymentMethodTitle(method: SimulatedPaymentMethod): string {
  if (method === 'simulated_edinar') return 'e-DINAR sandbox details'
  if (method === 'simulated_d17') return 'D17 sandbox confirmation'
  return 'Bank card sandbox details'
}

export function paymentMethodLabel(method: SimulatedPaymentMethod): string {
  if (method === 'simulated_edinar') return 'e-DINAR Smart'
  if (method === 'simulated_d17') return 'D17 mobile wallet'
  return 'Visa / Mastercard'
}

export function toSimulatedPaymentSelection(values: SimulatedPaymentSelection): SimulatedPaymentSelection {
  return {
    method: values.method,
    simulation_outcome: values.simulation_outcome ?? 'success',
  }
}
