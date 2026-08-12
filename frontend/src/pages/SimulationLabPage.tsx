import { useDeferredValue, useEffect, useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Alert, App, Avatar, Button, Empty, Input, Skeleton } from 'antd'
import { ArrowLeft, LogOut, Radio, RefreshCw, Search, ShieldCheck, Wifi, WifiOff } from 'lucide-react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { getApiErrorMessage } from '../api/apiErrors'
import { getRoleConfig } from '../features/auth/roleConfig'
import { useAuth } from '../features/auth/useAuth'
import { OcppPulseVisualizer } from '../features/stations/OcppPulseVisualizer'
import { OcppSimulatorConsole } from '../features/stations/OcppSimulatorConsole'
import {
  getOcppSimulatorConsole,
  getSimulationLabStations,
  restartStation,
  setStationMaintenanceMode,
  unlockStationConnector,
} from '../features/stations/stationApi'
import type { Connector, Station } from '../types/station'

export function SimulationLabPage() {
  const navigate = useNavigate()
  const [searchParams, setSearchParams] = useSearchParams()
  const queryClient = useQueryClient()
  const { message, modal } = App.useApp()
  const { user, primaryRole, logout } = useAuth()
  const [search, setSearch] = useState('')
  const deferredSearch = useDeferredValue(search.trim())
  const requestedStationId = Number(searchParams.get('station'))

  const stationsQuery = useQuery({
    queryKey: ['simulation-lab-stations', deferredSearch],
    queryFn: () => getSimulationLabStations(deferredSearch),
    refetchInterval: 10_000,
  })
  const stations = useMemo(() => stationsQuery.data?.data ?? [], [stationsQuery.data])
  const selectedStationId = stations.some((station) => station.id === requestedStationId)
    ? requestedStationId
    : stations[0]?.id

  useEffect(() => {
    if (selectedStationId && selectedStationId !== requestedStationId) {
      setSearchParams({ station: String(selectedStationId) }, { replace: true })
    }
  }, [requestedStationId, selectedStationId, setSearchParams])

  const snapshotQuery = useQuery({
    queryKey: ['station-simulator', selectedStationId],
    queryFn: () => getOcppSimulatorConsole(selectedStationId as number),
    enabled: Boolean(selectedStationId),
    refetchInterval: 3_000,
    refetchIntervalInBackground: false,
  })
  const station = snapshotQuery.data?.station ?? stations.find((item) => item.id === selectedStationId)
  const canExecute = snapshotQuery.data?.capabilities.execute ?? false

  const refresh = async () => {
    if (!selectedStationId) return
    await Promise.all([
      queryClient.invalidateQueries({ queryKey: ['station-simulator', selectedStationId] }),
      queryClient.invalidateQueries({ queryKey: ['simulation-lab-stations'] }),
      queryClient.invalidateQueries({ queryKey: ['station', selectedStationId] }),
    ])
  }

  const resetMutation = useMutation({
    mutationFn: () => restartStation(selectedStationId as number),
    onSuccess: async () => { await refresh(); void message.success('Soft restart command queued.') },
    onError: (error) => void message.error(getApiErrorMessage(error, 'The restart command could not be queued.')),
  })
  const unlockMutation = useMutation({
    mutationFn: (connector: Connector) => unlockStationConnector(selectedStationId as number, connector.id),
    onSuccess: async (_, connector) => { await refresh(); void message.success(`Unlock queued for ${connector.external_id}.`) },
    onError: (error) => void message.error(getApiErrorMessage(error, 'The unlock command could not be queued.')),
  })
  const maintenanceMutation = useMutation({
    mutationFn: (selected: Station) => setStationMaintenanceMode(selected.id, selected.availability_override !== 'maintenance'),
    onSuccess: async () => { await refresh(); void message.success('Maintenance mode updated.') },
    onError: (error) => void message.error(getApiErrorMessage(error, 'Maintenance mode could not be updated.')),
  })

  const workspacePath = primaryRole ? getRoleConfig(primaryRole).defaultPath : '/app'
  const confirmRestart = () => modal.confirm({
    title: 'Restart this simulated station?',
    content: 'ChargeTrackr will send a Soft Reset command through the OCPP gateway.',
    okText: 'Queue restart',
    onOk: () => resetMutation.mutate(),
  })
  const confirmUnlock = (connector: Connector) => modal.confirm({
    title: `Unlock connector ${connector.external_id}?`,
    content: 'The command will be audited and sent to the selected simulated charge point.',
    okText: 'Queue unlock',
    onOk: () => unlockMutation.mutate(connector),
  })
  const confirmMaintenance = () => station && modal.confirm({
    title: station.availability_override === 'maintenance' ? 'Leave maintenance mode?' : 'Enable maintenance mode?',
    content: 'The station projection and connector availability will be updated through the normal OCPP supervision path.',
    okText: 'Confirm',
    onOk: () => maintenanceMutation.mutate(station),
  })

  return (
    <main className="simulation-lab-page">
      <header className="simulation-lab-topbar">
        <button className="simulation-lab-brand" type="button" onClick={() => navigate(workspacePath)}>
          <img src="/assets/branding/charge-trackr-logo.webp" alt="ChargeTrackr" />
          <span><strong>ChargeTrackr</strong><small>Simulation Lab</small></span>
        </button>
        <div className="simulation-lab-context"><Radio size={17} /><span>OCPP 1.6J</span><i />Controlled test environment</div>
        <div className="simulation-lab-account">
          <Button icon={<ArrowLeft size={16} />} onClick={() => navigate(workspacePath)}>Return to workspace</Button>
          <Avatar src={user?.avatar_url}>{user?.name?.charAt(0)}</Avatar>
          <div><strong>{user?.name}</strong><small>{primaryRole ? getRoleConfig(primaryRole).label : ''}</small></div>
          <Button type="text" aria-label="Sign out" icon={<LogOut size={17} />} onClick={() => void logout().then(() => navigate('/login'))} />
        </div>
      </header>

      <div className="simulation-lab-shell">
        <aside className="simulation-lab-sidebar">
          <div className="simulation-lab-sidebar__heading"><span>SIMULATED FLEET</span><strong>{stationsQuery.data?.meta.total ?? 0} charge points</strong></div>
          <Input prefix={<Search size={16} />} value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Search station" allowClear />
          <div className="simulation-station-list">
            {stations.map((item) => (
              <button key={item.id} type="button" className={item.id === selectedStationId ? 'is-selected' : ''} onClick={() => setSearchParams({ station: String(item.id) })}>
                <span className={item.ocpp_is_connected ? 'is-online' : 'is-offline'}>{item.ocpp_is_connected ? <Wifi size={16} /> : <WifiOff size={16} />}</span>
                <div><strong>{item.name}</strong><small>{item.reference} - {item.city}</small></div>
                <em>{item.connectors.length}</em>
              </button>
            ))}
            {!stationsQuery.isLoading && stations.length === 0 && <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="No simulated station found" />}
          </div>
          <div className="simulation-lab-safety"><ShieldCheck size={18} /><p><strong>Protected controls</strong><span>No raw OCPP payloads or simulator secrets reach this page.</span></p></div>
        </aside>

        <section className="simulation-lab-workspace">
          {stationsQuery.isError && <Alert type="error" showIcon title="Simulated stations could not be loaded" action={<Button onClick={() => void stationsQuery.refetch()}>Retry</Button>} />}
          {station ? <>
            <header className="simulation-lab-hero">
              <div><span>SELECTED CHARGE POINT</span><h1>{station.name}</h1><p>{station.reference} - {station.location} - {station.ocpp_simulator_profile ?? 'Simulator profile'}</p></div>
              <div className="simulation-lab-hero__actions">
                {!canExecute && <span className="lab-readonly-badge"><ShieldCheck size={15} />Read-only access</span>}
                <Button icon={<RefreshCw size={16} />} loading={snapshotQuery.isFetching} onClick={() => void snapshotQuery.refetch()}>Refresh signals</Button>
              </div>
            </header>
            {snapshotQuery.isLoading ? <Skeleton active paragraph={{ rows: 12 }} /> : <>
              <OcppPulseVisualizer station={station} snapshot={snapshotQuery.data} />
              <OcppSimulatorConsole
                station={station}
                snapshot={snapshotQuery.data}
                snapshotLoading={snapshotQuery.isLoading}
                snapshotFetching={snapshotQuery.isFetching}
                snapshotError={snapshotQuery.isError}
                onRefresh={() => void snapshotQuery.refetch()}
                canExecute={canExecute}
                restartPending={resetMutation.isPending}
                unlockPendingConnectorId={unlockMutation.isPending ? unlockMutation.variables?.id ?? null : null}
                maintenancePending={maintenanceMutation.isPending}
                onRestart={confirmRestart}
                onUnlock={confirmUnlock}
                onToggleMaintenance={confirmMaintenance}
              />
            </>}
          </> : !stationsQuery.isLoading && <div className="simulation-lab-empty"><Empty description="Commission a simulated station to use the laboratory" /><Button type="primary" onClick={() => navigate('/stations')}>Open stations</Button></div>}
        </section>
      </div>
    </main>
  )
}
