import { useDeferredValue, useMemo, useState, type ReactNode } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { Alert, App, Avatar, Button, Drawer, Dropdown, Empty, Input, Pagination, Select, Skeleton, Tooltip } from 'antd'
import dayjs from 'dayjs'
import {
  BatteryCharging,
  CalendarClock,
  ChevronDown,
  Download,
  Eye,
  Grid2X2,
  List,
  Mail,
  MapPin,
  Phone,
  Search,
  Table2,
  UsersRound,
  WalletCards,
} from 'lucide-react'
import { MountainBanner } from '../components/MountainBanner'
import { UserDirectoryTabs } from '../components/UserDirectoryTabs'
import { exportCustomers, getCustomer, getCustomers } from '../features/customers/customerApi'
import type {
  Customer,
  CustomerActivityFilter,
  CustomerFilters,
  CustomerSort,
  CustomerStatus,
} from '../types/customer'

type CustomerView = 'table' | 'list' | 'grid'

export function CustomersPage() {
  const [search, setSearch] = useState('')
  const [status, setStatus] = useState<CustomerStatus | undefined>()
  const [lastActivity, setLastActivity] = useState<CustomerActivityFilter | undefined>()
  const [sort, setSort] = useState<CustomerSort>('latest')
  const [view, setView] = useState<CustomerView>('table')
  const [page, setPage] = useState(1)
  const [selectedCustomerId, setSelectedCustomerId] = useState<number | null>(null)
  const deferredSearch = useDeferredValue(search)
  const { message } = App.useApp()

  const filters = useMemo<CustomerFilters>(() => ({
    search: deferredSearch.trim() || undefined,
    status,
    last_activity: lastActivity,
    sort,
    page,
    per_page: 20,
  }), [deferredSearch, lastActivity, page, sort, status])

  const customersQuery = useQuery({
    queryKey: ['organization-customers', filters],
    queryFn: () => getCustomers(filters),
  })
  const customerQuery = useQuery({
    queryKey: ['organization-customer', selectedCustomerId],
    queryFn: () => getCustomer(selectedCustomerId as number),
    enabled: selectedCustomerId !== null,
  })
  const exportQuery = useMutation({
    mutationFn: (format: 'csv' | 'json') => exportCustomers(filters, format),
    onSuccess: (blob, format) => {
      const url = URL.createObjectURL(blob)
      const anchor = document.createElement('a')
      anchor.href = url
      anchor.download = `organization-customers.${format}`
      anchor.click()
      URL.revokeObjectURL(url)
      void message.success(`Customer export generated as ${format.toUpperCase()}.`)
    },
    onError: () => void message.error('The customer export could not be generated.'),
  })

  const customers = customersQuery.data?.data ?? []
  const summary = customersQuery.data?.summary
  const meta = customersQuery.data?.meta
  const setFilter = <T,>(setter: (value: T | undefined) => void, value: T | undefined) => {
    setter(value)
    setPage(1)
  }

  return <div className="users-page customers-page">
    <div className="users-banner-wrap">
      <MountainBanner
        color="cyan"
        breadcrumb={['Administrator', 'Users', 'Customers']}
        title="Customers"
        count={meta?.total ?? 0}
        subtitle="Customers are listed after using a station owned by your organization. Their accounts remain global and read-only."
      />
    </div>

    <UserDirectoryTabs />

    <div className="customer-kpis">
      <CustomerKpi icon={<UsersRound size={16} />} label="Organization customers" value={summary?.total ?? 0} tone="green" />
      <CustomerKpi icon={<CalendarClock size={16} />} label="Active in 30 days" value={summary?.active_30_days ?? 0} tone="blue" />
      <CustomerKpi icon={<BatteryCharging size={16} />} label="Charging activity" value={`${summary?.sessions ?? 0} sessions - ${formatEnergy(summary?.energy_kwh ?? 0)}`} tone="purple" />
      <CustomerKpi icon={<WalletCards size={16} />} label="Paid revenue" value={formatMoney(summary?.revenue_millimes ?? 0)} tone="orange" />
    </div>

    <div className="users-toolbar customers-toolbar">
      <Input value={search} onChange={(event) => { setSearch(event.target.value); setPage(1) }} prefix={<Search size={14} />} placeholder="Search customers" allowClear />
      <FilterSelect value={status} placeholder="Status: All" options={[
        { value: 'active', label: 'Active' },
        { value: 'inactive', label: 'Inactive' },
        { value: 'pending', label: 'Pending' },
      ]} onChange={(value) => setFilter(setStatus, value)} />
      <FilterSelect value={lastActivity} placeholder="Activity: Any time" options={[
        { value: 'today', label: 'Today' },
        { value: 'week', label: 'This week' },
        { value: 'month', label: 'This month' },
      ]} onChange={(value) => setFilter(setLastActivity, value)} />
      <Select<CustomerSort> value={sort} options={[
        { value: 'latest', label: 'Latest activity' },
        { value: 'name', label: 'Customer name' },
        { value: 'sessions', label: 'Most sessions' },
        { value: 'energy', label: 'Most energy' },
        { value: 'spent', label: 'Highest revenue' },
      ]} onChange={(value) => { setSort(value); setPage(1) }} />
      <ViewMode value={view} onChange={setView} />
      <Dropdown menu={{
        items: [{ key: 'csv', label: 'Export CSV' }, { key: 'json', label: 'Export JSON' }],
        onClick: ({ key }) => exportQuery.mutate(key as 'csv' | 'json'),
      }}>
        <Button className="users-export-button" loading={exportQuery.isPending}><Download size={14} />Export<ChevronDown size={13} /></Button>
      </Dropdown>
    </div>

    {customersQuery.isError && <Alert className="users-api-error" type="error" showIcon title="Unable to load customers" description="Make sure the Laravel API is running, then retry." action={<Button size="small" onClick={() => void customersQuery.refetch()}>Retry</Button>} />}

    <section className="prototype-section-card">
      <header><h2>Customer activity</h2><p>This directory is derived from charging sessions on stations owned by your organization.</p></header>
      <div>
        {customersQuery.isLoading ? <CustomerLoading /> : customers.length === 0 ? <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="No customer matches the current filters" /> : <>
          {view === 'table' && <CustomerTable customers={customers} onSelect={(customer) => setSelectedCustomerId(customer.id)} />}
          {view === 'list' && <CustomerList customers={customers} onSelect={(customer) => setSelectedCustomerId(customer.id)} />}
          {view === 'grid' && <CustomerGrid customers={customers} onSelect={(customer) => setSelectedCustomerId(customer.id)} />}
        </>}
        {meta && meta.last_page > 1 && <Pagination className="users-pagination" current={meta.current_page} total={meta.total} pageSize={meta.per_page} showSizeChanger={false} onChange={setPage} />}
      </div>
    </section>

    <CustomerDrawer customer={customerQuery.data ?? null} loading={customerQuery.isLoading} open={selectedCustomerId !== null} onClose={() => setSelectedCustomerId(null)} />
  </div>
}

