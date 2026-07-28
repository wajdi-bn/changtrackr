import { App, Button, Modal, Tag } from 'antd'
import { CheckCircle2, Clipboard, ExternalLink, KeyRound, Server, ShieldCheck } from 'lucide-react'
import { useNavigate } from 'react-router-dom'
import type { StationCommissioningResult } from '../../types/station'

interface StationCommissioningResultModalProps {
  result: StationCommissioningResult | null
  mode?: 'created' | 'rotated'
  onClose: () => void
}

export function StationCommissioningResultModal({ result, mode = 'created', onClose }: StationCommissioningResultModalProps) {
  const { message } = App.useApp()
  const navigate = useNavigate()
  const commissioning = result?.commissioning

  async function copy(value: string, label: string) {
    await navigator.clipboard.writeText(value)
    void message.success(`${label} copied.`)
  }

  return (
    <Modal
      className="commissioning-result-modal"
      width={680}
      zIndex={1400}
      open={Boolean(result)}
      closable={false}
      mask={{ closable: false }}
      title={null}
      footer={null}
    >
      {result && commissioning && <>
        <header className="commissioning-result-header">
          <span><CheckCircle2 size={27} /></span>
          <div><Tag color="green">{mode === 'rotated' ? 'Credentials rotated' : 'Station created'}</Tag><h2>{result.data.name}</h2><p>{result.data.reference} · {result.data.connectors.length} connector(s)</p></div>
        </header>

        {commissioning.target === 'external' && (
          <section className="commissioning-secret-panel">
            <div className="commissioning-result-callout"><ShieldCheck size={18} /><div><strong>Save these credentials now</strong><p>The station secret cannot be displayed again. Rotating it later invalidates this one.</p></div></div>
            <Credential label="WebSocket URL" value={commissioning.connection_url} onCopy={() => void copy(commissioning.connection_url, 'WebSocket URL')} />
            <Credential label="Basic Auth username" value={commissioning.username} onCopy={() => void copy(commissioning.username, 'Username')} />
            <Credential secret label="Basic Auth password" value={commissioning.secret ?? ''} onCopy={() => void copy(commissioning.secret ?? '', 'Password')} />
          </section>
        )}

        {commissioning.target === 'simulator' && (
          <section className="commissioning-simulator-panel">
            <div className="commissioning-result-callout"><Server size={18} /><div><strong>Register the local simulator profile</strong><p>Run this from the repository root, then restart the OCPP stack.</p></div></div>
            <div className="commissioning-command"><code>{commissioning.simulator_command}</code><Button type="text" icon={<Clipboard size={15} />} onClick={() => void copy(commissioning.simulator_command ?? '', 'Command')}>Copy</Button></div>
            <ol><li>Run the command above.</li><li>Run <code>npm run ocpp:down</code>, then <code>npm run ocpp:up</code>.</li><li>Open the station detail to watch registration and heartbeat status.</li></ol>
          </section>
        )}

        {commissioning.target === 'inventory' && (
          <section className="commissioning-inventory-panel">
            <div className="commissioning-result-callout"><KeyRound size={18} /><div><strong>Inventory record ready</strong><p>No OCPP credential was created. The station stays unavailable until it is provisioned.</p></div></div>
          </section>
        )}

        <footer>
          <Button onClick={onClose}>{mode === 'rotated' ? 'Close' : 'Back to stations'}</Button>
          {mode === 'created' && <Button type="primary" icon={<ExternalLink size={15} />} onClick={() => { onClose(); navigate(`/stations/${result.data.id}`) }}>Open station detail</Button>}
        </footer>
      </>}
    </Modal>
  )
}

function Credential({ label, value, secret, onCopy }: { label: string; value: string; secret?: boolean; onCopy: () => void }) {
  return <div className="commissioning-credential"><span><small>{label}</small><code>{secret ? value.replace(/.(?=.{6})/g, '•') : value}</code></span><Button type="text" icon={<Clipboard size={15} />} onClick={onCopy}>Copy</Button></div>
}
