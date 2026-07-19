import { useEffect, useState, type ReactNode } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Alert, App, Button, DatePicker, Drawer, Empty, Form, Input, InputNumber, Modal, Popconfirm, Segmented, Select, Switch, Tooltip } from 'antd'
import dayjs, { type Dayjs } from 'dayjs'
import { Calculator, Link2, PencilLine, Plus, Trash2 } from 'lucide-react'
import { MountainBanner } from '../components/MountainBanner'
import { CompactInputNumber } from '../components/CompactInputNumber'
import { useAuth } from '../features/auth/useAuth'
import { getStations } from '../features/stations/stationApi'
import {
  assignTariff,
  createChargingPlan,
  createTariff,
  deleteChargingPlan,
  deleteTariff,
  getChargingPlans,
  getTariffs,
  removeTariffAssignment,
  simulatePricing,
  updateChargingPlan,
  updateTariff,
} from '../features/tariffs/tariffApi'
import type { Station } from '../types/station'
import type { ChargingPlan, ChargingPlanPayload, PricingSimulationPayload, Tariff, TariffAssignment, TariffPayload } from '../types/tariff'

type TariffTab = 'rules' | 'plans' | 'assignments' | 'simulator'

type TariffFormValues = Omit<TariffPayload, 'price_per_kwh_millimes' | 'session_fee_millimes' | 'idle_fee_per_minute_millimes' | 'minimum_charge_millimes' | 'valid_from' | 'valid_until'> & {
  price_per_kwh: number
  session_fee: number
  idle_fee_per_minute: number
  minimum_charge: number
  validity?: [Dayjs, Dayjs]
}

type PlanFormValues = Omit<ChargingPlanPayload, 'monthly_fee_millimes' | 'discount_basis_points'> & {
  monthly_fee: number
  discount_percent: number
}

type AssignmentContext = { tariff: Tariff | null; assignment?: TariffAssignment }

const tabs: Array<{ key: TariffTab; label: string }> = [
  { key: 'rules', label: 'Tariff Rules' },
  { key: 'plans', label: 'Plans' },
  { key: 'assignments', label: 'Station Assignment' },
  { key: 'simulator', label: 'Pricing Simulator' },
]