function FilterSelect<T extends string>({ value, placeholder, options, onChange }: { value?: T; placeholder: string; options: Array<{ value: T; label: string }>; onChange: (value?: T) => void }) {
  return <Select<T> value={value} placeholder={placeholder} options={options} allowClear onChange={onChange} />
}

function ViewMode({ value, onChange }: { value: CustomerView; onChange: (value: CustomerView) => void }) {
  const items: Array<{ value: CustomerView; label: string; icon: ReactNode }> = [
    { value: 'table', label: 'Table view', icon: <Table2 size={16} /> },
    { value: 'list', label: 'List view', icon: <List size={16} /> },
    { value: 'grid', label: 'Grid view', icon: <Grid2X2 size={16} /> },
  ]
  return <div className="users-view-mode">{items.map((item) => <Tooltip key={item.value} title={item.label}><button type="button" className={value === item.value ? 'active' : ''} aria-label={item.label} onClick={() => onChange(item.value)}>{item.icon}</button></Tooltip>)}</div>
}

function CustomerKpi({ icon, label, value, tone }: { icon: ReactNode; label: string; value: string | number; tone: string }) {
  return <article className={`customer-kpi customer-kpi--${tone}`}><span>{icon}</span><div><small>{label}</small><strong>{value}</strong></div></article>
}

