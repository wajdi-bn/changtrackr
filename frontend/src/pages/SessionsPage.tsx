import { useDeferredValue, useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { App, Button, Card, Dropdown, Empty, Input, Popconfirm, Select, Skeleton, Table } from 'antd'
import dayjs from 'dayjs'
import { BatteryCharging, Clock3, CreditCard, Download, Gauge, Play, Search, Square, Zap } from 'lucide-react'
import type { ColumnsType } from 'antd/es/table'
import { MountainBanner } from '../components/MountainBanner'
import {
  getChargingSessions,
  getChargingAttempts,
  exportChargingSessions,
  processPayment,
  remoteStopChargingSession,
  stopChargingSession,
} from '../features/charging/chargingApi'
import { ChargingStatusTag } from '../features/charging/ChargingStatusTag'
import { PaymentDrawer } from '../features/charging/PaymentDrawer'
import { StartSessionDrawer } from '../features/charging/StartSessionDrawer'
import { useAuth } from '../features/auth/useAuth'
import { getStations } from '../features/stations/stationApi'
import type { ChargingAttempt, ChargingSession, ChargingSessionStatus, PaymentPayload } from '../types/charging'
import { downloadBlob } from '../utils/downloadBlob'

export function SessionsPage() {
  const { primaryRole, user } = useAuth()
  const clientMode = primaryRole === 'client'
  const canStopSessions = (clientMode && (user?.permissions.includes('sessions.stop') ?? false))
    || (user?.permissions.includes('sessions.manage') ?? false)
  const canExport = user?.permissions.includes('reports.export') ?? false
  const [search, setSearch] = useState('')
  const deferredSearch = useDeferredValue(search)
  const [status, setStatus] = useState<'all' | ChargingSessionStatus>('all')
  const [startOpen, setStartOpen] = useState(false)
  const [resumeAttemptUuid, setResumeAttemptUuid] = useState<string | null>(null)
  const [paymentSession, setPaymentSession] = useState<ChargingSession | null>(null)
  const queryClient = useQueryClient()
  const { message } = App.useApp()

  const filters = useMemo(() => ({
    search: deferredSearch.trim() || undefined,
    status: status === 'all' ? undefined : status,
  }), [deferredSearch, status])
  const sessionsQuery = useQuery({ queryKey: ['charging-sessions', filters], queryFn: () => getChargingSessions(filters) })
  const stationsQuery = useQuery({ queryKey: ['stations', 'session-start'], queryFn: () => getStations({}), enabled: clientMode })
  const attemptsQuery = useQuery({ queryKey: ['charging-attempts'], queryFn: getChargingAttempts, enabled: clientMode })
  const sessions = useMemo(() => sessionsQuery.data?.data ?? [], [sessionsQuery.data?.data])
  const activeSession = sessions.find((session) => isActiveSession(session)) ?? null
  const activeAttempt = attemptsQuery.data?.find((attempt) => isActiveAttempt(attempt)) ?? null

  const refreshWorkflow = async () => {
    await queryClient.invalidateQueries({ queryKey: ['charging-sessions'] })
    await queryClient.invalidateQueries({ queryKey: ['payments'] })
    await queryClient.invalidateQueries({ queryKey: ['stations'] })
  }

  const stopMutation = useMutation({
    mutationFn: (session: ChargingSession) => session.source === 'ocpp' ? remoteStopChargingSession(session.id) : stopChargingSession(session.id),
    onSuccess: async (session) => {
      await refreshWorkflow()
      if (session.source !== 'ocpp' && clientMode) setPaymentSession(session)
      void message.success(session.source === 'ocpp' ? 'Stop command sent to the station.' : 'Charging session stopped. The amount is ready for payment.')
    },
    onError: () => void message.error('The session could not be stopped.'),
  })
  const exportMutation = useMutation({
    mutationFn: (format: 'csv' | 'json') => exportChargingSessions(filters, format),
    onSuccess: (blob, format) => {
      downloadBlob(blob, `organization-charging-sessions.${format}`)
      void message.success(`Session export generated as ${format.toUpperCase()}.`)
    },
    onError: () => void message.error('The session export could not be generated.'),
  })
  const paymentMutation = useMutation({
    mutationFn: ({ sessionId, payload }: { sessionId: number; payload: PaymentPayload }) => processPayment(sessionId, payload),
    onSuccess: async (payment) => {
      await refreshWorkflow()
      if (payment.status === 'paid') {
        setPaymentSession(null)
        void message.success(`Payment accepted: ${payment.reference}`)
      } else {
        void message.warning(payment.failure_reason ?? 'The simulated payment was declined.')
      }
    },
    onError: () => void message.error('Payment processing failed.'),
  })

  const columns: ColumnsType<ChargingSession> = [
    { title: 'Session', dataIndex: 'reference', key: 'reference', render: (value: string, item) => <span className="session-reference"><strong>{value}</strong><small>{item.source === 'ocpp' ? `OCPP #${item.ocpp?.transaction_id ?? '—'} - ` : ''}{dayjs(item.started_at).format('DD MMM YYYY, HH:mm')}</small></span> },
    ...(!clientMode ? [{ title: 'Client', key: 'client', render: (_: unknown, item: ChargingSession) => item.client.name }] : []),
    { title: 'Station', key: 'station', render: (_: unknown, item) => <span className="session-station"><strong>{item.station.name}</strong><small>{clientMode && item.organization ? `${item.organization.name} - ` : ''}Connector {item.connector.external_id}</small></span> },
    { title: 'Duration', key: 'duration', render: (_: unknown, item) => isActiveSession(item) ? 'In progress' : `${item.duration_minutes} min` },
    { title: 'Energy', key: 'energy', render: (_: unknown, item) => `${item.energy_kwh.toFixed(3)} kWh` },
    { title: 'Session', dataIndex: 'status', key: 'status', render: (value: ChargingSessionStatus) => <ChargingStatusTag value={value} /> },
    { title: 'Payment', dataIndex: 'payment_status', key: 'payment_status', render: (value) => <ChargingStatusTag value={value} /> },
    { title: 'Total', key: 'total', align: 'right', render: (_: unknown, item) => <span className="session-total"><strong>{item.total_amount} {item.currency}</strong>{item.discount_millimes > 0 && <small>-{(item.discount_millimes / 1000).toFixed(3)} TND plan saving</small>}</span> },
    {
      title: '', key: 'actions', align: 'right', render: (_: unknown, item) => isActiveSession(item) && canStopSessions ? (
        <Popconfirm title="Stop this charging session?" description={item.source === 'ocpp' ? 'A RemoteStopTransaction command will be sent to the station.' : undefined} onConfirm={() => stopMutation.mutate(item)} okText="Stop">
          <Button size="small" icon={<Square size={12} />} loading={stopMutation.isPending}>Stop</Button>
        </Popconfirm>
      ) : clientMode && item.payment_status !== 'paid' ? <Button size="small" type="primary" onClick={() => setPaymentSession(item)}>Pay now</Button> : null,
    },
  ]

  return <div className="sessions-page">
    <MountainBanner
      color={clientMode ? 'green' : 'blue'}
      breadcrumb={[clientMode ? 'Driver' : 'Operations', clientMode ? 'My sessions' : 'Sessions']}
      title={clientMode ? 'My charging sessions' : 'Charging sessions'}
      count={sessionsQuery.data?.summary.total ?? 0}
      subtitle={clientMode ? 'Start, monitor, stop, and pay for your charging sessions from one workflow.' : 'Monitor active charging, delivered energy, client activity, and payment completion.'}
    />

    <div className="session-kpis">
      <SessionKpi icon={<BatteryCharging size={18} />} label="Active" value={sessionsQuery.data?.summary.active ?? 0} tone="purple" />
      <SessionKpi icon={<Gauge size={18} />} label="Completed" value={sessionsQuery.data?.summary.completed ?? 0} tone="green" />
      <SessionKpi icon={<Zap size={18} />} label="Energy delivered" value={`${sessionsQuery.data?.summary.energy_kwh ?? 0} kWh`} tone="blue" />
      <SessionKpi icon={<CreditCard size={18} />} label={clientMode ? 'Paid value' : 'Revenue'} value={`${((sessionsQuery.data?.summary.revenue_millimes ?? 0) / 1000).toFixed(3)} TND`} tone="gold" />
    </div>

    {clientMode && (sessionsQuery.isLoading ? <Skeleton active /> : activeSession ? (
      <section className="active-session-card">
        <div className="active-session-pulse"><BatteryCharging size={25} /></div>
        <div className="active-session-main">
          <span>{activeSession.status === 'stopping' ? 'Stopping' : 'Charging now'}{activeSession.source === 'ocpp' ? ' - Live OCPP' : ''}</span>
          <h2>{activeSession.station.name}</h2>
          <p>{activeSession.organization?.name ?? 'Charging network'} - Connector {activeSession.connector.external_id} - {activeSession.connector.type} - {activeSession.tariff.name}{activeSession.plan ? ` - ${activeSession.plan.name} (${(activeSession.plan.discount_basis_points / 100).toFixed(0)}% off)` : ''} - started {activeSession.started_relative}</p>
        </div>
        <div className="active-session-metrics">
          <div><Clock3 size={15} /><span><small>Elapsed</small><strong>{Math.max(1, dayjs().diff(dayjs(activeSession.started_at), 'minute'))} min</strong></span></div>
          <div><Zap size={15} /><span><small>Measured energy</small><strong>{activeSession.energy_kwh.toFixed(3)} kWh</strong></span></div>
          {activeSession.current_power_kw !== null && <div><Gauge size={15} /><span><small>Current power</small><strong>{activeSession.current_power_kw.toFixed(1)} kW</strong></span></div>}
          <div><CreditCard size={15} /><span><small>Current estimate</small><strong>{activeSession.total_amount} {activeSession.currency}</strong></span></div>
        </div>
        <Popconfirm title="Stop charging now?" description={activeSession.source === 'ocpp' ? 'The station will receive a secure remote stop command.' : 'The final energy and amount will be calculated.'} onConfirm={() => stopMutation.mutate(activeSession)} okText="Stop session">
          <Button danger icon={<Square size={14} />} loading={stopMutation.isPending}>Stop charging</Button>
        </Popconfirm>
      </section>
    ) : activeAttempt ? (
      <section className="no-active-session pending-attempt-card">
        <div><BatteryCharging size={21} /></div><span><strong>Charging start in progress</strong><p>{activeAttempt.station.name} · {attemptStatusLabel(activeAttempt)}</p></span>
        <Button type="primary" onClick={() => { setResumeAttemptUuid(activeAttempt.uuid); setStartOpen(true) }}>Resume</Button>
      </section>
    ) : (
      <section className="no-active-session">
        <div><Play size={21} /></div><span><strong>No active charging session</strong><p>Choose an available station and connector to begin.</p></span>
        <Button type="primary" icon={<Play size={14} />} onClick={() => { setResumeAttemptUuid(null); setStartOpen(true) }}>Start a session</Button>
      </section>
    ))}

    <Card className="sessions-table-card" title={clientMode ? 'Session history' : 'Network sessions'} extra={clientMode
      ? <Button type="primary" icon={<Play size={14} />} disabled={Boolean(activeSession || activeAttempt)} onClick={() => { setResumeAttemptUuid(null); setStartOpen(true) }}>Start session</Button>
      : canExport && <Dropdown menu={{ items: [
        { key: 'csv', label: 'Export CSV', onClick: () => exportMutation.mutate('csv') },
        { key: 'json', label: 'Export JSON', onClick: () => exportMutation.mutate('json') },
      ] }}><Button icon={<Download size={14} />} loading={exportMutation.isPending}>Export</Button></Dropdown>}>
      <div className="sessions-toolbar">
        <Input value={search} onChange={(event) => setSearch(event.target.value)} prefix={<Search size={14} />} placeholder="Search sessions" allowClear />
        <Select value={status} onChange={(value) => setStatus(value)} options={['all', 'pending', 'charging', 'stopping', 'completed', 'interrupted', 'failed', 'cancelled'].map((value) => ({ value, label: value === 'all' ? 'All statuses' : value }))} />
      </div>
      <Table rowKey="id" columns={columns} dataSource={sessions} loading={sessionsQuery.isLoading} pagination={{ pageSize: 8, hideOnSinglePage: true }} scroll={{ x: 1050 }} locale={{ emptyText: <Empty description="No charging sessions found" /> }} />
    </Card>

    <StartSessionDrawer open={startOpen} stations={stationsQuery.data?.data ?? []} initialAttemptUuid={resumeAttemptUuid} onClose={() => setStartOpen(false)} onSessionStarted={() => { void refreshWorkflow(); void message.success('The station confirmed that charging has started.') }} />
    <PaymentDrawer open={Boolean(paymentSession)} session={paymentSession} submitting={paymentMutation.isPending} onClose={() => setPaymentSession(null)} onSubmit={(payload) => paymentSession && paymentMutation.mutate({ sessionId: paymentSession.id, payload })} />
  </div>
}

function SessionKpi({ icon, label, value, tone }: { icon: React.ReactNode; label: string; value: string | number; tone: string }) {
  return <div className="session-kpi"><span className={tone}>{icon}</span><div><small>{label}</small><strong>{value}</strong></div></div>
}

function isActiveSession(session: ChargingSession) {
  return ['pending', 'charging', 'stopping'].includes(session.status)
}

function isActiveAttempt(attempt: ChargingAttempt) {
  return !attempt.charging_session && !['completed', 'failed'].includes(attempt.status)
}

function attemptStatusLabel(attempt: ChargingAttempt) {
  return ({ payment_pending: 'Authorizing payment', authorized: 'Payment authorized', command_queued: 'Command queued', command_sent: 'Contacting station', awaiting_station: 'Waiting for station confirmation', charging: 'Starting session' } as Record<string, string>)[attempt.status] ?? 'In progress'
}
