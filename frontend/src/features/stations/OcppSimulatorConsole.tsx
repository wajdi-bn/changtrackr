import { useEffect, useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Alert, App, Avatar, Button, Empty, Popconfirm, Select, Skeleton, Tooltip } from 'antd'
import dayjs from 'dayjs'
import {
  Activity,
  Cable,
  CheckCircle2,
  CircleAlert,
  HeartPulse,
  LockOpen,
  Play,
  Power,
  Radio,
  RefreshCw,
  RotateCw,
  Unplug,
  Wifi,
  WifiOff,
  Wrench,
  XCircle,
  Zap,
} from 'lucide-react'
import { getApiErrorMessage } from '../../api/apiErrors'
import type { Connector, OcppSimulatorAction, OcppSimulatorActionName, Station } from '../../types/station'
import { executeOcppSimulatorAction, getOcppSimulatorConsole } from './stationApi'

const actionLabels: Record<OcppSimulatorActionName, string> = {
  connect: 'Connect station',
  disconnect: 'Disconnect station',
  heartbeat: 'Send heartbeat',
  plug: 'Plug cable',
  unplug: 'Unplug cable',
  inject_fault: 'Inject connector fault',
  recover: 'Recover connector',
  normal_cycle: 'Cable cycle',
  fault_recovery: 'Fault and recovery',
}

