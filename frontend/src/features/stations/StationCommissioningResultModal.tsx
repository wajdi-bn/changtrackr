import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Alert, App, Button, Modal, Spin, Tag } from 'antd'
import { AlertTriangle, CheckCircle2, Clipboard, ExternalLink, KeyRound, RadioTower, ShieldCheck } from 'lucide-react'
import { useNavigate } from 'react-router-dom'
import type { CommissioningStatus, StationCommissioningResult } from '../../types/station'
import { getApiErrorMessage } from '../../api/apiErrors'
import { getStation, retrySimulatorProvisioning } from './stationApi'

interface StationCommissioningResultModalProps {
  result: StationCommissioningResult | null
  mode?: 'created' | 'rotated'
  onClose: () => void
}

export function StationCommissioningResultModal({ result, mode = 'created', onClose }: StationCommissioningResultModalProps) {
  const { message } = App.useApp()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const commissioning = result?.commissioning
  const simulatorMode = commissioning?.target === 'simulator'
  const stationId = result?.data.id
  const stationQuery = useQuery({
    queryKey: ['station', stationId],
    queryFn: () => getStation(stationId as number),
    enabled: Boolean(stationId && simulatorMode),
    initialData: simulatorMode ? result?.data : undefined,
    refetchInterval: (query) => {
      const status = query.state.data?.commissioning_status ?? result?.data.commissioning_status
      return status === 'provisioning' || status === 'awaiting_connection' ? 1500 : false
    },
  })
  const retryMutation = useMutation({
    mutationFn: () => retrySimulatorProvisioning(stationId as number),
    onSuccess: async (response) => {
      queryClient.setQueryData(['station', stationId], response.data)
      await queryClient.invalidateQueries({ queryKey: ['stations'] })
      void message.success('Simulator provisioning restarted.')
    },
    onError: (error) => void message.error(getApiErrorMessage(error, 'Provisioning could not be restarted.')),
  })
  const liveStation = stationQuery.data ?? result?.data

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
          <div><Tag color="green">{mode === 'rotated' ? 'Credentials rotated' : 'Station created'}</Tag><h2>{result.data.name}</h2><p>{result.data.reference} - {result.data.connectors.length} connector(s)</p></div>
        </header>

        {commissioning.target === 'external' && (
          <section className="commissioning-secret-panel">
            <div className="commissioning-result-callout"><ShieldCheck size={18} /><div><strong>Save these credentials now</strong><p>The station secret cannot be displayed again. Rotating it later invalidates this one.</p></div></div>
            <Credential label="WebSocket URL" value={commissioning.connection_url} onCopy={() => void copy(commissioning.connection_url, 'WebSocket URL')} />
            <Credential label="Basic Auth username" value={commissioning.username} onCopy={() => void copy(commissioning.username, 'Username')} />
            <Credential secret label="Basic Auth password" value={commissioning.secret ?? ''} onCopy={() => void copy(commissioning.secret ?? '', 'Password')} />
          </section>
        )}

        {commissioning.target === 'simulator' && liveStation && (
          <SimulatorProvisioningState
            status={liveStation.commissioning_status}
            error={liveStation.ocpp_provisioning_error}
            profile={liveStation.ocpp_simulator_profile}
            retrying={retryMutation.isPending}
            onRetry={() => retryMutation.mutate()}
          />
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

function SimulatorProvisioningState({
  status,
  error,
  profile,
  retrying,
  onRetry,
}: {
  status: CommissioningStatus
  error: string | null
  profile: string | null
  retrying: boolean
  onRetry: () => void
}) {
  const failed = status === 'provisioning_failed'
  const connected = status === 'connected'
  const waitingForConnection = status === 'awaiting_connection' || status === 'offline'

  return (
    <section className={`commissioning-simulator-panel commissioning-simulator-panel--${failed ? 'failed' : connected ? 'connected' : 'pending'}`}>
      <div className="simulator-provisioning-state">
        <span>{failed ? <AlertTriangle size={25} /> : connected ? <CheckCircle2 size={25} /> : <Spin size="small" />}</span>
        <div>
          <small>{profile ?? 'OCPP simulator profile'}</small>
          <strong>{failed ? 'Provisioning needs attention' : connected ? 'Simulator connected' : waitingForConnection ? 'Waiting for the first OCPP signal' : 'Creating the simulator station'}</strong>
          <p>{failed
            ? 'The station record is safe. Retry when the simulator service is available.'
            : connected
              ? 'Registration, heartbeat and connector state are now visible in the station workspace.'
              : waitingForConnection
                ? 'The simulator instance has been created and is completing its OCPP registration.'
                : 'The background worker is adding and starting the station. No terminal command is required.'}</p>
        </div>
      </div>
      {failed && <Alert type="error" showIcon title="Automatic provisioning failed" description={error ?? 'The simulator could not provision this station.'} />}
      <div className="simulator-provisioning-meta"><RadioTower size={16} /><span>Live status refresh is active while this window is open.</span></div>
      {failed && <Button type="primary" loading={retrying} onClick={onRetry}>Retry provisioning</Button>}
    </section>
  )
}

function Credential({ label, value, secret, onCopy }: { label: string; value: string; secret?: boolean; onCopy: () => void }) {
  return <div className="commissioning-credential"><span><small>{label}</small><code>{secret ? value.replace(/.(?=.{6})/g, '*') : value}</code></span><Button type="text" icon={<Clipboard size={15} />} onClick={onCopy}>Copy</Button></div>
}
