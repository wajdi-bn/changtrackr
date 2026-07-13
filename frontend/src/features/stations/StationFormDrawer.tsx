import { Button, Drawer, Form, Input, InputNumber, Select, Space } from 'antd'
import { useEffect } from 'react'
import type { Station, StationPayload } from '../../types/station'

interface StationFormDrawerProps {
  open: boolean
  station?: Station | null
  submitting: boolean
  onClose: () => void
  onSubmit: (values: StationPayload) => void
}

const statusOptions = ['available', 'charging', 'faulted', 'offline', 'maintenance'].map((value) => ({
  value,
  label: value.charAt(0).toUpperCase() + value.slice(1),
}))

export function StationFormDrawer({ open, station, submitting, onClose, onSubmit }: StationFormDrawerProps) {
  const [form] = Form.useForm<StationPayload>()

  useEffect(() => {
    if (!open) return
    form.setFieldsValue(station ? {
      name: station.name,
      reference: station.reference,
      location_name: station.location_name,
      city: station.city,
      address: station.address,
      latitude: station.latitude,
      longitude: station.longitude,
      status: station.status,
      max_power_kw: station.max_power_kw,
      model: station.model,
      manufacturer: station.manufacturer,
      ocpp_version: station.ocpp_version,
      model_image: station.model_image,
    } : {
      status: 'offline',
      ocpp_version: 'OCPP 1.6J',
      latitude: 36.8065,
      longitude: 10.1815,
      model_image: '/assets/charger-terra-hp-150.png',
    })
  }, [form, open, station])

  return (
    <Drawer
      title={station ? 'Edit charging station' : 'Add charging station'}
      open={open}
      onClose={onClose}
      size={560}
      extra={<Button type="primary" loading={submitting} onClick={() => form.submit()}>{station ? 'Save changes' : 'Add station'}</Button>}
    >
      <Form form={form} layout="vertical" onFinish={onSubmit} requiredMark="optional">
        <div className="station-form-grid">
          <Form.Item label="Station name" name="name" rules={[{ required: true }]}><Input placeholder="Lac 1 Fast Hub" /></Form.Item>
          <Form.Item label="Reference" name="reference" rules={[{ required: true }]}><Input placeholder="CT-TUN-001" /></Form.Item>
          <Form.Item label="Location" name="location_name" rules={[{ required: true }]}><Input placeholder="Lac 1" /></Form.Item>
          <Form.Item label="City" name="city" rules={[{ required: true }]}><Input placeholder="Tunis" /></Form.Item>
          <Form.Item className="station-form-wide" label="Address" name="address" rules={[{ required: true }]}><Input placeholder="Street and district" /></Form.Item>
          <Form.Item label="Latitude" name="latitude" rules={[{ required: true }]}><InputNumber style={{ width: '100%' }} precision={7} /></Form.Item>
          <Form.Item label="Longitude" name="longitude" rules={[{ required: true }]}><InputNumber style={{ width: '100%' }} precision={7} /></Form.Item>
          <Form.Item label="Status" name="status" rules={[{ required: true }]}><Select options={statusOptions} /></Form.Item>
          <Form.Item label="Maximum power (kW)" name="max_power_kw" rules={[{ required: true }]}><InputNumber style={{ width: '100%' }} min={1} max={1000} /></Form.Item>
          <Form.Item label="Manufacturer" name="manufacturer" rules={[{ required: true }]}><Input placeholder="ABB" /></Form.Item>
          <Form.Item label="Model" name="model" rules={[{ required: true }]}><Input placeholder="Terra HP 150" /></Form.Item>
          <Form.Item label="OCPP version" name="ocpp_version" rules={[{ required: true }]}><Select options={[{ value: 'OCPP 1.6J' }, { value: 'OCPP 2.0.1' }]} /></Form.Item>
          <Form.Item label="Model image" name="model_image"><Select options={[
            { value: '/assets/charger-terra-hp-150.png', label: 'ABB Terra HP 150' },
            { value: '/assets/charger-evbox-troniq.png', label: 'EVBox Troniq' },
            { value: '/assets/charger-enext-park-dc.png', label: 'eNext Park DC' },
            { value: '/assets/charger-raption-100.png', label: 'Raption 100' },
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
