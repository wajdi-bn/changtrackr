import type { DemoObjective } from '../../types/demoRequest'

export const demoObjectiveOptions: Array<{ value: DemoObjective; label: string }> = [
  { value: 'availability_monitoring', label: 'Monitor station availability and detect outages' },
  { value: 'remote_supervision', label: 'Supervise stations and connectors remotely' },
  { value: 'maintenance_coordination', label: 'Coordinate incidents, interventions and maintenance' },
  { value: 'charging_activity', label: 'Track charging activity and energy consumption' },
  { value: 'team_access', label: 'Manage operators, technicians and customer access' },
  { value: 'ocpp_onboarding', label: 'Integrate and onboard OCPP-compatible stations' },
  { value: 'performance_uptime', label: 'Analyze network performance and improve uptime' },
]

export const demoObjectiveLabels = Object.fromEntries(
  demoObjectiveOptions.map((option) => [option.value, option.label]),
) as Record<DemoObjective, string>
