import { useDeferredValue, useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Alert, App, Button, Card, Dropdown, Empty, Input, Select, Table } from 'antd'
import axios from 'axios'
import dayjs from 'dayjs'
import { CircleDollarSign, CreditCard, Download, ReceiptText, RefreshCw, Search } from 'lucide-react'
import type { ColumnsType } from 'antd/es/table'
import { MountainBanner } from '../components/MountainBanner'
import { MetricItem, MetricStrip } from '../components/MetricStrip'
import { exportPayments, getChargingSessions, getPayments, processPayment } from '../features/charging/chargingApi'
import { ChargingStatusTag } from '../features/charging/ChargingStatusTag'
import { PaymentDrawer } from '../features/charging/PaymentDrawer'
import { useAuth } from '../features/auth/useAuth'
import type { ChargingSession, Payment, PaymentPayload, PaymentStatus } from '../types/charging'
import { downloadBlob } from '../utils/downloadBlob'

export function PaymentsPage() {
  const { primaryRole, user } = useAuth()
  const clientMode = primaryRole === 'client'
  const canExport = user?.permissions.includes('reports.export') ?? false
  const [search, setSearch] = useState('')
  const deferredSearch = useDeferredValue(search)
  const [status, setStatus] = useState<'all' | PaymentStatus>('all')
  const [paymentSession, setPaymentSession] = useState<ChargingSession | null>(null)
  const queryClient = useQueryClient()
  const { message } = App.useApp()
  const filters = useMemo(() => ({ search: deferredSearch.trim() || undefined, status: status === 'all' ? undefined : status }), [deferredSearch, status])
  const paymentsQuery = useQuery({ queryKey: ['payments', filters], queryFn: () => getPayments(filters) })
  const unpaidQuery = useQuery({ queryKey: ['charging-sessions', 'unpaid'], queryFn: () => getChargingSessions({ payment_status: 'unpaid' }), enabled: clientMode })
  const failedQuery = useQuery({ queryKey: ['charging-sessions', 'failed-payment'], queryFn: () => getChargingSessions({ payment_status: 'failed' }), enabled: clientMode })
  const payableSessions = useMemo(() => [...(unpaidQuery.data?.data ?? []), ...(failedQuery.data?.data ?? [])]
    .filter((session) => ['completed', 'interrupted'].includes(session.status)), [failedQuery.data?.data, unpaidQuery.data?.data])

  const paymentMutation = useMutation({
    mutationFn: ({ sessionId, payload }: { sessionId: number; payload: PaymentPayload }) => processPayment(sessionId, payload),
    onSuccess: async (payment) => {
      await queryClient.invalidateQueries({ queryKey: ['payments'] })
      await queryClient.invalidateQueries({ queryKey: ['charging-sessions'] })
      if (payment.status === 'paid') {
        setPaymentSession(null)
        void message.success(`Payment accepted: ${payment.reference}`)
      } else {
        void message.warning(payment.failure_reason ?? 'The simulated payment was declined.')
      }
    },
    onError: (error) => void message.error(paymentErrorMessage(error)),
  })
  const exportMutation = useMutation({
    mutationFn: (format: 'csv' | 'json') => exportPayments(filters, format),
    onSuccess: (blob, format) => {
      downloadBlob(blob, `organization-payments.${format}`)
      void message.success(`Payment export generated as ${format.toUpperCase()}.`)
    },
    onError: () => void message.error('The payment export could not be generated.'),
  })

  const columns: ColumnsType<Payment> = [
    { title: 'Payment', dataIndex: 'reference', key: 'reference', render: (value: string, item) => <span className="payment-reference"><strong>{value}</strong><small>{dayjs(item.created_at).format('DD MMM YYYY, HH:mm')}</small></span> },
    ...(!clientMode ? [{ title: 'Client', key: 'client', render: (_: unknown, item: Payment) => item.client?.name ?? 'Deleted user' }] : []),
    { title: 'Session', key: 'session', render: (_: unknown, item) => <span className="payment-reference"><strong>{item.session?.reference}</strong><small>{item.session?.station_name} · {item.session?.connector_external_id}</small></span> },
    { title: 'Method', dataIndex: 'method', key: 'method', render: (value: string) => value.replace('simulated_', '').replace('_', ' ') },
    { title: 'Provider', dataIndex: 'provider', key: 'provider', render: (value: string) => value === 'wiremock' ? 'External sandbox' : value },
    { title: 'Status', dataIndex: 'status', key: 'status', render: (value: PaymentStatus) => <ChargingStatusTag value={value} /> },
    { title: 'Transaction', dataIndex: 'provider_transaction_id', key: 'transaction', render: (value: string | null, item) => <span className="payment-reference"><strong>{value ?? 'Pending'}</strong>{item.provider_event && <small>Webhook: {item.provider_event.processing_status.replaceAll('_', ' ')}</small>}</span> },
    { title: 'Amount', key: 'amount', align: 'right', render: (_: unknown, item) => <strong>{item.amount} {item.currency}</strong> },
  ]

  return <div className="payments-page">
    <MountainBanner color="purple" breadcrumb={[clientMode ? 'Driver' : 'Operations', clientMode ? 'Payments & invoices' : 'Payments']} title={clientMode ? 'Payments & invoices' : 'Payment monitoring'} count={paymentsQuery.data?.summary.total ?? 0} subtitle={clientMode ? 'Pay completed charging sessions and keep a traceable receipt history.' : 'Monitor simulated provider outcomes, failed attempts, transaction references, and collected revenue.'} />
    <MetricStrip className="payment-kpis">
      <MetricItem icon={<ReceiptText size={18} />} label="Transactions" value={paymentsQuery.data?.summary.total ?? 0} tone="blue" />
      <MetricItem icon={<CircleDollarSign size={18} />} label="Paid" value={paymentsQuery.data?.summary.paid ?? 0} tone="green" />
      <MetricItem icon={<RefreshCw size={18} />} label="Failed" value={paymentsQuery.data?.summary.failed ?? 0} tone="red" />
      <MetricItem icon={<CreditCard size={18} />} label={clientMode ? 'Total paid' : 'Revenue'} value={`${((paymentsQuery.data?.summary.revenue_millimes ?? 0) / 1000).toFixed(3)} TND`} tone="purple" />
    </MetricStrip>

    {paymentsQuery.isError && <Alert className="payments-api-error" type="error" showIcon title="Unable to load payments" description={axios.isAxiosError(paymentsQuery.error) ? `The API returned status ${paymentsQuery.error.response?.status ?? 'unknown'}.` : 'Check the API connection and retry.'} action={<Button size="small" onClick={() => void paymentsQuery.refetch()}>Retry</Button>} />}

    {clientMode && payableSessions.length > 0 && <section className="outstanding-payments">
      <header><div><span>Action required</span><h2>Outstanding sessions</h2></div><strong>{payableSessions.length}</strong></header>
      <div>{payableSessions.map((session) => <article key={session.id}>
        <span><small>{session.reference}</small><strong>{session.station.name}</strong><p>{session.energy_kwh.toFixed(3)} kWh · Connector {session.connector.external_id}</p></span>
        <div><ChargingStatusTag value={session.payment_status} /><strong>{session.total_amount} {session.currency}</strong><Button type="primary" onClick={() => setPaymentSession(session)}>{session.payment_status === 'failed' ? 'Retry payment' : 'Pay now'}</Button></div>
      </article>)}</div>
    </section>}

    <Card className="payments-table-card" title={clientMode ? 'Payment history' : 'All transactions'} extra={!clientMode && canExport && <Dropdown menu={{ items: [
      { key: 'csv', label: 'Export CSV', onClick: () => exportMutation.mutate('csv') },
      { key: 'json', label: 'Export JSON', onClick: () => exportMutation.mutate('json') },
    ] }}><Button icon={<Download size={14} />} loading={exportMutation.isPending}>Export</Button></Dropdown>}>
      <div className="sessions-toolbar"><Input value={search} onChange={(event) => setSearch(event.target.value)} prefix={<Search size={14} />} placeholder="Search payment or session" allowClear /><Select value={status} onChange={(value) => setStatus(value)} options={['all', 'paid', 'failed', 'pending'].map((value) => ({ value, label: value === 'all' ? 'All statuses' : value }))} /></div>
      <Table rowKey="id" columns={columns} dataSource={paymentsQuery.data?.data ?? []} loading={paymentsQuery.isLoading} pagination={{ pageSize: 8, hideOnSinglePage: true }} scroll={{ x: 900 }} locale={{ emptyText: <Empty description="No payment transactions found" /> }} />
    </Card>
    <PaymentDrawer open={Boolean(paymentSession)} session={paymentSession} submitting={paymentMutation.isPending} onClose={() => setPaymentSession(null)} onSubmit={(payload) => paymentSession && paymentMutation.mutate({ sessionId: paymentSession.id, payload })} />
  </div>
}

function paymentErrorMessage(error: unknown) {
  if (!axios.isAxiosError(error)) return 'Payment processing failed.'
  const data = error.response?.data as { message?: string; errors?: Record<string, string[]> } | undefined
  const validationMessage = data?.errors ? Object.values(data.errors).flat()[0] : undefined

  return validationMessage ?? data?.message ?? 'Payment processing failed.'
}