function CustomerTable({ customers, onSelect }: CustomerViewProps) {
  return <div className="users-table-wrap"><table className="users-table customers-table">
    <thead><tr>{['Avatar', 'Customer', 'Contact', 'Status', 'Sessions', 'Stations', 'Energy', 'Paid revenue', 'Last activity', 'Actions'].map((heading) => <th key={heading}>{heading}</th>)}</tr></thead>
    <tbody>{customers.map((customer) => <tr key={customer.id}>
      <td><CustomerAvatar customer={customer} size={36} /></td>
      <td><strong>{customer.name}</strong></td>
      <td><span className="customer-contact"><strong>{customer.email}</strong><small>{customer.phone ?? 'No phone'}</small></span></td>
      <td><CustomerStatusTag status={customer.status} /></td>
      <td>{customer.activity.sessions}</td>
      <td>{customer.activity.stations}</td>
      <td>{formatEnergy(customer.activity.energy_kwh)}</td>
      <td>{formatMoney(customer.activity.paid_millimes)}</td>
      <td>{formatActivity(customer.activity.last_session_at)}</td>
      <td><ViewCustomerButton customer={customer} onSelect={onSelect} /></td>
    </tr>)}</tbody>
  </table></div>
}

function CustomerList({ customers, onSelect }: CustomerViewProps) {
  return <div className="users-list customer-list">{customers.map((customer) => <article key={customer.id}>
    <div className="users-list-identity"><CustomerAvatar customer={customer} size={44} /><span><strong>{customer.name}</strong><small>{customer.email} - {customer.phone ?? 'No phone'}</small></span></div>
    <p>{customer.activity.sessions} sessions across {customer.activity.stations} stations - {formatEnergy(customer.activity.energy_kwh)}</p>
    <CustomerStatusTag status={customer.status} />
    <ViewCustomerButton customer={customer} onSelect={onSelect} />
  </article>)}</div>
}

function CustomerGrid({ customers, onSelect }: CustomerViewProps) {
  return <div className="users-grid customer-grid">{customers.map((customer) => <article key={customer.id}>
    <header><div><CustomerAvatar customer={customer} size={48} /><span><strong>{customer.name}</strong><small>{customer.email}</small></span></div><CustomerStatusTag status={customer.status} /></header>
    <div className="customer-card-metrics"><span><small>Sessions</small><strong>{customer.activity.sessions}</strong></span><span><small>Energy</small><strong>{formatEnergy(customer.activity.energy_kwh)}</strong></span><span><small>Revenue</small><strong>{formatMoney(customer.activity.paid_millimes)}</strong></span></div>
    <footer><span>{formatActivity(customer.activity.last_session_at)}</span><ViewCustomerButton customer={customer} onSelect={onSelect} /></footer>
  </article>)}</div>
}

interface CustomerViewProps {
  customers: Customer[]
  onSelect: (customer: Customer) => void
}

function ViewCustomerButton({ customer, onSelect }: { customer: Customer; onSelect: (customer: Customer) => void }) {
  return <div className="user-row-actions"><Tooltip title="View customer activity"><button type="button" className="view" aria-label={`View ${customer.name}`} onClick={() => onSelect(customer)}><Eye size={15} /></button></Tooltip></div>
}

