import { Tag } from 'antd'
import type { AlertSeverity, AlertStatus, InterventionStatus } from '../../types/operations'

type WorkflowValue = AlertSeverity | AlertStatus | InterventionStatus

const config: Record<WorkflowValue, { color: string; label: string }> = {
  critical: { color: 'error', label: 'Critical' },
  warning: { color: 'warning', label: 'Warning' },
  info: { color: 'processing', label: 'Info' },
  new: { color: 'purple', label: 'New' },
  assigned: { color: 'purple', label: 'Assigned' },
  'in-progress': { color: 'processing', label: 'In progress' },
  paused: { color: 'default', label: 'Paused' },
  'waiting-parts': { color: 'warning', label: 'Waiting parts' },
  resolved: { color: 'success', label: 'Resolved' },
}

export function WorkflowTag({ value }: { value: WorkflowValue }) {
  const item = config[value]
  return <Tag color={item.color} className="workflow-tag"><span />{item.label}</Tag>
}