export function OcppSimulatorConsole({
  station,
  active,
  canExecute,
  restartPending,
  unlockPendingConnectorId,
  maintenancePending,
  onRestart,
  onUnlock,
  onToggleMaintenance,
}: {
  station: Station
  active: boolean
  canExecute: boolean
  restartPending: boolean
  unlockPendingConnectorId: number | null
  maintenancePending: boolean
  onRestart: () => void
  onUnlock: (connector: Connector) => void
  onToggleMaintenance: () => void
}) {
  const { message } = App.useApp()
  const queryClient = useQueryClient()
  const connectorOptions = useMemo(() => station.connectors
    .filter((connector) => connector.ocpp_connector_id !== null)
    .map((connector) => ({
      label: `${connector.external_id} - ${connector.type} (${connector.ocpp_connector_id})`,
      value: connector.ocpp_connector_id as number,
    })), [station.connectors])
  const [connectorId, setConnectorId] = useState<number | undefined>(connectorOptions[0]?.value)

  useEffect(() => {
    if (connectorId === undefined || !connectorOptions.some((option) => option.value === connectorId)) {
      setConnectorId(connectorOptions[0]?.value)
    }
  }, [connectorId, connectorOptions])

  const consoleQuery = useQuery({
    queryKey: ['station-simulator', station.id],
    queryFn: () => getOcppSimulatorConsole(station.id),
    enabled: active,
    refetchInterval: (query) => query.state.data?.history.data.some((item) => ['queued', 'running'].includes(item.status))
      ? 1_200
      : 4_000,
  })

  const actionMutation = useMutation({
    mutationFn: ({ action, selectedConnectorId }: { action: OcppSimulatorActionName; selectedConnectorId?: number }) =>
      executeOcppSimulatorAction(station.id, action, selectedConnectorId),
    onSuccess: async (action) => {
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: ['station-simulator', station.id] }),
        queryClient.invalidateQueries({ queryKey: ['station', station.id] }),
        queryClient.invalidateQueries({ queryKey: ['stations'] }),
      ])
      void message.success(`${actionLabels[action.action]} queued.`)
    },
    onError: (error) => void message.error(getApiErrorMessage(error, 'The simulator action could not be queued.')),
  })

  const data = consoleQuery.data
  const controlsDisabled = !canExecute || !data?.adapter.available || actionMutation.isPending
  const selectedConnector = station.connectors.find((connector) => connector.ocpp_connector_id === connectorId) ?? null
  const execute = (action: OcppSimulatorActionName, needsConnector = false) => {
    actionMutation.mutate({ action, selectedConnectorId: needsConnector ? connectorId : undefined })
  }

  if (consoleQuery.isLoading) {
    return <div className="simulator-console-loading"><Skeleton active paragraph={{ rows: 8 }} /></div>
  }

  return (
    <div className="simulator-console">
      <section className="simulator-console-heading">
        <div className="simulator-console-heading__icon"><Radio size={22} /></div>
        <div>
          <span>OCPP 1.6J TEST ENVIRONMENT</span>
          <h2>Simulator console</h2>
          <p>Reproduce physical station events and observe their verified effect across OCPP, availability and operations.</p>
        </div>
        <Button icon={<RefreshCw size={16} />} loading={consoleQuery.isFetching} onClick={() => void consoleQuery.refetch()}>Refresh state</Button>
      </section>

      {consoleQuery.isError && <Alert type="error" showIcon title="Simulator state could not be loaded" action={<Button size="small" onClick={() => void consoleQuery.refetch()}>Retry</Button>} />}
      {data && !data.adapter.available && <Alert type="warning" showIcon title="Simulator control unavailable" description={data.adapter.message} />}

      <section className="simulator-status-strip">
        <SimulatorMetric icon={<Radio size={17} />} label="Adapter" value={data?.adapter.available ? 'Operational' : 'Unavailable'} tone={data?.adapter.available ? 'success' : 'danger'} />
        <SimulatorMetric icon={data?.state?.connected ? <Wifi size={17} /> : <WifiOff size={17} />} label="OCPP link" value={data?.state?.connected ? 'Connected' : 'Disconnected'} tone={data?.state?.connected ? 'success' : 'danger'} />
        <SimulatorMetric icon={<Zap size={17} />} label="Connectors" value={`${data?.state?.connectors.length ?? 0} detected`} />
        <SimulatorMetric icon={<Activity size={17} />} label="Identity" value={data?.state?.identity ?? station.ocpp_identity ?? 'Not registered'} />
      </section>

      <div className="simulator-control-grid">
        <SimulatorPanel eyebrow="STATION" title="Connection controls" description="Control the simulated device process and its OCPP link.">
          <div className="simulator-button-row">
            <Button type="primary" icon={<Wifi size={16} />} disabled={controlsDisabled || data?.state?.connected === true} onClick={() => execute('connect')}>Connect</Button>
            <Popconfirm title="Disconnect this simulated station?" description="The availability timeout and offline alerts can then be observed." onConfirm={() => execute('disconnect')}>
              <Button danger icon={<WifiOff size={16} />} disabled={controlsDisabled || data?.state?.connected !== true}>Disconnect</Button>
            </Popconfirm>
            <Button icon={<HeartPulse size={16} />} disabled={controlsDisabled || data?.state?.connected !== true} onClick={() => execute('heartbeat')}>Heartbeat</Button>
          </div>
        </SimulatorPanel>

        <SimulatorPanel eyebrow="PHYSICAL CONNECTOR" title="Cable and fault controls" description="Simulate actions that normally happen at the vehicle and connector.">
          <Select className="simulator-connector-select" value={connectorId} options={connectorOptions} onChange={setConnectorId} placeholder="Select a connector" />
          <div className="simulator-button-row">
            <Button type="primary" icon={<Cable size={16} />} disabled={controlsDisabled || connectorId === undefined} onClick={() => execute('plug', true)}>Plug</Button>
            <Button icon={<Unplug size={16} />} disabled={controlsDisabled || connectorId === undefined} onClick={() => execute('unplug', true)}>Unplug</Button>
            <Popconfirm title="Inject a ConnectorLockFailure?" description="This will create a real OCPP Faulted status for the selected simulated connector." onConfirm={() => execute('inject_fault', true)}>
              <Button danger icon={<CircleAlert size={16} />} disabled={controlsDisabled || connectorId === undefined}>Inject fault</Button>
            </Popconfirm>
            <Button icon={<RefreshCw size={16} />} disabled={controlsDisabled || connectorId === undefined} onClick={() => execute('recover', true)}>Recover</Button>
          </div>
        </SimulatorPanel>

        <SimulatorPanel eyebrow="SCENARIOS" title="Repeatable test presets" description="Run short, deterministic sequences without exposing arbitrary simulator commands.">
          <button className="simulator-scenario" disabled={controlsDisabled || connectorId === undefined} onClick={() => execute('normal_cycle', true)}>
            <span><Play size={17} /></span><strong>Cable cycle</strong><small>Preparing to Available</small>
          </button>
          <button className="simulator-scenario simulator-scenario--warning" disabled={controlsDisabled || connectorId === undefined} onClick={() => execute('fault_recovery', true)}>
            <span><RotateCw size={17} /></span><strong>Fault and recovery</strong><small>Faulted to Available</small>
          </button>
        </SimulatorPanel>

        <SimulatorPanel eyebrow="CENTRAL SYSTEM" title="OCPP supervision" description="Commands sent by ChargeTrackr to the station, kept separate from physical simulation.">
          <div className="simulator-button-row">
            <Tooltip title={!station.ocpp_is_connected ? 'The station must be online.' : undefined}>
              <span><Button icon={<Power size={16} />} loading={restartPending} disabled={!canExecute || !station.ocpp_is_connected} onClick={onRestart}>Soft restart</Button></span>
            </Tooltip>
            <Tooltip title={!selectedConnector || !station.ocpp_is_connected ? 'Select an online OCPP connector.' : undefined}>
              <span><Button icon={<LockOpen size={16} />} loading={selectedConnector ? unlockPendingConnectorId === selectedConnector.id : false} disabled={!canExecute || !selectedConnector || !station.ocpp_is_connected} onClick={() => selectedConnector && onUnlock(selectedConnector)}>Unlock</Button></span>
            </Tooltip>
            <Button icon={<Wrench size={16} />} loading={maintenancePending} disabled={!canExecute || station.maintenance_intervention_id !== null} onClick={onToggleMaintenance}>
              {station.availability_override === 'maintenance' ? 'Leave maintenance' : 'Maintenance mode'}
            </Button>
          </div>
        </SimulatorPanel>
      </div>

      <section className="simulator-live-panel">
        <header><div><span>LIVE SIMULATOR STATE</span><h3>Connector projections</h3></div><small>Updated from the private SAP adapter</small></header>
        <div className="simulator-connector-state-grid">
          {(data?.state?.connectors ?? []).map((connector) => (
            <article key={connector.connector_id}>
              <div className={`simulator-state-icon ${connector.status === 'Faulted' ? 'is-faulted' : ''}`}><Cable size={18} /></div>
              <div><strong>Connector {connector.connector_id}</strong><small>{connector.availability}</small></div>
              <span className={`simulator-state-pill simulator-state-pill--${connector.status.toLowerCase()}`}>{connector.status}</span>
              <p>{connector.error_code === 'NoError' ? 'No active error' : connector.error_code}</p>
            </article>
          ))}
          {(data?.state?.connectors.length ?? 0) === 0 && <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="No connector state returned by the simulator" />}
        </div>
      </section>

      <section className="simulator-history-panel">
        <header><div><span>AUDIT TRAIL</span><h3>Recent simulator actions</h3></div><small>{data?.history.meta.total ?? 0} recorded actions</small></header>
        <div className="simulator-timeline">
          {(data?.history.data ?? []).map((item) => <SimulatorTimelineItem key={item.uuid} item={item} />)}
          {(data?.history.data.length ?? 0) === 0 && <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="No simulator action has been executed yet" />}
        </div>
      </section>
    </div>
  )
}

