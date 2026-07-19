import { Tag } from 'antd'
import type { StationStatus } from '../../types/station'

const statusConfig: Record<StationStatus, { color: string; label: string }> = {
  available: { color: 'success', label: 'Available' },
  charging: { color: 'purple', label: 'Charging' },
  faulted: { color: 'error', label: 'Faulted' },
  offline: { color: 'default', label: 'Offline' },
  maintenance: { color: 'warning', label: 'Maintenance' },
  reserved: { color: 'geekblue', label: 'Reserved' },
  unavailable: { color: 'orange', label: 'Unavailable' },
}

export function StationStatusTag({ status }: { status: StationStatus }) {
  const config = statusConfig[status]
  return <Tag color={config.color} className="station-status-tag"><span className="station-status-dot" />{config.label}</Tag>
}