export function TariffsPage() {
  const { user } = useAuth()
  const canManage = user?.permissions.includes('tariffs.manage') ?? false
  const [activeTab, setActiveTab] = useState<TariffTab>('rules')
  const [editorTariff, setEditorTariff] = useState<Tariff | null | undefined>(undefined)
  const [editorPlan, setEditorPlan] = useState<ChargingPlan | null | undefined>(undefined)
  const [assignmentContext, setAssignmentContext] = useState<AssignmentContext>()
  const [simStationId, setSimStationId] = useState<number>()
  const [simConnectorId, setSimConnectorId] = useState<number>()
  const [simPlanId, setSimPlanId] = useState<number>()
  const [simEnergy, setSimEnergy] = useState(32)
  const [simDuration, setSimDuration] = useState(45)
  const [simIdleMinutes, setSimIdleMinutes] = useState(0)
  const queryClient = useQueryClient()
  const { message } = App.useApp()
  const tariffsQuery = useQuery({ queryKey: ['tariffs'], queryFn: () => getTariffs() })
  const plansQuery = useQuery({ queryKey: ['charging-plans'], queryFn: getChargingPlans })
  const stationsQuery = useQuery({ queryKey: ['stations', 'tariff-assignment'], queryFn: () => getStations({}) })

  const tariffs = tariffsQuery.data?.data ?? []
  const plans = plansQuery.data ?? []
  const stations = stationsQuery.data?.data ?? []
  const effectiveStationId = simStationId ?? stations[0]?.id
  const selectedStation = stations.find((station) => station.id === effectiveStationId)
  const effectiveConnectorId = simConnectorId
  const assignments = tariffs.flatMap((tariff) => tariff.assignments.map((assignment) => ({ tariff, assignment })))

  const refreshTariffs = () => queryClient.invalidateQueries({ queryKey: ['tariffs'] })
  const refreshPlans = () => queryClient.invalidateQueries({ queryKey: ['charging-plans'] })
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
  const savePlanMutation = useMutation({
    mutationFn: ({ plan, payload }: { plan: ChargingPlan | null; payload: ChargingPlanPayload }) => plan ? updateChargingPlan(plan.id, payload) : createChargingPlan(payload),
    onSuccess: async (_, variables) => {
      await refreshPlans()
      setEditorPlan(undefined)
      void message.success(variables.plan ? 'Plan updated.' : 'Plan created.')
    },
    onError: () => void message.error('The charging plan could not be saved. Check its code and values.'),
  })
  const deletePlanMutation = useMutation({
    mutationFn: deleteChargingPlan,
    onSuccess: async () => { await refreshPlans(); void message.success('Charging plan deleted.') },
    onError: () => void message.error('The charging plan could not be deleted.'),
  })
  const assignmentMutation = useMutation({
    mutationFn: ({ tariffId, payload }: { tariffId: number; payload: { station_id?: number; connector_id?: number } }) => assignTariff(tariffId, payload),
    onSuccess: async () => { await refreshTariffs(); setAssignmentContext(undefined); void message.success('Tariff assignment saved.') },
    onError: () => void message.error('The tariff could not be assigned to this target.'),
  })
  const removeAssignmentMutation = useMutation({
    mutationFn: removeTariffAssignment,
    onSuccess: async () => { await refreshTariffs(); void message.success('Assignment removed.') },
    onError: () => void message.error('The assignment could not be removed.'),
  })
  const simulationMutation = useMutation({
    mutationFn: simulatePricing,
    onError: () => void message.error('The price could not be simulated for this station and connector.'),
  })

  const runSimulation = () => {
    if (!effectiveStationId) {
      void message.warning('Select a station before calculating the estimate.')
      return
    }
    const payload: PricingSimulationPayload = {
      station_id: effectiveStationId,
      connector_id: effectiveConnectorId,
      charging_plan_id: simPlanId,
      energy_kwh: simEnergy,
      duration_minutes: simDuration,
      idle_minutes: simIdleMinutes,
    }
    simulationMutation.mutate(payload)
  }

  const openContextAction = () => {
    if (activeTab === 'plans') setEditorPlan(null)
    else if (activeTab === 'assignments') setAssignmentContext({ tariff: null })
    else setEditorTariff(null)
  }
  const contextAction = activeTab === 'plans' ? 'Create plan' : activeTab === 'assignments' ? 'Assign tariff' : 'Create tariff'

  return <div className="tariffs-page">
    <div className="tariffs-banner-wrap">
      <MountainBanner
        color="gold"
        breadcrumb={['Administrator', 'Tariffs & Pricing']}
        title="Tariffs & Pricing"
        subtitle="Configure charging rules, plans, station assignments, and simulate customer pricing."
      />
    </div>

    <div className="tariff-tabs-bar">
      {tabs.map((tab) => <button key={tab.key} type="button" className={activeTab === tab.key ? 'active' : ''} onClick={() => setActiveTab(tab.key)}>{tab.label}</button>)}
      <span />
      {canManage && activeTab !== 'simulator' && <button type="button" className="tariff-create-button" onClick={openContextAction}><Plus size={15} />{contextAction}</button>}
    </div>

    {activeTab === 'rules' && <SectionCard title="Tariff rules" subtitle="Charging prices, fees, VAT, applied stations, and lifecycle status.">
      <div className="prototype-tariff-table-wrap">
        <table className="prototype-tariff-table">
          <thead><tr><th>Tariff name</th><th>Type</th><th>Price per kWh</th><th>Price per minute</th><th>Start fee</th><th>Idle fee</th><th>Minimum charge</th><th>VAT</th><th>Applied stations</th><th>Status</th>{canManage && <th>Actions</th>}</tr></thead>
          <tbody>
            {tariffs.map((tariff) => <tr key={tariff.id}>
              <td><span className="tariff-rule-name"><strong>{tariff.name}</strong><small>{tariff.code}{tariff.is_default ? ' / Organization default' : ''}</small></span></td>
              <td>{getTariffType(tariff)}</td>
              <td><strong>{millimesToTnd(tariff.price_per_kwh_millimes)} TND</strong></td>
              <td>0.000 TND</td>
              <td>{millimesToTnd(tariff.session_fee_millimes)} TND</td>
              <td>{millimesToTnd(tariff.idle_fee_per_minute_millimes)} TND</td>
              <td>{millimesToTnd(tariff.minimum_charge_millimes)} TND</td>
              <td>0%</td>
              <td>{countAssignedStations(tariff)}</td>
              <td><StatusBadge status={tariff.status} /></td>
              {canManage && <td><div className="tariff-row-actions">
                <Tooltip title="Assign tariff"><button type="button" onClick={() => setAssignmentContext({ tariff })}><Link2 size={14} /></button></Tooltip>
                <Tooltip title="Edit tariff"><button type="button" onClick={() => setEditorTariff(tariff)}><PencilLine size={14} /></button></Tooltip>
                <Popconfirm title="Delete this tariff?" description="Existing sessions keep their pricing snapshot." okButtonProps={{ danger: true }} onConfirm={() => deleteMutation.mutate(tariff.id)}><Tooltip title="Delete tariff"><button type="button" className="danger"><Trash2 size={14} /></button></Tooltip></Popconfirm>
              </div></td>}
            </tr>)}
          </tbody>
        </table>
        {!tariffsQuery.isLoading && tariffs.length === 0 && <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="No tariffs found" />}
        {tariffsQuery.isLoading && <div className="tariff-loading">Loading tariffs...</div>}
      </div>
    </SectionCard>}

    {activeTab === 'plans' && <div className="tariff-plan-grid">
      {plans.map((plan) => <article key={plan.id} className="tariff-plan-card">
        <header><div><small>{plan.code}</small><h3>{plan.name}</h3></div><StatusBadge status={plan.status} /></header>
        <strong>{formatMonthlyPrice(plan.monthly_fee_millimes)}<small>/month</small></strong>
        <dl><div><dt>Charging discount</dt><dd>{formatDiscount(plan.discount_basis_points)}</dd></div><div><dt>Audience</dt><dd>{plan.audience}</dd></div><div><dt>Members</dt><dd>{plan.member_count.toLocaleString()}</dd></div></dl>
        <div className="tariff-plan-actions"><button type="button" onClick={() => setEditorPlan(plan)}>{canManage ? 'Edit plan' : 'View plan'}</button>{canManage && <Popconfirm title="Delete this plan?" okButtonProps={{ danger: true }} onConfirm={() => deletePlanMutation.mutate(plan.id)}><button type="button" className="danger" aria-label={`Delete ${plan.name}`}><Trash2 size={13} /></button></Popconfirm>}</div>
      </article>)}
      {!plansQuery.isLoading && plans.length === 0 && <div className="tariff-empty-grid"><Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="No charging plans" /></div>}
      {plansQuery.isLoading && <div className="tariff-loading tariff-empty-grid">Loading charging plans...</div>}
      {plansQuery.isError && <Alert className="tariff-empty-grid" type="error" showIcon title="Charging plans could not be loaded." />}
    </div>}

    {activeTab === 'assignments' && <SectionCard title="Station assignment" subtitle="Assign, replace, or remove the effective tariff for a station or connector.">
      <div className="tariff-assignment-list">
        {assignments.map(({ tariff, assignment }) => <article key={assignment.id}>
          <div><span className="assignment-symbol"><Link2 size={15} /></span><span><strong>{assignment.connector ? `Connector: ${assignment.connector.external_id}` : `Station: ${assignment.station?.name ?? 'Unknown'}`}</strong><small>{assignment.connector ? `${assignment.station?.name ?? 'Unknown station'} / ${assignment.connector.type}` : 'All station connectors'}</small></span></div>
          <div><span><strong>{tariff.name}</strong><small>{tariff.code}</small></span><StatusBadge status={tariff.status} />{canManage && <Tooltip title="Change assigned tariff"><button type="button" className="assignment-edit" onClick={() => setAssignmentContext({ tariff, assignment })}><PencilLine size={14} /></button></Tooltip>}{canManage && <Popconfirm title="Remove this assignment?" onConfirm={() => removeAssignmentMutation.mutate(assignment.id)}><button type="button" className="assignment-remove"><Trash2 size={14} /></button></Popconfirm>}</div>
        </article>)}
        {assignments.length === 0 && <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="No station assignments" />}
      </div>
    </SectionCard>}

    {activeTab === 'simulator' && <div className="tariff-simulator-grid">
      <SectionCard title="Pricing simulator" subtitle="Resolve the backend tariff and calculate a complete charging estimate.">
        <div className="tariff-simulator-form">
          <label>Station<Select loading={stationsQuery.isLoading} value={effectiveStationId} placeholder="Select a station" options={stations.map((station) => ({ value: station.id, label: station.name }))} onChange={(value) => { setSimStationId(value); setSimConnectorId(undefined); simulationMutation.reset() }} /></label>
          <label>Client plan<Select value={simPlanId ?? 0} options={[{ value: 0, label: 'Pay without a plan' }, ...plans.filter((plan) => plan.status === 'active').map((plan) => ({ value: plan.id, label: `${plan.name} / ${formatDiscount(plan.discount_basis_points)}` }))]} onChange={(value) => { setSimPlanId(value || undefined); simulationMutation.reset() }} /></label>
          <label>Energy (kWh)<InputNumber min={0} precision={1} value={simEnergy} onChange={(value) => { setSimEnergy(Number(value ?? 0)); simulationMutation.reset() }} /></label>
          <label>Duration (minutes)<InputNumber min={0} value={simDuration} onChange={(value) => { setSimDuration(Number(value ?? 0)); simulationMutation.reset() }} /></label>
          <label>Idle time (minutes)<InputNumber min={0} value={simIdleMinutes} onChange={(value) => { setSimIdleMinutes(Number(value ?? 0)); simulationMutation.reset() }} /></label>
          <label>Connector type<Select allowClear value={effectiveConnectorId} placeholder="Use station-level pricing" options={(selectedStation?.connectors ?? []).map((connector) => ({ value: connector.id, label: `${connector.type} / ${connector.max_power_kw} kW` }))} onChange={(value) => { setSimConnectorId(value); simulationMutation.reset() }} /></label>
          <Button className="tariff-simulate-button" type="primary" icon={<Calculator size={14} />} loading={simulationMutation.isPending} onClick={runSimulation}>Calculate estimate</Button>
        </div>
      </SectionCard>
      <SectionCard title="Estimated breakdown" subtitle={simulationMutation.data ? `${simulationMutation.data.tariff.name} / ${sourceLabel(simulationMutation.data.tariff.source)}` : 'Run the simulator to resolve the effective tariff'}>
        <SimulationBreakdown loading={simulationMutation.isPending} simulation={simulationMutation.data} duration={simDuration} />
      </SectionCard>
    </div>}

    <TariffFormModal open={editorTariff !== undefined} tariff={editorTariff ?? null} submitting={saveMutation.isPending} onClose={() => setEditorTariff(undefined)} onSubmit={(payload) => saveMutation.mutate({ tariff: editorTariff ?? null, payload })} />
    <ChargingPlanModal open={editorPlan !== undefined} plan={editorPlan ?? null} canSave={canManage} submitting={savePlanMutation.isPending} onClose={() => setEditorPlan(undefined)} onSubmit={(payload) => savePlanMutation.mutate({ plan: editorPlan ?? null, payload })} />
    <TariffAssignmentDrawer open={assignmentContext !== undefined} initialTariff={assignmentContext?.tariff ?? null} assignment={assignmentContext?.assignment} tariffs={tariffs} stations={stations} submitting={assignmentMutation.isPending} onClose={() => setAssignmentContext(undefined)} onSubmit={(tariffId, payload) => assignmentMutation.mutate({ tariffId, payload })} />
  </div>
}

