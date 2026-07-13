import { useDeferredValue, useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { App, Button, DatePicker, Drawer, Empty, Form, Input, InputNumber, Popconfirm, Segmented, Select, Space, Switch, Table, Tag, Tooltip } from 'antd'
import type { ColumnsType } from 'antd/es/table'
import dayjs, { type Dayjs } from 'dayjs'
import { CalendarDays, Link2, PencilLine, Plus, Search, ShieldCheck, Tags, Trash2 } from 'lucide-react'
import { MountainBanner } from '../components/MountainBanner'
import { useAuth } from '../features/auth/useAuth'
import { getStations } from '../features/stations/stationApi'
import {
  assignTariff,
  createTariff,
  deleteTariff,
  getTariffs,
  removeTariffAssignment,
  updateTariff,
} from '../features/tariffs/tariffApi'
import type { Station } from '../types/station'
import type { Tariff, TariffPayload, TariffStatus } from '../types/tariff'

type TariffFormValues = Omit<TariffPayload, 'price_per_kwh_millimes' | 'session_fee_millimes' | 'idle_fee_per_minute_millimes' | 'minimum_charge_millimes' | 'valid_from' | 'valid_until'> & {
  price_per_kwh: number
  session_fee: number
  idle_fee_per_minute: number
  minimum_charge: number
  validity?: [Dayjs, Dayjs]
}

export function TariffsPage() {
  const { user } = useAuth()
  const canManage = user?.permissions.includes('tariffs.manage') ?? false
  const [search, setSearch] = useState('')
  const deferredSearch = useDeferredValue(search)
  const [status, setStatus] = useState<'all' | TariffStatus>('all')
  const [editorTariff, setEditorTariff] = useState<Tariff | null | undefined>(undefined)
  const [assignmentTariff, setAssignmentTariff] = useState<Tariff | null>(null)
  const queryClient = useQueryClient()
  const { message } = App.useApp()
  const filters = useMemo(() => ({ search: deferredSearch.trim() || undefined, status: status === 'all' ? undefined : status }), [deferredSearch, status])
  const tariffsQuery = useQuery({ queryKey: ['tariffs', filters], queryFn: () => getTariffs(filters) })
  const stationsQuery = useQuery({ queryKey: ['stations', 'tariff-assignment'], queryFn: () => getStations({}), enabled: canManage })

  const refreshTariffs = () => queryClient.invalidateQueries({ queryKey: ['tariffs'] })
  const saveMutation = useMutation({
    mutationFn: ({ tariff, payload }: { tariff: Tariff | null; payload: TariffPayload }) => tariff ? updateTariff(tariff.id, payload) : createTariff(payload),
    onSuccess: async (_, variables) => {
      await refreshTariffs()
      setEditorTariff(undefined)
      void message.success(variables.tariff ? 'Tariff updated.' : 'Tariff created.')
    },
    onError: () => void message.error('The tariff could not be saved. Check the code and validity period.'),
  })
  const deleteMutation = useMutation({
    mutationFn: deleteTariff,
    onSuccess: async () => { await refreshTariffs(); void message.success('Tariff archived from the active catalog.') },
    onError: () => void message.error('The tariff could not be deleted.'),
  })
  const assignmentMutation = useMutation({
    mutationFn: ({ tariffId, payload }: { tariffId: number; payload: { station_id?: number; connector_id?: number } }) => assignTariff(tariffId, payload),
    onSuccess: async () => { await refreshTariffs(); setAssignmentTariff(null); void message.success('Tariff assignment saved.') },
    onError: () => void message.error('The tariff could not be assigned to this target.'),
  })
  const removeAssignmentMutation = useMutation({
    mutationFn: removeTariffAssignment,
    onSuccess: async () => { await refreshTariffs(); void message.success('Assignment removed.') },
    onError: () => void message.error('The assignment could not be removed.'),
  })

  const columns: ColumnsType<Tariff> = [
    { title: 'Tariff', key: 'tariff', render: (_: unknown, tariff) => <span className="tariff-name"><strong>{tariff.name}{tariff.is_default && <Tag color="gold">Default</Tag>}</strong><small>{tariff.code}</small></span> },
    { title: 'Status', dataIndex: 'status', key: 'status', render: (value: TariffStatus) => <Tag color={value === 'active' ? 'green' : value === 'draft' ? 'gold' : 'default'}>{value}</Tag> },
    { title: 'Energy rate', key: 'rate', render: (_: unknown, tariff) => <strong>{millimesToTnd(tariff.price_per_kwh_millimes)} TND/kWh</strong> },
    { title: 'Session fee', key: 'session_fee', render: (_: unknown, tariff) => `${millimesToTnd(tariff.session_fee_millimes)} TND` },
    { title: 'Idle fee', key: 'idle_fee', render: (_: unknown, tariff) => `${millimesToTnd(tariff.idle_fee_per_minute_millimes)} TND/min` },
    { title: 'Minimum', key: 'minimum', render: (_: unknown, tariff) => `${millimesToTnd(tariff.minimum_charge_millimes)} TND` },
    { title: 'Validity', key: 'validity', render: (_: unknown, tariff) => <span className="tariff-validity"><CalendarDays size={12} />{formatValidity(tariff)}</span> },
    { title: 'Assignments', key: 'assignments', render: (_: unknown, tariff) => <div className="tariff-assignment-tags">{tariff.assignments.length === 0 ? <small>Organization default only</small> : tariff.assignments.slice(0, 3).map((assignment) => <Tag key={assignment.id} closable={canManage} onClose={(event) => { event.preventDefault(); removeAssignmentMutation.mutate(assignment.id) }}>{assignment.connector ? `${assignment.station?.name} · ${assignment.connector.external_id}` : assignment.station?.name}</Tag>)}{tariff.assignments.length > 3 && <Tag>+{tariff.assignments.length - 3}</Tag>}</div> },
    ...(canManage ? [{
      title: '', key: 'actions', align: 'right' as const, render: (_: unknown, tariff: Tariff) => <Space size={2}>
        <Tooltip title="Assign tariff"><Button type="text" icon={<Link2 size={14} />} onClick={() => setAssignmentTariff(tariff)} /></Tooltip>
        <Tooltip title="Edit tariff"><Button type="text" icon={<PencilLine size={14} />} onClick={() => setEditorTariff(tariff)} /></Tooltip>
        <Popconfirm title="Delete this tariff?" description="Existing sessions keep their pricing snapshot." okButtonProps={{ danger: true }} onConfirm={() => deleteMutation.mutate(tariff.id)}><Tooltip title="Delete tariff"><Button type="text" danger icon={<Trash2 size={14} />} /></Tooltip></Popconfirm>
      </Space>,
    }] : []),
  ]

  return <div className="tariffs-page">
    <MountainBanner color="gold" breadcrumb={['Administration', 'Tariffs & pricing']} title="Tariffs & pricing" count={tariffsQuery.data?.summary.total ?? 0} subtitle="Configure organization rates, validity periods, and station or connector-specific pricing." />
    <div className="tariff-kpis">
      <TariffKpi icon={<Tags size={18} />} label="Tariffs" value={tariffsQuery.data?.summary.total ?? 0} />
      <TariffKpi icon={<ShieldCheck size={18} />} label="Active" value={tariffsQuery.data?.summary.active ?? 0} />
      <TariffKpi icon={<PencilLine size={18} />} label="Draft" value={tariffsQuery.data?.summary.draft ?? 0} />
      <TariffKpi icon={<Link2 size={18} />} label="Assignments" value={tariffsQuery.data?.summary.assignments ?? 0} />
    </div>
    <section className="tariff-catalog">
      <header><div><span>Pricing catalog</span><h2>Organization tariffs</h2></div>{canManage && <Button type="primary" icon={<Plus size={15} />} onClick={() => setEditorTariff(null)}>Create tariff</Button>}</header>
      <div className="tariff-toolbar"><Input value={search} onChange={(event) => setSearch(event.target.value)} prefix={<Search size={14} />} placeholder="Search tariff or code" allowClear /><Select value={status} onChange={(value) => setStatus(value)} options={['all', 'active', 'draft', 'archived'].map((value) => ({ value, label: value === 'all' ? 'All statuses' : value }))} /></div>
      <Table rowKey="id" columns={columns} dataSource={tariffsQuery.data?.data ?? []} loading={tariffsQuery.isLoading} pagination={false} scroll={{ x: 1150 }} locale={{ emptyText: <Empty description="No tariffs found" /> }} />
    </section>
    <TariffFormDrawer open={editorTariff !== undefined} tariff={editorTariff ?? null} submitting={saveMutation.isPending} onClose={() => setEditorTariff(undefined)} onSubmit={(payload) => saveMutation.mutate({ tariff: editorTariff ?? null, payload })} />
    <TariffAssignmentDrawer open={Boolean(assignmentTariff)} tariff={assignmentTariff} stations={stationsQuery.data?.data ?? []} submitting={assignmentMutation.isPending} onClose={() => setAssignmentTariff(null)} onSubmit={(payload) => assignmentTariff && assignmentMutation.mutate({ tariffId: assignmentTariff.id, payload })} />
  </div>
}

function TariffFormDrawer({ open, tariff, submitting, onClose, onSubmit }: { open: boolean; tariff: Tariff | null; submitting: boolean; onClose: () => void; onSubmit: (payload: TariffPayload) => void }) {
  const [form] = Form.useForm<TariffFormValues>()
  return <Drawer open={open} title={tariff ? 'Edit tariff' : 'Create tariff'} size={560} onClose={onClose} extra={<Button type="primary" loading={submitting} onClick={() => form.submit()}>Save tariff</Button>} afterOpenChange={(visible) => {
    if (!visible) return
    form.setFieldsValue(tariff ? {
      name: tariff.name, code: tariff.code, description: tariff.description, status: tariff.status, currency: 'TND',
      price_per_kwh: tariff.price_per_kwh_millimes / 1000, session_fee: tariff.session_fee_millimes / 1000,
      idle_fee_per_minute: tariff.idle_fee_per_minute_millimes / 1000, minimum_charge: tariff.minimum_charge_millimes / 1000,
      validity: tariff.valid_from && tariff.valid_until ? [dayjs(tariff.valid_from), dayjs(tariff.valid_until)] : undefined,
      is_default: tariff.is_default,
    } : { status: 'draft', currency: 'TND', price_per_kwh: 0.85, session_fee: 0.5, idle_fee_per_minute: 0.1, minimum_charge: 1, is_default: false })
  }}>
    <Form form={form} layout="vertical" requiredMark="optional" onFinish={(values) => onSubmit({
      name: values.name, code: values.code.toUpperCase(), description: values.description, status: values.status, currency: 'TND',
      price_per_kwh_millimes: tndToMillimes(values.price_per_kwh), session_fee_millimes: tndToMillimes(values.session_fee),
      idle_fee_per_minute_millimes: tndToMillimes(values.idle_fee_per_minute), minimum_charge_millimes: tndToMillimes(values.minimum_charge),
      valid_from: values.validity?.[0].toISOString() ?? null, valid_until: values.validity?.[1].toISOString() ?? null, is_default: values.is_default,
    })}>
      <div className="tariff-form-grid"><Form.Item label="Tariff name" name="name" rules={[{ required: true }]}><Input placeholder="Standard charging" /></Form.Item><Form.Item label="Code" name="code" rules={[{ required: true }]}><Input placeholder="STANDARD" /></Form.Item></div>
      <Form.Item label="Description" name="description"><Input.TextArea rows={3} placeholder="Internal pricing purpose and scope" /></Form.Item>
      <div className="tariff-form-grid"><Form.Item label="Status" name="status" rules={[{ required: true }]}><Select options={['draft', 'active', 'archived'].map((value) => ({ value, label: value }))} /></Form.Item><Form.Item label="Validity period" name="validity"><DatePicker.RangePicker showTime style={{ width: '100%' }} /></Form.Item></div>
      <div className="tariff-form-grid"><MoneyField label="Energy price" name="price_per_kwh" suffix="TND/kWh" /><MoneyField label="Session fee" name="session_fee" suffix="TND" /><MoneyField label="Idle fee" name="idle_fee_per_minute" suffix="TND/min" /><MoneyField label="Minimum charge" name="minimum_charge" suffix="TND" /></div>
      <Form.Item label="Organization default" name="is_default" valuePropName="checked"><Switch checkedChildren="Default" unCheckedChildren="Specific" /></Form.Item>
    </Form>
  </Drawer>
}

function MoneyField({ label, name, suffix }: { label: string; name: keyof TariffFormValues; suffix: string }) {
  return <Form.Item label={label} name={name} rules={[{ required: true }]}><InputNumber min={0} max={1000} precision={3} step={0.05} addonAfter={suffix} style={{ width: '100%' }} /></Form.Item>
}

function TariffAssignmentDrawer({ open, tariff, stations, submitting, onClose, onSubmit }: { open: boolean; tariff: Tariff | null; stations: Station[]; submitting: boolean; onClose: () => void; onSubmit: (payload: { station_id?: number; connector_id?: number }) => void }) {
  const [form] = Form.useForm<{ target: 'station' | 'connector'; station_id: number; connector_id?: number }>()
  const target = Form.useWatch('target', form) ?? 'station'
  const stationId = Form.useWatch('station_id', form)
  const station = stations.find((item) => item.id === stationId)
  return <Drawer open={open} title={`Assign ${tariff?.name ?? 'tariff'}`} size={480} onClose={onClose} afterOpenChange={(visible) => visible && form.setFieldsValue({ target: 'station', station_id: undefined, connector_id: undefined })}>
    <div className="assignment-intro"><Link2 size={19} /><p>A connector assignment overrides its station tariff. A station assignment overrides the organization default.</p></div>
    <Form form={form} layout="vertical" requiredMark="optional" onFinish={(values) => onSubmit(values.target === 'connector' ? { connector_id: values.connector_id } : { station_id: values.station_id })}>
      <Form.Item label="Target level" name="target"><Segmented block options={[{ value: 'station', label: 'Charging station' }, { value: 'connector', label: 'Specific connector' }]} onChange={() => form.setFieldValue('connector_id', undefined)} /></Form.Item>
      <Form.Item label="Station" name="station_id" rules={[{ required: true }]}><Select showSearch optionFilterProp="label" options={stations.map((item) => ({ value: item.id, label: `${item.name} - ${item.city}` }))} /></Form.Item>
      {target === 'connector' && <Form.Item label="Connector" name="connector_id" rules={[{ required: true }]}><Select disabled={!station} options={(station?.connectors ?? []).map((connector) => ({ value: connector.id, label: `${connector.external_id} - ${connector.type} - ${connector.max_power_kw} kW` }))} /></Form.Item>}
      <Button type="primary" htmlType="submit" icon={<Link2 size={14} />} loading={submitting} block>Save assignment</Button>
    </Form>
  </Drawer>
}

function TariffKpi({ icon, label, value }: { icon: React.ReactNode; label: string; value: number }) { return <div><span>{icon}</span><small>{label}</small><strong>{value}</strong></div> }
function tndToMillimes(value: number) { return Math.round(value * 1000) }
function millimesToTnd(value: number) { return (value / 1000).toFixed(3) }
function formatValidity(tariff: Tariff) { return tariff.valid_from && tariff.valid_until ? `${dayjs(tariff.valid_from).format('DD MMM YY')} - ${dayjs(tariff.valid_until).format('DD MMM YY')}` : 'No expiry' }
