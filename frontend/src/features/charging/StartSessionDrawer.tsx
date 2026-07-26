import { useEffect, useMemo, useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Alert, App, Button, Drawer, Empty, Form, InputNumber, Radio, Result, Select, Space, Spin, Steps, Tag } from 'antd'
import type { FormInstance } from 'antd'
import { motion } from 'framer-motion'
import { BadgePercent, BatteryCharging, CheckCircle2, CircleDollarSign, Clock3, CreditCard, Gauge, MapPin, PlugZap, ShieldCheck, Zap } from 'lucide-react'
import { isAxiosError } from 'axios'
import type { ChargingAttempt, ChargingAttemptPayload, ChargingSession, PaymentSimulationOutcome, SimulatedPaymentMethod } from '../../types/charging'
import type { Connector, Station } from '../../types/station'
import { getStation } from '../stations/stationApi'
import { getEffectivePricing } from '../tariffs/tariffApi'
import { getChargingAttempt, startChargingAttempt } from './chargingApi'
import { ConnectorTypeIcon } from './ConnectorTypeIcon'
import { createIdempotencyKey } from '../../lib/idempotency'

type ChargeableStation = Pick<Station, 'id' | 'name' | 'city' | 'location' | 'model_image' | 'available_connectors_count' | 'remote_start_available'> & {
  connectors: Connector[]
}

type FormValues = {
  station_id: number
  connector_id: number
  method: SimulatedPaymentMethod
  simulation_outcome: PaymentSimulationOutcome
  limit_type: 'none' | 'energy' | 'amount' | 'duration'
  limit_value?: number
}

interface StartSessionDrawerProps {
  open: boolean
  stations: ChargeableStation[]
  initialStationId?: number | null
  initialConnectorId?: number | null
  initialAttemptUuid?: string | null
  onClose: () => void
  onSessionStarted?: (session: ChargingSession) => void
}

const terminalAttemptStatuses = ['completed', 'failed']