function SectionCard({ title, subtitle, children }: { title: string; subtitle: string; children: ReactNode }) {
  return <section className="prototype-section-card"><header><h2>{title}</h2><p>{subtitle}</p></header><div>{children}</div></section>
}

function StatusBadge({ status }: { status: string }) {
  return <span className={`prototype-status ${status}`}><i />{status.charAt(0).toUpperCase() + status.slice(1)}</span>
}

function SimulationBreakdown({ loading, simulation, duration }: { loading: boolean; simulation?: Awaited<ReturnType<typeof simulatePricing>>; duration: number }) {
  if (loading) return <div className="tariff-estimate-empty">Calculating with the backend pricing engine...</div>
  if (!simulation) return <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="No estimate calculated" />
  const breakdown = simulation.breakdown

  return <div className="tariff-estimate">
    <p><span>Gross energy cost</span><strong>{millimesToTnd(breakdown.energy_gross_millimes)} TND</strong></p>
    {breakdown.discount_millimes > 0 && <p className="discount"><span>Plan discount ({formatDiscount(simulation.plan?.discount_basis_points ?? 0)})</span><strong>-{millimesToTnd(breakdown.discount_millimes)} TND</strong></p>}
    <p><span>Time cost ({duration} min)</span><strong>{millimesToTnd(breakdown.time_cost_millimes)} TND</strong></p>
    <p><span>Start fee</span><strong>{millimesToTnd(breakdown.session_fee_millimes)} TND</strong></p>
    <p><span>Idle fee ({simulation.inputs.idle_minutes} min)</span><strong>{millimesToTnd(breakdown.idle_fee_millimes)} TND</strong></p>
    <p><span>Minimum charge</span><strong>{millimesToTnd(breakdown.minimum_charge_millimes)} TND</strong></p>
    <div><span>Estimated total</span><strong>{millimesToTnd(breakdown.total_millimes)} TND</strong></div>
    <small>Calculated by the backend with the effective tariff and active charging plan.</small>
  </div>
}

