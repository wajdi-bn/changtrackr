import { useDeferredValue, useEffect, useMemo, useRef, useState } from 'react'
import type { ReactNode } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Alert, App, Button, Drawer, Empty, Form, Input, InputNumber, Radio, Result, Select, Slider, Spin, Steps, Tag } from 'antd'
import type { FormInstance } from 'antd'
import { motion } from 'framer-motion'
import { BadgePercent, BatteryCharging, Check, CheckCircle2, CircleDollarSign, Clock3, CreditCard, Gauge, MapPin, PlugZap, RadioTower, ShieldCheck, Zap } from 'lucide-react'
import { getApiErrorMessage } from '../../api/apiErrors'
import type { ChargingAttempt, ChargingAttemptPayload, ChargingSession, PaymentSimulationOutcome, SimulatedPaymentMethod } from '../../types/charging'
import type { Connector, OcppSimulatorActionStatus, Station } from '../../types/station'
import type { ChargingTargetType, PricingSimulation } from '../../types/tariff'
import { getStation } from '../stations/stationApi'
import { getEffectivePricing, simulatePricing } from '../tariffs/tariffApi'
import { executeClientChargingTerminalAction, getChargingAttempt, getClientChargingTerminalAction, startChargingAttempt } from './chargingApi'
import { buildChargingLimitPayload, linkedTargetValues } from './chargingEstimate'
import { canInsertVirtualCable, resolveClientTerminalState } from './clientChargingTerminal'
import { ConnectorTypeIcon } from './ConnectorTypeIcon'
import { PaymentMethodBrand } from './PaymentMethodBrand'
import { createIdempotencyKey } from '../../lib/idempotency'

type ChargeableStation = Pick<Station, 'id' | 'name' | 'city' | 'location' | 'model_image' | 'available_connectors_count' | 'remote_start_available'> & {
  connectors: Connector[]
}

