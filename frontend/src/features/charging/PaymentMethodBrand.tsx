import type { SimulatedPaymentMethod } from '../../types/charging'

const paymentAssetRoot = '/assets/brands/payments'

export function PaymentMethodBrand({ method }: { method: SimulatedPaymentMethod }) {
  if (method === 'simulated_d17') {
    return <span className="payment-brand payment-brand--d17"><img src={`${paymentAssetRoot}/d17.png`} alt="D17" /></span>
  }

  if (method === 'simulated_edinar') {
    return <span className="payment-brand payment-brand--edinar">
      <img src={`${paymentAssetRoot}/poste-tunisienne.gif`} alt="La Poste Tunisienne" />
      <strong>e-DINAR</strong>
    </span>
  }

  return <span className="payment-brand payment-brand--card">
    <img src={`${paymentAssetRoot}/visa.png`} alt="Visa" />
    <img src={`${paymentAssetRoot}/mastercard.png`} alt="Mastercard" />
  </span>
}
