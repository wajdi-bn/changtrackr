import { useDeferredValue, useEffect, useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Alert, App, Button, Card, Empty, Input, Select, Table } from 'antd'
import { getApiErrorMessage } from '../api/apiErrors'
import dayjs from 'dayjs'
import { CircleDollarSign, CreditCard, Eye, FileDown, ReceiptText, RefreshCw, Search } from 'lucide-react'
import type { ColumnsType } from 'antd/es/table'
import { useSearchParams } from 'react-router-dom'
import { MountainBanner } from '../components/MountainBanner'
import { ExportDropdown, type ExportFormat } from '../components/ExportDropdown'
import { MetricItem, MetricStrip } from '../components/MetricStrip'
import { exportPayments, getChargingSessions, getPayments, processPayment } from '../features/charging/chargingApi'
import { ChargingStatusTag } from '../features/charging/ChargingStatusTag'
import { PaymentDrawer } from '../features/charging/PaymentDrawer'
import { useAuth } from '../features/auth/useAuth'
import { downloadOperationalDocument } from '../features/reports/reportingApi'
import { OperationalDocumentPreviewModal, type OperationalPreviewTarget } from '../features/reports/OperationalDocumentPreviewModal'
import type { ChargingSession, Payment, PaymentPayload, PaymentStatus } from '../types/charging'
import { downloadBlob } from '../utils/downloadBlob'