export function StartSessionDrawer({
  open,
  stations,
  initialStationId,
  initialConnectorId,
  initialAttemptUuid,
  onClose,
  onSessionStarted,
}: StartSessionDrawerProps) {
  const [form] = Form.useForm<FormValues>()
  const [current, setCurrent] = useState(0)
  const [attemptUuid, setAttemptUuid] = useState<string | null>(initialAttemptUuid ?? null)
  const notifiedSessionId = useRef<number | null>(null)
  const initializedKey = useRef<string | null>(null)
  const idempotencyKey = useRef(createIdempotencyKey())
  const queryClient = useQueryClient()
  const { message } = App.useApp()
  const stationId = Form.useWatch('station_id', { form, preserve: true })
  const connectorId = Form.useWatch('connector_id', { form, preserve: true })
  const limitType = Form.useWatch('limit_type', { form, preserve: true })
  const effectiveStationId = stationId ?? initialStationId ?? null
  const effectiveConnectorId = connectorId ?? initialConnectorId ?? null
  const availableStations = useMemo(
    () => stations.filter((station) => station.remote_start_available),
    [stations],
  )
  const selectedStation = stations.find((station) => station.id === effectiveStationId)
  const availableConnectors = selectedStation?.connectors.filter((connector) => connector.status === 'available' && connector.ocpp_status === 'Available') ?? []
  const selectedConnector = selectedStation?.connectors.find((connector) => connector.id === effectiveConnectorId)
  const pricingQuery = useQuery({
    queryKey: ['effective-pricing', effectiveStationId, effectiveConnectorId],
    queryFn: () => getEffectivePricing(effectiveStationId!, effectiveConnectorId!),
    enabled: Boolean(effectiveStationId && effectiveConnectorId),
  })
  const attemptQuery = useQuery({
    queryKey: ['charging-attempt', attemptUuid],
    queryFn: () => getChargingAttempt(attemptUuid!),
    enabled: Boolean(attemptUuid),
    initialData: undefined,
    refetchInterval: (query) => terminalAttemptStatuses.includes(query.state.data?.status ?? '') || query.state.data?.charging_session ? false : 1200,
  })
  const connectorConnectionQuery = useQuery({
    queryKey: ['station', effectiveStationId, 'connector-connection'],
    queryFn: () => getStation(effectiveStationId!),
    enabled: open && current === 2 && effectiveStationId != null && effectiveConnectorId != null,
    refetchInterval: current === 2 ? 1000 : false,
  })
  const liveConnector = connectorConnectionQuery.data?.connectors.find((connector) => connector.id === effectiveConnectorId)
  const startMutation = useMutation({
    mutationFn: startChargingAttempt,
    onSuccess: (attempt) => {
      setAttemptUuid(attempt.uuid)
      setCurrent(4)
      queryClient.setQueryData(['charging-attempt', attempt.uuid], attempt)
      void queryClient.invalidateQueries({ queryKey: ['charging-attempts'] })
    },
    onError: (error) => void message.error(apiErrorMessage(error)),
  })

  useEffect(() => {
    if (!open) {
      initializedKey.current = null
      return
    }
    const key = `${initialAttemptUuid ?? ''}:${initialStationId ?? ''}:${initialConnectorId ?? ''}`
    if (initializedKey.current === key) return
    if (initialStationId != null && stations.length === 0) return
    initializedKey.current = key
    if (initialAttemptUuid) {
      setAttemptUuid(initialAttemptUuid)
      setCurrent(4)
      return
    }
    setAttemptUuid(null)
    idempotencyKey.current = createIdempotencyKey()
    const initialStation = initialStationId != null
      ? availableStations.find((station) => station.id === initialStationId)
      : undefined
    const initialConnector = initialStation?.connectors.find(
      (connector) => connector.id === initialConnectorId
        && connector.status === 'available'
        && connector.ocpp_status === 'Available',
    )
    setCurrent(initialConnector ? 2 : initialStation ? 1 : 0)
    notifiedSessionId.current = null
    form.setFieldsValue({
      station_id: initialStation?.id,
      connector_id: initialConnector?.id,
      method: 'simulated_card',
      simulation_outcome: 'success',
      limit_type: 'none',
      limit_value: undefined,
    })
  }, [availableStations, form, initialAttemptUuid, initialConnectorId, initialStationId, open, stations.length])

  useEffect(() => {
    if (current !== 2 || liveConnector?.ocpp_status !== 'Preparing') return
    void message.success('Cable connection detected by the station.')
    setCurrent(3)
  }, [current, liveConnector?.ocpp_status, message])

  useEffect(() => {
    const session = attemptQuery.data?.charging_session
    if (!session || notifiedSessionId.current === session.id) return
    notifiedSessionId.current = session.id
    void Promise.all([
      queryClient.invalidateQueries({ queryKey: ['charging-sessions'] }),
      queryClient.invalidateQueries({ queryKey: ['stations'] }),
      queryClient.invalidateQueries({ queryKey: ['payments'] }),
    ])
    onSessionStarted?.(session)
  }, [attemptQuery.data?.charging_session, onSessionStarted, queryClient])

  async function next() {
    const fields = current === 0 ? ['station_id'] : ['connector_id']
    await form.validateFields(fields)
    setCurrent((value) => Math.min(2, value + 1))
  }

  async function submit() {
    if (startMutation.isPending) return
    await form.validateFields()
    const values = form.getFieldsValue(true) as FormValues
    const payload: ChargingAttemptPayload = {
      station_id: values.station_id,
      connector_id: values.connector_id,
      method: values.method,
      simulation_outcome: values.simulation_outcome,
      idempotency_key: idempotencyKey.current,
    }
    if (values.limit_type === 'energy') payload.limit_energy_kwh = values.limit_value
    if (values.limit_type === 'amount') payload.limit_amount_tnd = values.limit_value
    if (values.limit_type === 'duration') payload.limit_duration_minutes = values.limit_value
    startMutation.mutate(payload)
  }

  return (
    <Drawer className="charging-workflow-drawer" open={open} title="Start charging" size={720} onClose={onClose} destroyOnHidden>
      <Steps
        current={current}
        responsive
        items={[
          { title: 'Station' },
          { title: 'Connector' },
          { title: 'Connect' },
          { title: 'Payment' },
          { title: 'Start' },
        ]}
      />
      <Form form={form} layout="vertical" requiredMark="optional" className="charging-workflow-form">
        <motion.div key={current} initial={{ opacity: 0, x: 12 }} animate={{ opacity: 1, x: 0 }} transition={{ duration: 0.2 }}>
          {current === 0 && <StationStep stations={availableStations} selectedStation={selectedStation} form={form} />}
          {current === 1 && <ConnectorStep connectors={availableConnectors} selectedConnector={selectedConnector} form={form} />}
          {current === 2 && <ConnectionStep connector={selectedConnector} liveConnector={liveConnector} loading={connectorConnectionQuery.isLoading} error={connectorConnectionQuery.isError} />}
          {current === 3 && <PaymentStep pricing={pricingQuery.data} limitType={limitType} />}
          {current === 4 && <AttemptStep attempt={attemptQuery.data} loading={attemptQuery.isLoading || startMutation.isPending} />}
        </motion.div>
      </Form>
      {current < 4 && <footer className="charging-workflow-footer">
        <Button disabled={current === 0} onClick={() => setCurrent((value) => Math.max(0, value - 1))}>Back</Button>
        {current < 3
          ? current === 2
            ? <Button type="primary" loading disabled>Waiting for cable</Button>
            : <Button type="primary" onClick={() => void next()}>Continue</Button>
          : <Button type="primary" icon={<Zap size={16} />} loading={startMutation.isPending} onClick={() => void submit()}>Authorize 30 TND & start</Button>}
      </footer>}
    </Drawer>
  )
}

