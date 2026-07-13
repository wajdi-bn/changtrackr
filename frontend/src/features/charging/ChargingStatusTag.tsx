import { Tag } from 'antd'
import type { ChargingSessionStatus, PaymentStatus, SessionPaymentStatus } from '../../types/charging'

const colors: Record<string, string> = {
  charging: 'purple',
  completed: 'green',
  cancelled: 'default',
  unpaid: 'gold',
  paid: 'green',
  failed: 'red',
  pending: 'blue',
}

export function ChargingStatusTag({ value }: { value: ChargingSessionStatus | SessionPaymentStatus | PaymentStatus }) {
  return <Tag className="charging-status-tag" color={colors[value]}><span />{value}</Tag>
}