export function PaymentsPage() {
  const [searchParams] = useSearchParams()
  const { primaryRole, user } = useAuth()
  const clientMode = primaryRole === 'client'
  const canExport = user?.permissions.includes('reports.export') ?? false
  const [search, setSearch] = useState(() => searchParams.get('search') ?? '')
  const deferredSearch = useDeferredValue(search)
  const [status, setStatus] = useState<'all' | PaymentStatus>('all')
  const [page, setPage] = useState(1)
  const [pageSize, setPageSize] = useState(8)
  const [paymentSession, setPaymentSession] = useState<ChargingSession | null>(null)
  const [receiptPreview, setReceiptPreview] = useState<OperationalPreviewTarget | null>(null)
  const queryClient = useQueryClient()
  const { message } = App.useApp()
  const listFilters = useMemo(() => ({ search: deferredSearch.trim() || undefined, status: status === 'all' ? undefined : status, page, per_page: pageSize }), [deferredSearch, page, pageSize, status])
  const exportFilters = useMemo(() => ({ search: deferredSearch.trim() || undefined, status: status === 'all' ? undefined : status }), [deferredSearch, status])
  const paymentsQuery = useQuery({ queryKey: ['payments', listFilters], queryFn: () => getPayments(listFilters) })
  const unpaidQuery = useQuery({ queryKey: ['charging-sessions', 'unpaid'], queryFn: () => getChargingSessions({ payment_status: 'unpaid', per_page: 100 }), enabled: clientMode })
  const failedQuery = useQuery({ queryKey: ['charging-sessions', 'failed-payment'], queryFn: () => getChargingSessions({ payment_status: 'failed', per_page: 100 }), enabled: clientMode })
  const payableSessions = useMemo(() => [...(unpaidQuery.data?.data ?? []), ...(failedQuery.data?.data ?? [])]
    .filter((session) => ['completed', 'interrupted'].includes(session.status)), [failedQuery.data?.data, unpaidQuery.data?.data])

  useEffect(() => {
    setSearch(searchParams.get('search') ?? '')
    setPage(1)
  }, [searchParams])

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
    onError: (error) => void message.error(getApiErrorMessage(error, 'Payment processing failed.')),
  })
  const exportMutation = useMutation({
    mutationFn: (format: ExportFormat) => exportPayments(exportFilters, format),
    onSuccess: (blob, format) => {
      downloadBlob(blob, `organization-payments.${format}`)
      void message.success(`Payment export generated as ${format.toUpperCase()}.`)
    },
    onError: () => void message.error('The payment export could not be generated.'),
  })
  const receiptMutation = useMutation({
    mutationFn: (payment: Payment) => downloadOperationalDocument('receipt', payment.id),
    onSuccess: (blob, payment) => downloadBlob(blob, `receipt-${payment.reference}.pdf`),
    onError: () => void message.error('The payment receipt could not be generated.'),
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
    { title: '', key: 'receipt', width: 92, align: 'right', render: (_: unknown, item) => <div className="payment-receipt-actions">
      <Button type="text" aria-label={`View receipt ${item.reference}`} icon={<Eye size={15} />} onClick={() => setReceiptPreview({ type: 'receipt', id: item.id, title: `Receipt ${item.reference}`, filename: `receipt-${item.reference}.pdf` })} />
      <Button type="text" aria-label={`Download receipt ${item.reference}`} icon={<FileDown size={15} />} loading={receiptMutation.isPending && receiptMutation.variables?.id === item.id} onClick={() => receiptMutation.mutate(item)} />
    </div> },
  ]

  return <div className="payments-page">
    <MountainBanner color="purple" breadcrumb={[clientMode ? 'Driver' : 'Operations', clientMode ? 'Payments & invoices' : 'Payments']} title={clientMode ? 'Payments & invoices' : 'Payment monitoring'} count={paymentsQuery.data?.summary.total ?? 0} subtitle={clientMode ? 'Pay completed charging sessions and keep a traceable receipt history.' : 'Monitor simulated provider outcomes, failed attempts, transaction references, and collected revenue.'} />
    <MetricStrip className="payment-kpis">
      <MetricItem icon={<ReceiptText size={18} />} label="Transactions" value={paymentsQuery.data?.summary.total ?? 0} tone="blue" />
      <MetricItem icon={<CircleDollarSign size={18} />} label="Paid" value={paymentsQuery.data?.summary.paid ?? 0} tone="green" />
      <MetricItem icon={<RefreshCw size={18} />} label="Failed" value={paymentsQuery.data?.summary.failed ?? 0} tone="red" />
      <MetricItem icon={<CreditCard size={18} />} label={clientMode ? 'Total paid' : 'Revenue'} value={`${((paymentsQuery.data?.summary.revenue_millimes ?? 0) / 1000).toFixed(3)} TND`} tone="purple" />
    </MetricStrip>

    {paymentsQuery.isError && <Alert className="payments-api-error" type="error" showIcon title="Unable to load payments" description={getApiErrorMessage(paymentsQuery.error, 'Check the API connection and retry.')} action={<Button size="small" onClick={() => void paymentsQuery.refetch()}>Retry</Button>} />}

    {clientMode && payableSessions.length > 0 && <section className="outstanding-payments">
      <header><div><span>Action required</span><h2>Outstanding sessions</h2></div><strong>{payableSessions.length}</strong></header>
      <div>{payableSessions.map((session) => <article key={session.id}>
        <span><small>{session.reference}</small><strong>{session.station.name}</strong><p>{session.energy_kwh.toFixed(3)} kWh · Connector {session.connector.external_id}</p></span>
        <div><ChargingStatusTag value={session.payment_status} /><strong>{session.total_amount} {session.currency}</strong><Button type="primary" onClick={() => setPaymentSession(session)}>{session.payment_status === 'failed' ? 'Retry payment' : 'Pay now'}</Button></div>
      </article>)}</div>
    </section>}

    <Card className="payments-table-card" title={clientMode ? 'Payment history' : 'All transactions'} extra={!clientMode && canExport && <ExportDropdown loading={exportMutation.isPending} onExport={(format) => exportMutation.mutate(format)} />}>
      <div className="sessions-toolbar"><Input value={search} onChange={(event) => { setSearch(event.target.value); setPage(1) }} prefix={<Search size={14} />} placeholder="Search payment or session" allowClear /><Select value={status} onChange={(value) => { setStatus(value); setPage(1) }} options={['all', 'paid', 'failed', 'pending'].map((value) => ({ value, label: value === 'all' ? 'All statuses' : value }))} /></div>
      <Table rowKey="id" columns={columns} dataSource={paymentsQuery.data?.data ?? []} loading={paymentsQuery.isLoading} pagination={{
        current: paymentsQuery.data?.meta.current_page ?? page,
        pageSize: paymentsQuery.data?.meta.per_page ?? pageSize,
        total: paymentsQuery.data?.meta.total ?? 0,
        hideOnSinglePage: true,
        showSizeChanger: true,
        pageSizeOptions: [8, 16, 32],
        onChange: (nextPage, nextPageSize) => {
          setPage(nextPageSize === pageSize ? nextPage : 1)
          setPageSize(nextPageSize)
        },
      }} scroll={{ x: 900 }} locale={{ emptyText: <Empty description="No payment transactions found" /> }} />
    </Card>
    <PaymentDrawer open={Boolean(paymentSession)} session={paymentSession} submitting={paymentMutation.isPending} onClose={() => setPaymentSession(null)} onSubmit={(payload) => paymentSession && paymentMutation.mutate({ sessionId: paymentSession.id, payload })} />
    <OperationalDocumentPreviewModal target={receiptPreview} onClose={() => setReceiptPreview(null)} />
  </div>
}