function SimulatorPanel({ eyebrow, title, description, children }: { eyebrow: string; title: string; description: string; children: React.ReactNode }) {
  return <section className="simulator-control-panel"><span>{eyebrow}</span><h3>{title}</h3><p>{description}</p><div className="simulator-control-panel__body">{children}</div></section>
}

function SimulatorMetric({ icon, label, value, tone = 'neutral' }: { icon: React.ReactNode; label: string; value: string; tone?: 'neutral' | 'success' | 'danger' }) {
  return <div className={`simulator-metric simulator-metric--${tone}`}><span>{icon}</span><div><small>{label}</small><strong>{value}</strong></div></div>
}

function SimulatorTimelineItem({ item }: { item: OcppSimulatorAction }) {
  const statusIcon = item.status === 'succeeded'
    ? <CheckCircle2 size={17} />
    : item.status === 'failed'
      ? <XCircle size={17} />
      : <RefreshCw className={item.status === 'running' ? 'is-spinning' : ''} size={17} />
  return (
    <article className={`simulator-timeline-item is-${item.status}`}>
      <span className="simulator-timeline-item__status">{statusIcon}</span>
      <div className="simulator-timeline-item__main"><strong>{actionLabels[item.action]}</strong><small>{item.connector ? `Connector ${item.connector.external_id}` : 'Whole station'} · {dayjs(item.queued_at).format('DD MMM, HH:mm:ss')}</small></div>
      <div className="simulator-timeline-item__user">{item.requested_by ? <><Avatar size={25} src={item.requested_by.avatar_url}>{item.requested_by.name.charAt(0)}</Avatar><span>{item.requested_by.name}</span></> : <span>System</span>}</div>
      <span className="simulator-timeline-item__label">{item.status.replace('_', ' ')}</span>
      {item.failure_message && <p>{item.failure_message}</p>}
    </article>
  )
}