function TariffFormModal({ open, tariff, submitting, onClose, onSubmit }: { open: boolean; tariff: Tariff | null; submitting: boolean; onClose: () => void; onSubmit: (payload: TariffPayload) => void }) {
  const [form] = Form.useForm<TariffFormValues>()

  useEffect(() => {
    if (!open) return
    form.setFieldsValue(tariff ? {
      name: tariff.name, code: tariff.code, description: tariff.description, status: tariff.status, currency: 'TND',
      price_per_kwh: tariff.price_per_kwh_millimes / 1000, session_fee: tariff.session_fee_millimes / 1000,
      idle_fee_per_minute: tariff.idle_fee_per_minute_millimes / 1000, minimum_charge: tariff.minimum_charge_millimes / 1000,
      validity: tariff.valid_from && tariff.valid_until ? [dayjs(tariff.valid_from), dayjs(tariff.valid_until)] : undefined,
      is_default: tariff.is_default,
    } : { name: '', code: '', description: '', status: 'draft', currency: 'TND', price_per_kwh: 0.85, session_fee: 0.5, idle_fee_per_minute: 0.1, minimum_charge: 1, validity: undefined, is_default: false })
  }, [form, open, tariff])

  return <Modal className="tariff-editor-modal" open={open} centered width={680} footer={null} onCancel={onClose} title={<div><strong>{tariff ? 'Edit tariff' : 'Create tariff'}</strong><small>{tariff ? 'Update the pricing rule and its lifecycle.' : 'Create a tariff rule with TND currency.'}</small></div>}>
    <Form form={form} layout="vertical" requiredMark="optional" onFinish={(values) => onSubmit({
      name: values.name, code: values.code.toUpperCase(), description: values.description, status: values.status, currency: 'TND',
      price_per_kwh_millimes: tndToMillimes(values.price_per_kwh), session_fee_millimes: tndToMillimes(values.session_fee),
      idle_fee_per_minute_millimes: tndToMillimes(values.idle_fee_per_minute), minimum_charge_millimes: tndToMillimes(values.minimum_charge),
      valid_from: values.validity?.[0].toISOString() ?? null, valid_until: values.validity?.[1].toISOString() ?? null, is_default: values.is_default,
    })}>
      <div className="tariff-form-grid"><Form.Item label="Tariff name" name="name" rules={[{ required: true }]}><Input placeholder="Standard charging" /></Form.Item><Form.Item label="Code" name="code" rules={[{ required: true }]}><Input placeholder="STANDARD" /></Form.Item></div>
      <Form.Item label="Description" name="description"><Input.TextArea rows={2} placeholder="Internal pricing purpose and scope" /></Form.Item>
      <div className="tariff-form-grid"><Form.Item label="Currency" name="currency"><Input readOnly /></Form.Item><Form.Item label="Status" name="status" rules={[{ required: true }]}><Select options={['draft', 'active', 'archived'].map((value) => ({ value, label: value }))} /></Form.Item></div>
      <div className="tariff-form-grid"><MoneyField label="Price per kWh" name="price_per_kwh" suffix="TND" /><MoneyField label="Start fee" name="session_fee" suffix="TND" /><MoneyField label="Idle fee per minute" name="idle_fee_per_minute" suffix="TND" /><MoneyField label="Minimum session amount" name="minimum_charge" suffix="TND" /></div>
      <Form.Item label="Validity period" name="validity"><DatePicker.RangePicker showTime style={{ width: '100%' }} /></Form.Item>
      <Form.Item label="Organization default" name="is_default" valuePropName="checked"><Switch checkedChildren="Default" unCheckedChildren="Specific" /></Form.Item>
      <div className="tariff-modal-actions"><Button onClick={onClose}>Cancel</Button><Button className="tariff-save-button" type="primary" htmlType="submit" loading={submitting}>Save tariff</Button></div>
    </Form>
  </Modal>
}

