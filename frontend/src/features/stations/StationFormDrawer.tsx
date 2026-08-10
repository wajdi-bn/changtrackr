import { Alert, Button, Drawer, Form, Input, InputNumber, Select, Space } from 'antd'
import { Crosshair, Keyboard } from 'lucide-react'
import { useEffect, useState } from 'react'
import type { Station, StationPayload } from '../../types/station'
import { LocationPickerMap } from '../maps/LocationPickerMap'
import { formatCoordinates } from '../maps/mapUtils'
import { availabilityReasonLabel } from './availabilityLabels'

interface StationFormDrawerProps {
  open: boolean
  station?: Station | null
  submitting: boolean
  initialCoordinates?: { latitude: number; longitude: number } | null
  onClose: () => void
  onSubmit: (values: StationPayload) => void
}

const statusOptions = ['available', 'charging', 'faulted', 'offline', 'maintenance', 'reserved', 'unavailable'].map((value) => ({
  value,
  label: value.charAt(0).toUpperCase() + value.slice(1),
}))

export function StationFormDrawer({ open, station, submitting, initialCoordinates, onClose, onSubmit }: StationFormDrawerProps) {
  const [form] = Form.useForm<StationPayload>()
  const [manualCoordinates, setManualCoordinates] = useState(false)
  const latitude = Form.useWatch('latitude', form) ?? 36.8065
  const longitude = Form.useWatch('longitude', form) ?? 10.1815

  useEffect(() => {
    if (!open) return
    form.resetFields()
    setManualCoordinates(false)
    form.setFieldsValue(station ? {
      name: station.name,
      reference: station.reference,
      location_name: station.location_name,
      city: station.city,
      address: station.address,
      latitude: station.latitude,
      longitude: station.longitude,
      status: station.status,
      availability_override: station.availability_override === 'disabled' ? 'disabled' : undefined,
      max_power_kw: station.max_power_kw,
      model: station.model,
      manufacturer: station.manufacturer,
      ocpp_version: station.ocpp_version,
      model_image: station.model_image,
    } : {
      status: 'offline',
      ocpp_version: 'OCPP 1.6J',
      latitude: initialCoordinates?.latitude ?? 36.8065,
      longitude: initialCoordinates?.longitude ?? 10.1815,
      model_image: '/assets/stations/models/terra-hp-150.webp',
    })
  }, [form, initialCoordinates, open, station])

  function submit(values: StationPayload) {
    if (!station?.ocpp_managed) {
      onSubmit(values)
      return
    }

    const payload = { ...values }
    delete payload.status
    if (station.availability_override === 'maintenance') {
      delete payload.availability_override
    } else {
      payload.availability_override = values.availability_override ?? null
    }
    onSubmit(payload)
  }

  return (
    <Drawer
      title={station ? 'Edit charging station' : 'Add charging station'}
      open={open}
      onClose={onClose}
      size={560}
      extra={<Button type="primary" loading={submitting} onClick={() => form.submit()}>{station ? 'Save changes' : 'Add station'}</Button>}
    >
      <Form form={form} layout="vertical" onFinish={submit} requiredMark="optional">
        <div className="station-form-grid">
          <Form.Item label="Station name" name="name" rules={[{ required: true }]}><Input placeholder="Lac 1 Fast Hub" /></Form.Item>
          <Form.Item label="Reference" name="reference" rules={[{ required: true }]}><Input placeholder="CT-TUN-001" /></Form.Item>
          <Form.Item label="Location" name="location_name" rules={[{ required: true }]}><Input placeholder="Lac 1" /></Form.Item>
          <Form.Item label="City" name="city" rules={[{ required: true }]}><Input placeholder="Tunis" /></Form.Item>
          <Form.Item className="station-form-wide" label="Address" name="address" rules={[{ required: true }]}><Input placeholder="Street and district" /></Form.Item>
          <section className="station-location-picker station-form-wide">
            <header><div><span><Crosshair size={17} /></span><div><h3>Station position</h3><p>Click the map or drag the marker to set the coordinates.</p></div></div><Button type="text" icon={<Keyboard size={15} />} onClick={() => setManualCoordinates((value) => !value)}>{manualCoordinates ? 'Hide manual input' : 'Enter manually'}</Button></header>
            <LocationPickerMap
              value={{ latitude: Number(latitude), longitude: Number(longitude) }}
              onChange={(coordinates) => form.setFieldsValue(coordinates)}
            />
            <div className="station-location-value"><Crosshair size={14} />{formatCoordinates(Number(latitude), Number(longitude))}</div>
            <div className={`station-coordinate-fields ${manualCoordinates ? 'is-visible' : ''}`}>
              <Form.Item label="Latitude" name="latitude" rules={[{ required: true }, { type: 'number', min: -90, max: 90 }]}><InputNumber style={{ width: '100%' }} precision={7} /></Form.Item>
              <Form.Item label="Longitude" name="longitude" rules={[{ required: true }, { type: 'number', min: -180, max: 180 }]}><InputNumber style={{ width: '100%' }} precision={7} /></Form.Item>
            </div>
          </section>
          {station?.ocpp_managed ? <>
            <Form.Item label="Operational status">
              <Input value={`${station.status.charAt(0).toUpperCase() + station.status.slice(1)} - ${availabilityReasonLabel(station.availability_reason)}`} disabled />
            </Form.Item>
            <Form.Item label="Operational override" name="availability_override">
              <Select disabled={station.availability_override === 'maintenance'} allowClear placeholder="Automatic (OCPP)" options={[
                { value: 'disabled', label: 'Manually disabled' },
              ]} />
            </Form.Item>
            <Alert className="station-form-wide" type="info" showIcon title="Status managed by OCPP" description="Use the station supervision action for planned maintenance. Clear this administrative override to resume automatic calculation." />
          </> : <Form.Item label="Status" name="status" rules={[{ required: true }]}><Select options={statusOptions} /></Form.Item>}
          <Form.Item label="Maximum power (kW)" name="max_power_kw" rules={[{ required: true }]}><InputNumber style={{ width: '100%' }} min={1} max={1000} /></Form.Item>
          <Form.Item label="Manufacturer" name="manufacturer" rules={[{ required: true }]}><Input placeholder="ABB" /></Form.Item>
          <Form.Item label="Model" name="model" rules={[{ required: true }]}><Input placeholder="Terra HP 150" /></Form.Item>
          <Form.Item label="OCPP version" name="ocpp_version" rules={[{ required: true }]}><Select options={[{ value: 'OCPP 1.6J' }, { value: 'OCPP 2.0.1' }]} /></Form.Item>
          <Form.Item label="Model image" name="model_image"><Select options={[
            { value: '/assets/stations/models/terra-hp-150.webp', label: 'ABB Terra HP 150' },
            { value: '/assets/stations/models/evbox-troniq.webp', label: 'EVBox Troniq' },
            { value: '/assets/stations/models/enext-park-dc.webp', label: 'eNext Park DC' },
            { value: '/assets/stations/models/raption-100.webp', label: 'Raption 100' },
          ]} /></Form.Item>
        </div>
        <Space className="station-drawer-footer">
          <Button onClick={onClose}>Cancel</Button>
          <Button type="primary" htmlType="submit" loading={submitting}>{station ? 'Save changes' : 'Add station'}</Button>
        </Space>
      </Form>
    </Drawer>
  )
}