function StationStep({ stations, selectedStation, form }: { stations: ChargeableStation[]; selectedStation?: ChargeableStation; form: FormInstance<FormValues> }) {
  return <section className="charging-workflow-step"><header><MapPin size={20} /><span><h2>Choose your charging station</h2><p>Only connected OCPP stations with an available connector are shown.</p></span></header>
    {stations.length === 0 ? <Empty description="No connected OCPP station currently has a connector ready for remote charging." /> : <>
      <Form.Item label="Charging station" name="station_id" rules={[{ required: true, message: 'Choose a station' }]}>
        <Select showSearch optionFilterProp="label" placeholder="Select a station" onChange={() => form.setFieldValue('connector_id', undefined)} options={stations.map((station) => ({ value: station.id, label: `${station.name} - ${station.city}` }))} />
      </Form.Item>
      {selectedStation && <div className="start-station-context"><img src={selectedStation.model_image ?? '/assets/charger-terra-hp-150.png'} alt="" /><div><strong>{selectedStation.name}</strong><span><MapPin size={13} />{selectedStation.location}</span></div><b>{selectedStation.available_connectors_count} free</b></div>}
    </>}
  </section>
}

function ConnectorStep({ connectors, selectedConnector, form }: { connectors: Connector[]; selectedConnector?: Connector; form: FormInstance<FormValues> }) {
  return <section className="charging-workflow-step"><header><PlugZap size={20} /><span><h2>Select the plug that matches your vehicle</h2><p>The connector type and maximum power are confirmed before charging.</p></span></header>
    <Form.Item name="connector_id" rules={[{ required: true, message: 'Choose a connector' }]}>
      <Radio.Group className="connector-choice-grid">
        {connectors.map((connector) => <Radio.Button value={connector.id} key={connector.id} onClick={() => form.setFieldValue('connector_id', connector.id)}>
          <ConnectorTypeIcon type={connector.type} />
          <span><strong>{connector.type}</strong><small>{connector.external_id} · {connector.max_power_kw} kW {connector.current_type}</small></span>
          <Tag color="success">Available</Tag>
        </Radio.Button>)}
      </Radio.Group>
    </Form.Item>
    {connectors.length === 0 && <Empty description="No connector is currently available" />}
    {selectedConnector && <Alert type="info" showIcon title={`${selectedConnector.type} selected`} description={`The station can deliver up to ${selectedConnector.max_power_kw} kW on connector ${selectedConnector.external_id}.`} />}
  </section>
}

