import { Tag } from 'antd'
import type { ChargingSessionStatus, PaymentStatus, SessionPaymentStatus } from '../../types/charging'

const colors: Record<string, string> = {
  charging: 'purple',
  stopping: 'blue',
  completed: 'green',
  interrupted: 'orange',
  cancelled: 'default',
  unpaid: 'gold',
  authorized: 'cyan',
  paid: 'green',
  failed: 'red',
  pending: 'blue',
}

export function ChargingStatusTag({ value }: { value: ChargingSessionStatus | SessionPaymentStatus | PaymentStatus }) {
  return <Tag className="charging-status-tag" color={colors[value]}><span />{value}</Tag>
}
