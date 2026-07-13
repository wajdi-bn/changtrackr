import { useDeferredValue, useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { App, Button, Card, Empty, Input, Popconfirm, Select, Skeleton, Table } from 'antd'
import dayjs from 'dayjs'
import { BatteryCharging, Clock3, CreditCard, Gauge, Play, Search, Square, Zap } from 'lucide-react'
import type { ColumnsType } from 'antd/es/table'
import { MountainBanner } from '../components/MountainBanner'
import {
  getChargingSessions,
  processPayment,
  startChargingSession,
  stopChargingSession,
} from '../features/charging/chargingApi'
import { ChargingStatusTag } from '../features/charging/ChargingStatusTag'
import { PaymentDrawer } from '../features/charging/PaymentDrawer'
import { StartSessionDrawer } from '../features/charging/StartSessionDrawer'
import { useAuth } from '../features/auth/useAuth'
import { getStations } from '../features/stations/stationApi'
import type { ChargingSession, ChargingSessionStatus, PaymentPayload } from '../types/charging'

export function SessionsPage() {
  const { primaryRole } = useAuth()
  const clientMode = primaryRole === 'client'
  const [search, setSearch] = useState('')
  const deferredSearch = useDeferredValue(search)
  const [status, setStatus] = useState<'all' | ChargingSessionStatus>('all')
  const [startOpen, setStartOpen] = useState(false)
  const [paymentSession, setPaymentSession] = useState<ChargingSession | null>(null)
  const queryClient = useQueryClient()
  const { message } = App.useApp()

  const filters = useMemo(() => ({
    search: deferredSearch.trim() || undefined,
    status: status === 'all' ? undefined : status,
  }), [deferredSearch, status])
  const sessionsQuery = useQuery({ queryKey: ['charging-sessions', filters], queryFn: () => getChargingSessions(filters) })
  const stationsQuery = useQuery({ queryKey: ['stations', 'session-start'], queryFn: () => getStations({}), enabled: clientMode })
  const sessions = useMemo(() => sessionsQuery.data?.data ?? [], [sessionsQuery.data?.data])
  const activeSession = sessions.find((session) => session.status === 'charging') ?? null

  const refreshWorkflow = async () => {
    await queryClient.invalidateQueries({ queryKey: ['charging-sessions'] })
    await queryClient.invalidateQueries({ queryKey: ['payments'] })
    await queryClient.invalidateQueries({ queryKey: ['stations'] })
  }

  const startMutation = useMutation({
    mutationFn: startChargingSession,
    onSuccess: async () => {
      await refreshWorkflow()
      setStartOpen(false)
      void message.success('Charging session started.')
    },
    onError: () => void message.error('The session could not be started. The connector may no longer be available.'),
  })
  const stopMutation = useMutation({
    mutationFn: stopChargingSession,
    onSuccess: async (session) => {
      await refreshWorkflow()
      if (clientMode) setPaymentSession(session)
      void message.success('Charging session stopped. The amount is ready for payment.')
    },
    onError: () => void message.error('The session could not be stopped.'),
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
    { title: 'Session', dataIndex: 'reference', key: 'reference', render: (value: string, item) => <span className="session-reference"><strong>{value}</strong><small>{dayjs(item.started_at).format('DD MMM YYYY, HH:mm')}</small></span> },
    ...(!clientMode ? [{ title: 'Client', key: 'client', render: (_: unknown, item: ChargingSession) => item.client.name }] : []),
    { title: 'Station', key: 'station', render: (_: unknown, item) => <span className="session-station"><strong>{item.station.name}</strong><small>Connector {item.connector.external_id}</small></span> },
    { title: 'Duration', key: 'duration', render: (_: unknown, item) => item.status === 'charging' ? 'In progress' : `${item.duration_minutes} min` },
    { title: 'Energy', key: 'energy', render: (_: unknown, item) => `${item.energy_kwh.toFixed(3)} kWh` },
    { title: 'Session', dataIndex: 'status', key: 'status', render: (value: ChargingSessionStatus) => <ChargingStatusTag value={value} /> },
    { title: 'Payment', dataIndex: 'payment_status', key: 'payment_status', render: (value) => <ChargingStatusTag value={value} /> },
    { title: 'Total', key: 'total', align: 'right', render: (_: unknown, item) => <strong>{item.total_amount} {item.currency}</strong> },
    {
      title: '', key: 'actions', align: 'right', render: (_: unknown, item) => item.status === 'charging' ? (
        <Popconfirm title="Stop this charging session?" onConfirm={() => stopMutation.mutate(item.id)} okText="Stop">
          <Button size="small" icon={<Square size={12} />}>Stop</Button>
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
          <span>Charging now</span>
          <h2>{activeSession.station.name}</h2>
          <p>Connector {activeSession.connector.external_id} · {activeSession.connector.type} · started {activeSession.started_relative}</p>
        </div>
        <div className="active-session-metrics">
          <div><Clock3 size={15} /><span><small>Elapsed</small><strong>{Math.max(1, dayjs().diff(dayjs(activeSession.started_at), 'minute'))} min</strong></span></div>
          <div><Zap size={15} /><span><small>Tariff</small><strong>{(activeSession.price_per_kwh_millimes / 1000).toFixed(3)} TND/kWh</strong></span></div>
        </div>
        <Popconfirm title="Stop charging now?" description="The final energy and amount will be calculated." onConfirm={() => stopMutation.mutate(activeSession.id)} okText="Stop session">
          <Button danger icon={<Square size={14} />} loading={stopMutation.isPending}>Stop charging</Button>
        </Popconfirm>
      </section>
    ) : (
      <section className="no-active-session">
        <div><Play size={21} /></div><span><strong>No active charging session</strong><p>Choose an available station and connector to begin.</p></span>
        <Button type="primary" icon={<Play size={14} />} onClick={() => setStartOpen(true)}>Start a session</Button>
      </section>
    ))}

    <Card className="sessions-table-card" title={clientMode ? 'Session history' : 'Network sessions'} extra={clientMode && <Button type="primary" icon={<Play size={14} />} disabled={Boolean(activeSession)} onClick={() => setStartOpen(true)}>Start session</Button>}>
      <div className="sessions-toolbar">
        <Input value={search} onChange={(event) => setSearch(event.target.value)} prefix={<Search size={14} />} placeholder="Search sessions" allowClear />
        <Select value={status} onChange={(value) => setStatus(value)} options={['all', 'charging', 'completed', 'cancelled'].map((value) => ({ value, label: value === 'all' ? 'All statuses' : value }))} />
      </div>
      <Table rowKey="id" columns={columns} dataSource={sessions} loading={sessionsQuery.isLoading} pagination={{ pageSize: 8, hideOnSinglePage: true }} scroll={{ x: 1050 }} locale={{ emptyText: <Empty description="No charging sessions found" /> }} />
    </Card>

    <StartSessionDrawer open={startOpen} stations={stationsQuery.data?.data ?? []} submitting={startMutation.isPending} onClose={() => setStartOpen(false)} onSubmit={(payload) => startMutation.mutate(payload)} />
    <PaymentDrawer open={Boolean(paymentSession)} session={paymentSession} submitting={paymentMutation.isPending} onClose={() => setPaymentSession(null)} onSubmit={(payload) => paymentSession && paymentMutation.mutate({ sessionId: paymentSession.id, payload })} />
  </div>
}

function SessionKpi({ icon, label, value, tone }: { icon: React.ReactNode; label: string; value: string | number; tone: string }) {
  return <div className="session-kpi"><span className={tone}>{icon}</span><div><small>{label}</small><strong>{value}</strong></div></div>
}