function ChargingPlanModal({ open, plan, canSave, submitting, onClose, onSubmit }: { open: boolean; plan: ChargingPlan | null; canSave: boolean; submitting: boolean; onClose: () => void; onSubmit: (payload: ChargingPlanPayload) => void }) {
  const [form] = Form.useForm<PlanFormValues>()

  useEffect(() => {
    if (!open) return
    form.setFieldsValue(plan ? {
      name: plan.name,
      code: plan.code,
      description: plan.description,
      monthly_fee: plan.monthly_fee_millimes / 1000,
      discount_percent: plan.discount_basis_points / 100,
      audience: plan.audience,
      status: plan.status,
      member_count: plan.member_count,
    } : { name: '', code: '', description: '', monthly_fee: 0, discount_percent: 0, audience: '', status: 'draft', member_count: 0 })
  }, [form, open, plan])

  return <Modal className="tariff-editor-modal" open={open} centered width={620} footer={null} onCancel={onClose} title={<div><strong>{plan ? (canSave ? 'Edit charging plan' : 'Charging plan details') : 'Create charging plan'}</strong><small>Configure recurring fees, charging discounts, audience, and lifecycle.</small></div>}>
    <Form form={form} disabled={!canSave} layout="vertical" requiredMark="optional" onFinish={(values) => onSubmit({
      name: values.name,
      code: values.code.toUpperCase(),
      description: values.description,
      monthly_fee_millimes: tndToMillimes(values.monthly_fee),
      discount_basis_points: Math.round(values.discount_percent * 100),
      audience: values.audience,
      status: values.status,
      member_count: values.member_count,
    })}>
      <div className="tariff-form-grid"><Form.Item label="Plan name" name="name" rules={[{ required: true }]}><Input placeholder="Member Plan" /></Form.Item><Form.Item label="Code" name="code" rules={[{ required: true }]}><Input placeholder="MEMBER" /></Form.Item></div>
      <Form.Item label="Description" name="description"><Input.TextArea rows={2} placeholder="Plan purpose and eligibility" /></Form.Item>
      <div className="tariff-form-grid"><Form.Item label="Monthly fee" name="monthly_fee" rules={[{ required: true }]}><CompactInputNumber min={0} precision={3} addon="TND" /></Form.Item><Form.Item label="Charging discount" name="discount_percent" rules={[{ required: true }]}><CompactInputNumber min={0} max={100} precision={2} addon="%" /></Form.Item></div>
      <div className="tariff-form-grid"><Form.Item label="Audience" name="audience" rules={[{ required: true }]}><Input placeholder="Frequent drivers" /></Form.Item><Form.Item label="Members" name="member_count" rules={[{ required: true }]}><InputNumber min={0} precision={0} style={{ width: '100%' }} /></Form.Item></div>
      <Form.Item label="Status" name="status" rules={[{ required: true }]}><Select options={['draft', 'active', 'archived'].map((value) => ({ value, label: value }))} /></Form.Item>
      <div className="tariff-modal-actions"><Button disabled={false} onClick={onClose}>{canSave ? 'Cancel' : 'Close'}</Button>{canSave && <Button className="tariff-save-button" type="primary" htmlType="submit" loading={submitting}>Save plan</Button>}</div>
    </Form>
  </Modal>
}