function ConnectionStep({ connector, liveConnector, loading, error }: { connector?: Connector; liveConnector?: Connector; loading: boolean; error: boolean }) {
  const rawStatus = liveConnector?.ocpp_status ?? connector?.ocpp_status ?? 'Waiting'

  return <section className="charging-workflow-step"><header><BatteryCharging size={20} /><span><h2>Connect your vehicle</h2><p>Complete the physical connection before authorizing the remote start.</p></span></header>
    <div className="connection-guide">
      <div className="connection-guide-visual">{connector && <ConnectorTypeIcon type={connector.type} subtitled />}<span>Instructional video slot</span><small>A WebM or MP4 guide can be added here later.</small></div>
      <ol><li><b>1</b><span>Park safely and switch off the vehicle.</span></li><li><b>2</b><span>Take the <strong>{connector?.type ?? 'selected'}</strong> cable from connector {connector?.external_id}.</span></li><li><b>3</b><span>Insert it fully into the vehicle until it locks.</span></li></ol>
    </div>
    <div className="connector-detection-status">
      <Spin spinning={loading || rawStatus === 'Available' || rawStatus === 'Waiting'} size="small" />
      <span><strong>Waiting for the station to detect the cable</strong><small>OCPP connector status: {rawStatus}</small></span>
    </div>
    {error && <Alert type="error" showIcon title="The connector status could not be refreshed" description="The platform will retry automatically while this step remains open." />}
  </section>
}

function PaymentStep({ pricing, limitType }: { pricing?: Awaited<ReturnType<typeof getEffectivePricing>>; limitType?: FormValues['limit_type'] }) {
  const limitLabel = limitType === 'energy' ? 'kWh' : limitType === 'amount' ? 'TND' : 'minutes'
  return <section className="charging-workflow-step"><header><CreditCard size={20} /><span><h2>Authorize payment and set a limit</h2><p>30.000 TND is temporarily authorized. Only the final measured amount is captured.</p></span></header>
    <div className="preauthorization-card"><ShieldCheck size={23} /><span><small>Temporary authorization</small><strong>30.000 TND</strong><p>Automatically released if charging does not start.</p></span></div>
    <Form.Item label="Payment method" name="method" rules={[{ required: true }]}>
      <Radio.Group className="payment-method-grid" options={[
        { value: 'simulated_card', label: <span><CreditCard size={17} />Bank card</span> },
        { value: 'simulated_edinar', label: <span><CircleDollarSign size={17} />e-DINAR</span> },
        { value: 'simulated_d17', label: <span><Zap size={17} />D17</span> },
      ]} optionType="button" />
    </Form.Item>
    <Form.Item label="Optional charging limit" name="limit_type">
      <Radio.Group options={[{ value: 'none', label: 'No custom limit' }, { value: 'energy', label: 'Energy' }, { value: 'amount', label: 'Amount' }, { value: 'duration', label: 'Duration' }]} />
    </Form.Item>
    {limitType && limitType !== 'none' && <Form.Item label={`Maximum ${limitType}`} name="limit_value" rules={[{ required: true, message: 'Enter a limit' }]}>
      <Space.Compact block><InputNumber className="charging-limit-input" min={limitType === 'energy' ? 0.1 : 1} max={limitType === 'amount' ? 30 : limitType === 'duration' ? 1440 : 200} step={limitType === 'energy' ? 0.5 : 1} /><Button disabled>{limitLabel}</Button></Space.Compact>
    </Form.Item>}
    {import.meta.env.DEV && <Form.Item label="External sandbox result" name="simulation_outcome"><Select options={[
      { value: 'success', label: 'Authorize successfully' },
      { value: 'declined', label: 'Provider decline' },
      { value: 'timeout', label: 'Provider timeout' },
      { value: 'provider_error', label: 'Provider unavailable' },
    ]} /></Form.Item>}
    {pricing && <div className="effective-pricing-card"><div><small>Applied tariff</small><strong>{pricing.name}</strong><span>{pricingSourceLabel(pricing.source)}</span></div><div><small>Energy</small><strong>{(pricing.effective_price_per_kwh_millimes / 1000).toFixed(3)} TND/kWh</strong></div><div><small>Start fee</small><strong>{(pricing.session_fee_millimes / 1000).toFixed(3)} TND</strong></div><div><small>Minimum</small><strong>{(pricing.minimum_charge_millimes / 1000).toFixed(3)} TND</strong></div></div>}
    {pricing?.plan && <div className="start-plan-benefit"><BadgePercent size={15} /><span><strong>{pricing.plan.name}</strong><small>{(pricing.plan.discount_basis_points / 100).toFixed(0)}% subscription discount is applied automatically.</small></span></div>}
  </section>
}

