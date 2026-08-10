import type { SimulatedPaymentMethod } from '../../types/charging'

const paymentAssetRoot = '/assets/payments/providers'

export function PaymentMethodBrand({ method }: { method: SimulatedPaymentMethod }) {
  if (method === 'simulated_d17') {
    return <span className="payment-brand payment-brand--d17"><img src={`${paymentAssetRoot}/d17.png`} alt="D17" width={152} height={97} /></span>
  }

  if (method === 'simulated_edinar') {
    return <span className="payment-brand payment-brand--edinar">
      <img src={`${paymentAssetRoot}/poste-tunisienne.gif`} alt="La Poste Tunisienne" width={121} height={121} />
      <strong>e-DINAR</strong>
    </span>
  }

  return <span className="payment-brand payment-brand--card">
    <img src={`${paymentAssetRoot}/visa.png`} alt="Visa" width={208} height={68} />
    <img src={`${paymentAssetRoot}/mastercard.png`} alt="Mastercard" width={164} height={108} />
  </span>
}
