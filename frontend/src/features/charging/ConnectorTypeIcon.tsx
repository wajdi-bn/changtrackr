import { Chademo, IEC62196T2, IEC62196T2Combo } from 'react-charging-station-connector-icons'
import type { ConnectorType } from '../../types/station'

export function ConnectorTypeIcon({ type, subtitled = false }: { type: ConnectorType; subtitled?: boolean }) {
  const Icon = type === 'CCS2' ? IEC62196T2Combo : type === 'CHAdeMO' ? Chademo : IEC62196T2

  return <span className="connector-type-icon" aria-hidden="true"><Icon variant="light" subtitled={subtitled} /></span>
}