function CustomerDrawer({ customer, loading, open, onClose }: { customer: Customer | null; loading: boolean; open: boolean; onClose: () => void }) {
  return <Drawer className="user-detail-drawer customer-detail-drawer" size={560} open={open} onClose={onClose} title={customer ? <div className="user-drawer-title"><CustomerAvatar customer={customer} size={48} /><span><strong>{customer.name}</strong><small>Global customer - organization activity</small></span></div> : 'Customer activity'}>
    {loading ? <CustomerLoading /> : customer && <div className="user-drawer-content">
      <div className="user-contact-grid">
        <InfoPanel icon={<Mail size={14} />} label="Email" value={customer.email} />
        <InfoPanel icon={<Phone size={14} />} label="Phone" value={customer.phone ?? 'Not provided'} />
        <InfoPanel icon={<MapPin size={14} />} label="Address" value={customer.address ?? 'Not provided'} />
        <InfoPanel icon={<CalendarClock size={14} />} label="Last activity" value={formatActivity(customer.activity.last_session_at)} />
      </div>
      <section className="prototype-section-card"><header><h2>Activity with your organization</h2><p>Metrics exclude sessions performed on other charging networks.</p></header><div className="customer-detail-metrics">
        <span><small>Sessions</small><strong>{customer.activity.sessions}</strong></span>
        <span><small>Stations visited</small><strong>{customer.activity.stations}</strong></span>
        <span><small>Energy delivered</small><strong>{formatEnergy(customer.activity.energy_kwh)}</strong></span>
        <span><small>Paid revenue</small><strong>{formatMoney(customer.activity.paid_millimes)}</strong></span>
        <span><small>Outstanding</small><strong>{formatMoney(customer.activity.outstanding_millimes)}</strong></span>
        <span><small>First visit</small><strong>{formatDate(customer.activity.first_session_at)}</strong></span>
      </div></section>
      <section className="prototype-section-card"><header><h2>Recent charging sessions</h2><p>The five latest sessions on this organization&apos;s stations.</p></header><div className="customer-session-list">
        {(customer.recent_sessions ?? []).map((session) => <article key={session.id}><span><BatteryCharging size={15} /><div><strong>{session.station.name}</strong><small>{session.reference} - {formatDate(session.started_at)}</small></div></span><div><strong>{formatEnergy(session.energy_kwh)}</strong><small>{formatMoney(session.total_millimes)}</small></div></article>)}
        {(customer.recent_sessions ?? []).length === 0 && <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="No session available" />}
      </div></section>
    </div>}
  </Drawer>
}

function CustomerAvatar({ customer, size }: { customer: Customer; size: number }) {
  return <Avatar className={`managed-user-avatar managed-user-avatar--${customer.id % 4}`} size={size} src={customer.avatar_url ?? undefined}>{initials(customer.name)}</Avatar>
}

function CustomerStatusTag({ status }: { status: CustomerStatus }) {
  return <span className={`user-status user-status--${status}`}><i />{status}</span>
}

function InfoPanel({ icon, label, value }: { icon: ReactNode; label: string; value: string }) {
  return <div className="user-info-panel"><span>{icon}</span><div><small>{label}</small><strong>{value}</strong></div></div>
}

function CustomerLoading() {
  return <div className="users-loading">{Array.from({ length: 4 }, (_, index) => <Skeleton key={index} active avatar paragraph={{ rows: 1 }} />)}</div>
}

function initials(name: string): string {
  return name.split(' ').filter(Boolean).slice(0, 2).map((part) => part[0]).join('').toUpperCase()
}

function formatEnergy(value: number): string {
  return `${value.toLocaleString('en-US', { maximumFractionDigits: 1 })} kWh`
}

function formatMoney(millimes: number): string {
  return `${(millimes / 1000).toLocaleString('en-US', { minimumFractionDigits: 3, maximumFractionDigits: 3 })} TND`
}

function formatActivity(value: string | null): string {
  if (!value) return 'No activity'
  const days = dayjs().diff(dayjs(value), 'day')
  if (days === 0) return 'Today'
  if (days === 1) return 'Yesterday'
  if (days < 7) return `${days} days ago`
  return formatDate(value)
}

function formatDate(value: string | null): string {
  return value ? dayjs(value).format('DD MMM YYYY') : 'Not available'
}
