import { Alert, Button, Drawer, Empty, Form, Select } from 'antd'
import { BatteryCharging, MapPin, PlugZap, Zap } from 'lucide-react'
import { useEffect, useMemo } from 'react'
import { useQuery } from '@tanstack/react-query'
import type { Station } from '../../types/station'
import { getEffectivePricing } from '../tariffs/tariffApi'

interface StartSessionDrawerProps {
  open: boolean
  stations: Station[]
  initialStationId?: number | null
  submitting: boolean
  onClose: () => void
  onSubmit: (payload: { station_id: number; connector_id: number }) => void
}

export function StartSessionDrawer({ open, stations, initialStationId, submitting, onClose, onSubmit }: StartSessionDrawerProps) {
  const [form] = Form.useForm<{ station_id: number; connector_id: number }>()
  const stationId = Form.useWatch('station_id', form)
  const connectorId = Form.useWatch('connector_id', form)
  const availableStations = useMemo(() => stations.filter((station) => station.available_connectors_count > 0), [stations])
  const selectedStation = availableStations.find((station) => station.id === stationId)
  const availableConnectors = selectedStation?.connectors.filter((connector) => connector.status === 'available') ?? []
  const pricingQuery = useQuery({
    queryKey: ['effective-pricing', stationId, connectorId],
    queryFn: () => getEffectivePricing(stationId, connectorId),
    enabled: Boolean(stationId && connectorId),
  })

  useEffect(() => {
    if (!open) return
    form.setFieldsValue({ station_id: initialStationId ?? undefined, connector_id: undefined })
  }, [form, initialStationId, open])

  return (
    <Drawer open={open} title="Start a charging session" size={480} onClose={onClose}>
      <div className="start-session-intro">
        <span><BatteryCharging size={20} /></span>
        <div><strong>Connect and confirm</strong><p>Choose an available connector. The platform simulates the OCPP start command for this MVP.</p></div>
      </div>
      {availableStations.length === 0 ? <Empty description="No connector is currently available" /> : (
        <Form form={form} layout="vertical" onFinish={onSubmit} requiredMark="optional">
          <Form.Item label="Charging station" name="station_id" rules={[{ required: true, message: 'Choose a station' }]}>
            <Select
              showSearch
              optionFilterProp="label"
              placeholder="Select a station"
              onChange={() => form.setFieldValue('connector_id', undefined)}
              options={availableStations.map((station) => ({
                value: station.id,
                label: `${station.name} - ${station.city}`,
              }))}
            />
          </Form.Item>
          {selectedStation && (
            <div className="start-station-context">
              <img src={selectedStation.model_image ?? '/assets/charger-terra-hp-150.png'} alt="" />
              <div><strong>{selectedStation.name}</strong><span><MapPin size={13} />{selectedStation.location}</span></div>
              <b>{selectedStation.available_connectors_count} free</b>
            </div>
          )}
          <Form.Item label="Connector" name="connector_id" rules={[{ required: true, message: 'Choose a connector' }]}>
            <Select
              disabled={!selectedStation}
              placeholder="Select an available connector"
              options={availableConnectors.map((connector) => ({
                value: connector.id,
                label: `${connector.external_id} - ${connector.type} - ${connector.max_power_kw} kW`,
              }))}
            />
          </Form.Item>
          {pricingQuery.data && <div className="effective-pricing-card">
            <div><small>Applied tariff</small><strong>{pricingQuery.data.name}</strong><span>{pricingSourceLabel(pricingQuery.data.source)}</span></div>
            <div><small>Energy</small><strong>{(pricingQuery.data.price_per_kwh_millimes / 1000).toFixed(3)} TND/kWh</strong></div>
            <div><small>Start fee</small><strong>{(pricingQuery.data.session_fee_millimes / 1000).toFixed(3)} TND</strong></div>
            <div><small>Minimum</small><strong>{(pricingQuery.data.minimum_charge_millimes / 1000).toFixed(3)} TND</strong></div>
          </div>}
          <Alert
            type="info"
            showIcon
            icon={<PlugZap size={16} />}
            message="MVP simulation"
            description="No physical charger command is sent yet. The same service boundary can later call the OCPP backend."
          />
          <Button className="start-session-submit" type="primary" htmlType="submit" icon={<Zap size={16} />} loading={submitting} block>
            Start charging
          </Button>
        </Form>
      )}
    </Drawer>
  )
}

function pricingSourceLabel(source: string) {
  return ({ connector: 'Connector-specific', station: 'Station-specific', organization_default: 'Organization default', configuration_fallback: 'Configuration fallback' } as Record<string, string>)[source] ?? source
}