function AttemptStep({ attempt, loading }: { attempt?: ChargingAttempt; loading: boolean }) {
  if (loading && !attempt) return <div className="attempt-loading"><Spin size="large" /><h2>Authorizing payment</h2><p>Please keep this window open.</p></div>
  if (!attempt) return <Result status="warning" title="Charging status is unavailable" />
  if (attempt.status === 'failed') return <Result status="error" title="Charging did not start" subTitle={attempt.failure_message ?? 'The station or external payment sandbox rejected the request.'} />
  if (attempt.charging_session) return <Result status="success" icon={<CheckCircle2 />} title="Charging has started" subTitle={`${attempt.station.name} · Connector ${attempt.connector.external_id}`} extra={<div className="attempt-session-summary"><span><Gauge size={16} />Live OCPP session</span><span><ShieldCheck size={16} />Payment authorized</span><span><Clock3 size={16} />Automatic final capture</span></div>} />

  const status = attempt.status === 'payment_pending' ? 'Authorizing payment'
    : attempt.status === 'authorized' ? 'Payment authorized'
      : attempt.status === 'command_queued' ? 'Command queued'
        : attempt.status === 'command_sent' ? 'Contacting the station'
          : 'Waiting for station confirmation'
  const percent = attempt.status === 'payment_pending' ? 20 : attempt.status === 'authorized' ? 40 : attempt.status === 'command_queued' ? 60 : attempt.status === 'command_sent' ? 80 : 90
  return <div className="attempt-progress"><div className="active-session-pulse"><BatteryCharging size={27} /></div><h2>{status}</h2><p>The station must confirm `StartTransaction` before the charging session exists.</p><div className="attempt-progress-track"><span style={{ width: `${percent}%` }} /></div><small>Payment: {attempt.payment_status.replaceAll('_', ' ')} · Command: {attempt.command?.status ?? 'preparing'}</small></div>
}

function pricingSourceLabel(source: string) {
  return ({ connector: 'Connector-specific', station: 'Station-specific', organization_default: 'Organization default', configuration_fallback: 'Configuration fallback' } as Record<string, string>)[source] ?? source
}

function apiErrorMessage(error: unknown) {
  if (!isAxiosError(error)) return 'Charging could not be started. Check the station and payment details.'
  const data = error.response?.data as { message?: string; errors?: Record<string, string[]> } | undefined
  const validationMessage = data?.errors ? Object.values(data.errors).flat()[0] : undefined

  return validationMessage ?? data?.message ?? 'Charging could not be started. Check the station and payment details.'
}