function MoneyField({ label, name, suffix }: { label: string; name: keyof TariffFormValues; suffix: string }) {
  return <Form.Item label={label} name={name} rules={[{ required: true }]}><CompactInputNumber min={0} max={1000} precision={3} step={0.05} addon={suffix} /></Form.Item>
}

function TariffAssignmentDrawer({ open, initialTariff, assignment, tariffs, stations, submitting, onClose, onSubmit }: { open: boolean; initialTariff: Tariff | null; assignment?: TariffAssignment; tariffs: Tariff[]; stations: Station[]; submitting: boolean; onClose: () => void; onSubmit: (tariffId: number, payload: { station_id?: number; connector_id?: number }) => void }) {
  const [form] = Form.useForm<{ tariff_id: number; target: 'station' | 'connector'; station_id: number; connector_id?: number }>()
  const target = Form.useWatch('target', form) ?? 'station'
  const stationId = Form.useWatch('station_id', form)
  const station = stations.find((item) => item.id === stationId)

  useEffect(() => {
    if (!open) return
    form.setFieldsValue({
      tariff_id: initialTariff?.id,
      target: assignment?.type ?? 'station',
      station_id: assignment?.station?.id,
      connector_id: assignment?.connector?.id,
    })
  }, [assignment, form, initialTariff, open])

  return <Drawer open={open} title={assignment ? 'Change assigned tariff' : 'Assign a tariff'} size={480} onClose={onClose}>
    <div className="assignment-intro"><Link2 size={19} /><p>A connector assignment overrides its station tariff. A station assignment overrides the organization default.</p></div>
    <Form form={form} layout="vertical" requiredMark="optional" onFinish={(values) => onSubmit(values.tariff_id, values.target === 'connector' ? { connector_id: values.connector_id } : { station_id: values.station_id })}>
      <Form.Item label="Tariff" name="tariff_id" rules={[{ required: true }]}><Select showSearch optionFilterProp="label" options={tariffs.map((tariff) => ({ value: tariff.id, label: `${tariff.name} - ${tariff.code}` }))} /></Form.Item>
      <Form.Item label="Target level" name="target"><Segmented disabled={Boolean(assignment)} block options={[{ value: 'station', label: 'Charging station' }, { value: 'connector', label: 'Specific connector' }]} onChange={() => form.setFieldValue('connector_id', undefined)} /></Form.Item>
      <Form.Item label="Station" name="station_id" rules={[{ required: true }]}><Select disabled={Boolean(assignment)} showSearch optionFilterProp="label" options={stations.map((item) => ({ value: item.id, label: `${item.name} - ${item.city}` }))} /></Form.Item>
      {target === 'connector' && <Form.Item label="Connector" name="connector_id" rules={[{ required: true }]}><Select disabled={Boolean(assignment) || !station} options={(station?.connectors ?? []).map((connector) => ({ value: connector.id, label: `${connector.external_id} - ${connector.type} - ${connector.max_power_kw} kW` }))} /></Form.Item>}
      <Button type="primary" htmlType="submit" icon={<Link2 size={14} />} loading={submitting} block>Save assignment</Button>
    </Form>
  </Drawer>
}

function getTariffType(tariff: Tariff) {
  const connectorTypes = tariff.assignments.map((assignment) => assignment.connector?.type).filter(Boolean)
  if (connectorTypes.some((type) => type === 'CCS2' || type === 'CHAdeMO') || /FAST|DC/.test(tariff.code)) return 'DC fast charging'
  if (connectorTypes.includes('Type 2') || /AC/.test(tariff.code)) return 'AC charging'
  return 'Standard charging'
}

function countAssignedStations(tariff: Tariff) {
  return new Set(tariff.assignments.map((assignment) => assignment.station?.id).filter(Boolean)).size
}

function sourceLabel(source: string) {
  return ({ connector: 'Connector assignment', station: 'Station assignment', organization_default: 'Organization default', configuration_fallback: 'Configuration fallback' })[source] ?? source
}

function formatMonthlyPrice(value: number) { return `${(value / 1000).toLocaleString(undefined, { maximumFractionDigits: 3 })} TND` }
function formatDiscount(value: number) { return `${(value / 100).toLocaleString(undefined, { maximumFractionDigits: 2 })}%` }
function tndToMillimes(value: number) { return Math.round(value * 1000) }
function millimesToTnd(value: number) { return (value / 1000).toFixed(3) }