type FormValues = {
  station_id: number
  connector_id: number
  method: SimulatedPaymentMethod
  simulation_outcome: PaymentSimulationOutcome
  cardholder_name?: string
  card_number?: string
  card_expiry?: string
  card_cvc?: string
  edinar_card_number?: string
  edinar_expiry?: string
  edinar_code?: string
  d17_phone?: string
  d17_code?: string
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
  const [targetType, setTargetType] = useState<ChargingTargetType>('amount')
  const [targetValue, setTargetValue] = useState(15)
  const notifiedSessionId = useRef<number | null>(null)
  const initializedKey = useRef<string | null>(null)
  const idempotencyKey = useRef(createIdempotencyKey())
  const terminalPlugIdempotencyKey = useRef(createIdempotencyKey())
  const queryClient = useQueryClient()
  const { message } = App.useApp()
  const stationId = Form.useWatch('station_id', { form, preserve: true })
  const connectorId = Form.useWatch('connector_id', { form, preserve: true })
  const paymentMethod = Form.useWatch('method', { form, preserve: true })
  const deferredTargetValue = useDeferredValue(targetValue)
  const estimatePending = targetValue !== deferredTargetValue
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
  const estimateQuery = useQuery({
    queryKey: ['charging-estimate', effectiveStationId, effectiveConnectorId, targetType, deferredTargetValue],
    queryFn: () => simulatePricing({
      station_id: effectiveStationId!,
      connector_id: effectiveConnectorId!,
      target_type: targetType,
      target_value: deferredTargetValue,
    }),
    enabled: open && current >= 3 && current < 6 && effectiveStationId != null && effectiveConnectorId != null,
    placeholderData: (previous) => previous,
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
      setCurrent(6)
      queryClient.setQueryData(['charging-attempt', attempt.uuid], attempt)
      void queryClient.invalidateQueries({ queryKey: ['charging-attempts'] })
    },
    onError: (error) => void message.error(getApiErrorMessage(error, 'Charging could not be started. Check the station and payment details.')),
  })
  const terminalMutation = useMutation({
    mutationFn: executeClientChargingTerminalAction,
    onSuccess: () => {
      void message.success('Virtual cable signal sent to the station.')
      void connectorConnectionQuery.refetch()
    },
    onError: (error) => {
      terminalPlugIdempotencyKey.current = createIdempotencyKey()
      void message.error(getApiErrorMessage(error, 'The virtual cable could not be inserted. Check the station status and try again.'))
    },
  })
  const terminalActionQuery = useQuery({
    queryKey: ['client-charging-terminal-action', effectiveStationId, effectiveConnectorId, terminalMutation.data?.uuid],
    queryFn: () => getClientChargingTerminalAction(effectiveStationId!, effectiveConnectorId!, terminalMutation.data!.uuid),
    enabled: open && current === 2 && effectiveStationId != null && effectiveConnectorId != null && terminalMutation.data != null,
    refetchInterval: (query) => ['queued', 'running'].includes(query.state.data?.status ?? '') ? 700 : false,
  })
  const terminalAction = terminalActionQuery.data ?? terminalMutation.data

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
      setCurrent(6)
      return
    }
    setAttemptUuid(null)
    idempotencyKey.current = createIdempotencyKey()
    terminalPlugIdempotencyKey.current = createIdempotencyKey()
    setTargetType('amount')
    setTargetValue(15)
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
    if (current === 3) {
      if (estimatePending || estimateQuery.isFetching) return
      if (!estimateQuery.data || estimateQuery.isError) {
        void message.error('The charging estimate is not available yet.')
        return
      }
      if (!estimateQuery.data.estimate?.within_preauthorization) {
        void message.error('Choose a target within the payment authorization limit.')
        return
      }
      setCurrent(4)
      return
    }
    if (current === 4) {
      await form.validateFields(['method', 'simulation_outcome', ...paymentFields(paymentMethod)])
      setCurrent(5)
      return
    }
    const fields = current === 0 ? ['station_id'] : ['connector_id']
    await form.validateFields(fields)
    setCurrent((value) => Math.min(2, value + 1))
  }

  async function submit() {
    if (startMutation.isPending) return
    await form.validateFields(['station_id', 'connector_id', 'method', 'simulation_outcome', ...paymentFields(paymentMethod)])
    const values = form.getFieldsValue(true) as FormValues
    const payload: ChargingAttemptPayload = {
      station_id: values.station_id,
      connector_id: values.connector_id,
      method: values.method,
      simulation_outcome: values.simulation_outcome,
      idempotency_key: idempotencyKey.current,
      ...buildChargingLimitPayload(targetType, targetValue),
    }
    startMutation.mutate(payload)
  }

  return (
    <Drawer className="charging-workflow-drawer" open={open} title="Start charging" size={720} onClose={onClose} destroyOnHidden>
      <Steps
        current={current}
        labelPlacement="vertical"
        responsive
        items={[
          { title: 'Station' },
          { title: 'Plug' },
          { title: 'Connect' },
          { title: 'Target' },
          { title: 'Pay' },
          { title: 'Review' },
          { title: 'Start' },
        ]}
      />
      <Form form={form} layout="vertical" requiredMark="optional" className="charging-workflow-form">
        <motion.div key={current} initial={{ opacity: 0, x: 12 }} animate={{ opacity: 1, x: 0 }} transition={{ duration: 0.2 }}>
          {current === 0 && <StationStep stations={availableStations} selectedStation={selectedStation} form={form} />}
          {current === 1 && <ConnectorStep connectors={availableConnectors} selectedConnector={selectedConnector} form={form} />}
          {current === 2 && <ConnectionStep
            station={selectedStation}
            connector={selectedConnector}
            liveConnector={liveConnector}
            loading={connectorConnectionQuery.isLoading}
            error={connectorConnectionQuery.isError}
            terminalActionStatus={terminalAction?.status}
            terminalActionCompletedAt={terminalAction?.completed_at}
            terminalFailureMessage={terminalAction?.failure_message}
            terminalPending={terminalMutation.isPending}
            onInsertCable={(retry) => {
              if (!effectiveStationId || !effectiveConnectorId) return
              if (retry) terminalPlugIdempotencyKey.current = createIdempotencyKey()
              terminalMutation.mutate({
                stationId: effectiveStationId,
                connectorId: effectiveConnectorId,
                action: 'plug',
                idempotencyKey: terminalPlugIdempotencyKey.current,
              })
            }}
          />}
          {current === 3 && <ChargingTargetStep targetType={targetType} targetValue={targetValue} quote={estimateQuery.data} loading={estimatePending || estimateQuery.isFetching} error={estimateQuery.isError} onChange={(type, value) => { setTargetType(type); setTargetValue(value) }} />}
          {current === 4 && <PaymentStep quote={estimateQuery.data} method={paymentMethod} />}
          {current === 5 && <PaymentReviewStep pricing={pricingQuery.data} quote={estimateQuery.data} method={paymentMethod} />}
          {current === 6 && <AttemptStep attempt={attemptQuery.data} loading={attemptQuery.isLoading || startMutation.isPending} />}
        </motion.div>
      </Form>
      {current < 6 && <footer className="charging-workflow-footer">
        <Button disabled={current === 0} onClick={() => setCurrent((value) => Math.max(0, value - 1))}>Back</Button>
        {current < 5
          ? current === 2
            ? <Button type="primary" loading disabled>Waiting for cable</Button>
            : <Button type="primary" loading={current === 3 && (estimatePending || estimateQuery.isFetching)} onClick={() => void next()}>{current === 4 ? 'Review payment' : 'Continue'}</Button>
          : <Button type="primary" icon={<Zap size={16} />} loading={startMutation.isPending} onClick={() => void submit()}>Authorize {formatTnd(estimateQuery.data?.estimate?.preauthorization_amount_millimes ?? 30000)} & start</Button>}
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
      {selectedStation && <div className="start-station-context"><img src={selectedStation.model_image ?? '/assets/stations/models/terra-hp-150.webp'} alt="" width={960} height={540} loading="lazy" decoding="async" /><div><strong>{selectedStation.name}</strong><span><MapPin size={13} />{selectedStation.location}</span></div><b>{selectedStation.available_connectors_count} free</b></div>}
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

function ConnectionStep({
  station,
  connector,
  liveConnector,
  loading,
  error,
  terminalActionStatus,
  terminalActionCompletedAt,
  terminalFailureMessage,
  terminalPending,
  onInsertCable,
}: {
  station?: ChargeableStation
  connector?: Connector
  liveConnector?: Connector
  loading: boolean
  error: boolean
  terminalActionStatus?: OcppSimulatorActionStatus
  terminalActionCompletedAt?: string | null
  terminalFailureMessage?: string | null
  terminalPending: boolean
  onInsertCable: (retry: boolean) => void
}) {
  const rawStatus = liveConnector?.ocpp_status ?? connector?.ocpp_status ?? 'Waiting'
  const terminalState = terminalPending ? 'requesting' : resolveClientTerminalState(rawStatus, terminalActionStatus, terminalActionCompletedAt)

  return <section className="charging-workflow-step"><header><BatteryCharging size={20} /><span><h2>Connect your vehicle</h2><p>Use the virtual station terminal to reproduce inserting the physical cable.</p></span></header>
    <div className="connection-guide">
      <div className="connection-guide-visual">{connector && <ConnectorTypeIcon type={connector.type} subtitled />}<span>Instructional video slot</span><small>A WebM or MP4 guide can be added here later.</small></div>
      <ol><li><b>1</b><span>Park safely and switch off the vehicle.</span></li><li><b>2</b><span>Take the <strong>{connector?.type ?? 'selected'}</strong> cable from connector {connector?.external_id}.</span></li><li><b>3</b><span>Insert it fully into the vehicle until it locks.</span></li></ol>
    </div>
    <div className={`client-charging-terminal client-charging-terminal--${terminalState}`}>
      <div className="client-charging-terminal__icon">{terminalState === 'connected' ? <Check size={23} /> : <RadioTower size={23} />}</div>
      <div className="client-charging-terminal__content">
        <small>VIRTUAL STATION TERMINAL</small>
        <strong>{station?.name ?? 'Selected station'}</strong>
        <span>Connector {connector?.external_id ?? '-'} - {connector?.type ?? 'plug'} - OCPP {rawStatus}</span>
      </div>
      {canInsertVirtualCable(terminalState)
        ? <Button type="primary" icon={<PlugZap size={17} />} loading={terminalPending} onClick={() => onInsertCable(terminalState === 'failed')}>{terminalState === 'failed' ? 'Try again' : 'Insert virtual cable'}</Button>
        : terminalState === 'connected'
          ? <span className="client-charging-terminal__confirmed"><Check size={16} /> Cable detected</span>
          : <span className="client-charging-terminal__waiting"><Spin size="small" /> {terminalState === 'unavailable' ? 'Connector unavailable' : 'Waiting for OCPP'}</span>}
      <p>{terminalState === 'ready'
        ? 'In this simulated environment, this button represents inserting the physical cable into the vehicle.'
        : terminalState === 'failed'
          ? terminalFailureMessage ?? 'The station did not process the cable action. You can try again safely.'
        : terminalState === 'connected'
          ? 'The station reported StatusNotification(Preparing). The next step opens automatically.'
          : 'The simulator is processing the physical action. This workflow advances only after the station confirms it through OCPP.'}</p>
    </div>
    <div className="connector-detection-status">
      <Spin spinning={loading || terminalState === 'requesting' || terminalState === 'waiting_ocpp'} size="small" />
      <span><strong>{terminalState === 'connected' ? 'Cable connection detected by the station' : terminalState === 'failed' ? 'Cable action was not completed' : 'Waiting for the station to detect the cable'}</strong><small>Verified OCPP connector status: {rawStatus}</small></span>
    </div>
    {error && <Alert type="error" showIcon title="The connector status could not be refreshed" description="The platform will retry automatically while this step remains open." />}
  </section>
}

function ChargingTargetStep({
  targetType,
  targetValue,
  quote,
  loading,
  error,
  onChange,
}: {
  targetType: ChargingTargetType
  targetValue: number
  quote?: PricingSimulation
  loading: boolean
  error: boolean
  onChange: (type: ChargingTargetType, value: number) => void
}) {
  const estimate = quote?.estimate
  const values = linkedTargetValues(estimate, targetType, targetValue)
  const maximumEnergy = Math.max(0.1, estimate?.maximums.energy_kwh ?? 40)
  const maximumDuration = Math.max(1, estimate?.maximums.duration_minutes ?? 60)
  const maximumAmount = Math.max(1, (estimate?.maximums.amount_millimes ?? 30000) / 1000)

  return <section className="charging-workflow-step charging-target-step">
    <header><Gauge size={20} /><span><h2>Choose what should stop charging</h2><p>Adjust energy, time or budget. The other two values update from the station tariff and connector power.</p></span></header>
    <div className="charging-target-context">
      <span><Zap size={16} /><strong>{estimate?.connector_power_kw ?? 0} kW</strong> connector maximum</span>
      <span className={loading ? 'is-loading' : ''}><Spin size="small" spinning={loading} />{loading ? 'Updating estimate' : 'Estimate up to date'}</span>
    </div>
    <div className="charging-target-grid">
      <ChargingTargetControl icon={<BatteryCharging />} type="energy" label="Energy" help="Energy delivered before the automatic stop" value={values.energy} min={0.1} max={maximumEnergy} step={0.1} unit="kWh" active={targetType === 'energy'} onChange={onChange} />
      <ChargingTargetControl icon={<Clock3 />} type="duration" label="Time" help="Estimated at the connector's maximum power" value={values.duration} min={1} max={maximumDuration} step={1} unit="min" active={targetType === 'duration'} onChange={onChange} />
      <ChargingTargetControl icon={<CircleDollarSign />} type="amount" label="Budget" help="Includes tariff, start fee and membership discount" value={values.amount} min={1} max={maximumAmount} step={0.1} unit="TND" active={targetType === 'amount'} onChange={onChange} />
    </div>
    {estimate && <div className="charging-estimate-summary">
      <div><small>Estimated energy</small><strong>{estimate.energy_kwh.toFixed(2)} kWh</strong></div>
      <div><small>Estimated time</small><strong>{estimate.duration_minutes} min</strong></div>
      <div><small>Estimated total</small><strong>{formatTnd(estimate.amount_millimes)}</strong></div>
      <Tag color={estimate.within_preauthorization ? 'success' : 'error'}>{estimate.within_preauthorization ? 'Within authorization' : 'Above authorization'}</Tag>
    </div>}
    <Alert
      type="info"
      showIcon
      title="Planning estimate, not a guaranteed charging speed"
      description="The estimate uses the connector's maximum power. Vehicle limits, battery temperature and charge level can reduce actual power. Final billing always uses OCPP meter values."
    />
    {error && <Alert type="error" showIcon title="The estimate could not be refreshed" description="Check the connection and try the selected value again." />}
  </section>
}

function ChargingTargetControl({
  icon,
  type,
  label,
  help,
  value,
  min,
  max,
  step,
  unit,
  active,
  onChange,
}: {
  icon: ReactNode
  type: ChargingTargetType
  label: string
  help: string
  value: number
  min: number
  max: number
  step: number
  unit: string
  active: boolean
  onChange: (type: ChargingTargetType, value: number) => void
}) {
  const boundedValue = Math.min(max, Math.max(min, value))
  return <article className={`charging-target-control${active ? ' is-active' : ''}`}>
    <header><span>{icon}</span><div><strong>{label}</strong><small>{help}</small></div>{active && <b>Stop target</b>}</header>
    <div className="charging-target-control__input">
      <Slider min={min} max={max} step={step} value={boundedValue} onChange={(next) => onChange(type, next)} tooltip={{ formatter: (next) => `${next ?? 0} ${unit}` }} />
      <InputNumber min={min} max={max} step={step} value={boundedValue} onChange={(next) => next != null && onChange(type, next)} controls />
      <span>{unit}</span>
    </div>
  </article>
}

function PaymentStep({
  quote,
  method,
}: {
  quote?: PricingSimulation
  method?: SimulatedPaymentMethod
}) {
  const selectedMethod = method ?? 'simulated_card'
  const estimate = quote?.estimate

  return <section className="charging-workflow-step charging-payment-step">
    <header><CreditCard size={20} /><span><h2>Enter payment details</h2><p>Select a sandbox payment method and complete only the fields required by that method.</p></span></header>
    {estimate && <div className="payment-charge-summary">
      <div><small>Charging target</small><strong>{targetSummary(estimate)}</strong></div>
      <div><small>Estimated session</small><strong>{estimate.energy_kwh.toFixed(2)} kWh · {estimate.duration_minutes} min</strong></div>
      <div><small>Estimated total</small><strong>{formatTnd(estimate.amount_millimes)}</strong></div>
    </div>}
    <Form.Item label="Payment method" name="method" rules={[{ required: true }]}>
      <Radio.Group className="payment-method-grid">
        <Radio.Button value="simulated_card"><PaymentMethodBrand method="simulated_card" /><span><strong>Bank card</strong><small>Visa or Mastercard sandbox</small></span></Radio.Button>
        <Radio.Button value="simulated_edinar"><PaymentMethodBrand method="simulated_edinar" /><span><strong>e-DINAR</strong><small>Postal card sandbox</small></span></Radio.Button>
        <Radio.Button value="simulated_d17"><PaymentMethodBrand method="simulated_d17" /><span><strong>D17</strong><small>Mobile wallet sandbox</small></span></Radio.Button>
      </Radio.Group>
    </Form.Item>
    <div className="payment-details-panel">
      <header><span><ShieldCheck size={18} /></span><div><strong>{paymentMethodTitle(selectedMethod)}</strong><small>Sandbox fields are validated locally and never included in the API request.</small></div></header>
      {selectedMethod === 'simulated_card' && <div className="payment-details-grid">
        <Form.Item className="is-wide" label="Sandbox cardholder" name="cardholder_name" preserve={false} rules={[{ required: true, message: 'Enter the sandbox cardholder name' }]}><Input autoComplete="off" placeholder="Demo customer" /></Form.Item>
        <Form.Item className="is-wide" label="Sandbox card number" name="card_number" preserve={false} rules={[{ required: true }, { pattern: /^\d{16}$/, message: 'Enter 16 sandbox digits' }]}><Input inputMode="numeric" autoComplete="off" maxLength={16} placeholder="4242424242424242" /></Form.Item>
        <Form.Item label="Expiry" name="card_expiry" preserve={false} rules={[{ required: true }, { pattern: /^(0[1-9]|1[0-2])\/\d{2}$/, message: 'Use MM/YY' }]}><Input inputMode="numeric" autoComplete="off" maxLength={5} placeholder="12/30" /></Form.Item>
        <Form.Item label="Demo CVC" name="card_cvc" preserve={false} rules={[{ required: true }, { pattern: /^\d{3}$/, message: 'Enter 3 sandbox digits' }]}><Input.Password inputMode="numeric" autoComplete="off" maxLength={3} placeholder="123" /></Form.Item>
      </div>}
      {selectedMethod === 'simulated_edinar' && <div className="payment-details-grid">
        <Form.Item className="is-wide" label="e-DINAR sandbox card number" name="edinar_card_number" preserve={false} rules={[{ required: true }, { pattern: /^\d{16}$/, message: 'Enter 16 sandbox digits' }]}><Input inputMode="numeric" autoComplete="off" maxLength={16} placeholder="5359400000000000" /></Form.Item>
        <Form.Item label="Expiry" name="edinar_expiry" preserve={false} rules={[{ required: true }, { pattern: /^(0[1-9]|1[0-2])\/\d{2}$/, message: 'Use MM/YY' }]}><Input inputMode="numeric" autoComplete="off" maxLength={5} placeholder="12/30" /></Form.Item>
        <Form.Item label="Demo verification code" name="edinar_code" preserve={false} rules={[{ required: true }, { pattern: /^\d{4}$/, message: 'Enter 4 sandbox digits' }]}><Input.Password inputMode="numeric" autoComplete="off" maxLength={4} placeholder="0000" /></Form.Item>
      </div>}
      {selectedMethod === 'simulated_d17' && <div className="payment-details-grid">
        <Form.Item className="is-wide" label="D17 sandbox mobile number" name="d17_phone" preserve={false} rules={[{ required: true }, { pattern: /^\+216\d{8}$/, message: 'Use +216 followed by 8 digits' }]}><Input inputMode="tel" autoComplete="off" placeholder="+21620123456" /></Form.Item>
        <Form.Item className="is-wide" label="Demo confirmation code" name="d17_code" preserve={false} rules={[{ required: true }, { pattern: /^\d{6}$/, message: 'Enter 6 sandbox digits' }]}><Input.Password inputMode="numeric" autoComplete="off" maxLength={6} placeholder="000000" /></Form.Item>
      </div>}
    </div>
    {import.meta.env.DEV && <Form.Item label="External sandbox result" name="simulation_outcome"><Select options={[
      { value: 'success', label: 'Authorize successfully' },
      { value: 'declined', label: 'Provider decline' },
      { value: 'timeout', label: 'Provider timeout' },
      { value: 'provider_error', label: 'Provider unavailable' },
    ]} /></Form.Item>}
    <Alert type="info" showIcon title="Simulation only" description="Use only demo values. ChargeTrackr does not send these fields to the backend or to a real payment provider." />
  </section>
}

function PaymentReviewStep({
  pricing,
  quote,
  method,
}: {
  pricing?: Awaited<ReturnType<typeof getEffectivePricing>>
  quote?: PricingSimulation
  method?: SimulatedPaymentMethod
}) {
  const estimate = quote?.estimate
  const selectedMethod = method ?? 'simulated_card'

  if (!estimate) {
    return <Result status="warning" title="The payment estimate is unavailable" subTitle="Go back to the charging target and refresh the estimate before continuing." />
  }

  return <section className="charging-workflow-step payment-review-step">
    <header><ShieldCheck size={20} /><span><h2>Review and authorize</h2><p>Confirm the charging target, estimated cost and simulated payment method before sending the remote-start command.</p></span></header>
    <div className="payment-review-overview">
      <div className="payment-review-method"><span><PaymentMethodBrand method={selectedMethod} /></span><div><small>Payment method</small><strong>{paymentMethodLabel(selectedMethod)}</strong><p>Sandbox authorization</p></div></div>
      <div className="payment-review-target"><small>Automatic stop target</small><strong>{targetSummary(estimate)}</strong><p>{estimate.energy_kwh.toFixed(2)} kWh / {estimate.duration_minutes} min estimated</p></div>
    </div>
    <div className="payment-review-amounts">
      <div><small>Estimated charging total</small><strong>{formatTnd(estimate.amount_millimes)}</strong></div>
      <div><small>Temporary authorization</small><strong>{formatTnd(estimate.preauthorization_amount_millimes)}</strong></div>
    </div>
    {pricing && <div className="effective-pricing-card"><div><small>Applied tariff</small><strong>{pricing.name}</strong><span>{pricingSourceLabel(pricing.source)}</span></div><div><small>Energy</small><strong>{(pricing.effective_price_per_kwh_millimes / 1000).toFixed(3)} TND/kWh</strong></div><div><small>Start fee</small><strong>{(pricing.session_fee_millimes / 1000).toFixed(3)} TND</strong></div><div><small>Minimum</small><strong>{(pricing.minimum_charge_millimes / 1000).toFixed(3)} TND</strong></div></div>}
    {pricing?.plan && <div className="start-plan-benefit"><BadgePercent size={15} /><span><strong>{pricing.plan.name}</strong><small>{(pricing.plan.discount_basis_points / 100).toFixed(0)}% subscription discount is applied automatically.</small></span></div>}
    <div className="preauthorization-card"><ShieldCheck size={23} /><span><small>How the final charge works</small><strong>{formatTnd(estimate.preauthorization_amount_millimes)}</strong><p>The sandbox authorizes this ceiling first. ChargeTrackr captures only the final amount calculated from OCPP meter values and releases the unused balance.</p></span></div>
    <Alert type="success" showIcon title="Ready to authorize and start" description="The next action authorizes the simulated payment, then asks the OCPP station to start charging. No session is created until the station confirms it." />
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

function paymentFields(method?: SimulatedPaymentMethod): Array<keyof FormValues> {
  if (method === 'simulated_edinar') return ['edinar_card_number', 'edinar_expiry', 'edinar_code']
  if (method === 'simulated_d17') return ['d17_phone', 'd17_code']
  return ['cardholder_name', 'card_number', 'card_expiry', 'card_cvc']
}

function paymentMethodTitle(method: SimulatedPaymentMethod): string {
  if (method === 'simulated_edinar') return 'e-DINAR sandbox details'
  if (method === 'simulated_d17') return 'D17 sandbox confirmation'
  return 'Bank card sandbox details'
}

function paymentMethodLabel(method: SimulatedPaymentMethod): string {
  if (method === 'simulated_edinar') return 'e-DINAR Smart'
  if (method === 'simulated_d17') return 'D17 mobile wallet'
  return 'Visa / Mastercard'
}

function targetSummary(estimate: NonNullable<PricingSimulation['estimate']>): string {
  if (estimate.target_type === 'duration') return `${Math.round(estimate.target_value)} minutes`
  if (estimate.target_type === 'amount') return `${estimate.target_value.toFixed(3)} TND budget`
  return `${estimate.target_value.toFixed(2)} kWh`
}

function formatTnd(amountMillimes: number): string {
  return `${(amountMillimes / 1000).toFixed(3)} TND`
}
