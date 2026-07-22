import { useEffect, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { App, Button, Card, Drawer, Empty, Form, Input, InputNumber, Popconfirm, Select, Skeleton, Switch, Tag } from 'antd'
import { BatteryCharging, CarFront, PencilLine, Plus, Star, Trash2, Zap } from 'lucide-react'
import { MountainBanner } from '../components/MountainBanner'
import { ConnectorTypeIcon } from '../features/charging/ConnectorTypeIcon'
import { createVehicle, deleteVehicle, getVehicles, updateVehicle } from '../features/vehicles/vehicleApi'
import type { ConnectorType } from '../types/station'
import type { Vehicle, VehiclePayload } from '../types/vehicle'

const connectorOptions: Array<{ value: ConnectorType; label: string }> = [
  { value: 'Type 2', label: 'Type 2 (AC)' },
  { value: 'CCS2', label: 'CCS2 (DC fast charging)' },
  { value: 'CHAdeMO', label: 'CHAdeMO (DC fast charging)' },
]

type VehicleFormValues = VehiclePayload

export function VehiclesPage() {
  const queryClient = useQueryClient()
  const { message } = App.useApp()
  const [form] = Form.useForm<VehicleFormValues>()
  const [editingVehicle, setEditingVehicle] = useState<Vehicle | null>(null)
  const [drawerOpen, setDrawerOpen] = useState(false)
  const vehiclesQuery = useQuery({ queryKey: ['vehicles'], queryFn: getVehicles })

  const saveMutation = useMutation({
    mutationFn: (values: VehicleFormValues) => editingVehicle
      ? updateVehicle(editingVehicle.id, values)
      : createVehicle(values),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['vehicles'] })
      setDrawerOpen(false)
      setEditingVehicle(null)
      form.resetFields()
      void message.success(editingVehicle ? 'Vehicle updated.' : 'Vehicle added.')
    },
    onError: () => void message.error('Vehicle details could not be saved.'),
  })
  const deleteMutation = useMutation({
    mutationFn: deleteVehicle,
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['vehicles'] })
      void message.success('Vehicle removed.')
    },
    onError: () => void message.error('Vehicle could not be removed.'),
  })
  const defaultMutation = useMutation({
    mutationFn: (vehicle: Vehicle) => updateVehicle(vehicle.id, { is_default: true }),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['vehicles'] })
      void message.success('Default vehicle updated.')
    },
  })

  useEffect(() => {
    if (!drawerOpen) return
    form.setFieldsValue(editingVehicle ? {
      name: editingVehicle.name,
      make: editingVehicle.make ?? undefined,
      model: editingVehicle.model ?? undefined,
      model_year: editingVehicle.model_year ?? undefined,
      license_plate: editingVehicle.license_plate ?? undefined,
      battery_capacity_kwh: editingVehicle.battery_capacity_kwh ?? undefined,
      max_charging_power_kw: editingVehicle.max_charging_power_kw ?? undefined,
      connector_types: editingVehicle.connector_types,
      is_default: editingVehicle.is_default,
    } : { connector_types: ['Type 2'], is_default: vehiclesQuery.data?.length === 0 })
  }, [drawerOpen, editingVehicle, form, vehiclesQuery.data?.length])

  const openCreate = () => {
    setEditingVehicle(null)
    setDrawerOpen(true)
  }
  const openEdit = (vehicle: Vehicle) => {
    setEditingVehicle(vehicle)
    setDrawerOpen(true)
  }
  const vehicles = vehiclesQuery.data ?? []

  return <div className="page-stack vehicle-page">
    <MountainBanner color="purple" breadcrumb={['Client', 'My vehicles']} title="My vehicles" count={vehicles.length} subtitle="Save compatibility details so the charging workflow can suggest the right connector." />

    <section className="vehicle-toolbar">
      <div><strong>{vehicles.length === 0 ? 'No vehicle profile yet' : `${vehicles.length} vehicle${vehicles.length === 1 ? '' : 's'} saved`}</strong><span>{vehicles.some((vehicle) => vehicle.is_default) ? 'Your default vehicle will be selected during charging.' : 'Choose a default vehicle to speed up charging.'}</span></div>
      <Button type="primary" icon={<Plus size={16} />} onClick={openCreate}>Add vehicle</Button>
    </section>

    {vehiclesQuery.isLoading ? <Skeleton active paragraph={{ rows: 8 }} /> : vehicles.length === 0 ? <Card className="vehicle-empty-card"><Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="Add your first vehicle to keep its charging compatibility ready." ><Button type="primary" icon={<Plus size={16} />} onClick={openCreate}>Add vehicle</Button></Empty></Card> : <section className="vehicle-grid">
      {vehicles.map((vehicle) => <article className={`vehicle-card ${vehicle.is_default ? 'vehicle-card--default' : ''}`} key={vehicle.id}>
        <header><span className="vehicle-card-icon"><CarFront size={22} /></span><div><div className="vehicle-card-title"><h2>{vehicle.name}</h2>{vehicle.is_default && <Tag color="green" icon={<Star size={12} fill="currentColor" />}>Default</Tag>}</div><p>{[vehicle.make, vehicle.model, vehicle.model_year].filter(Boolean).join(' ') || 'Vehicle details not specified'}</p></div></header>
        <div className="vehicle-connector-row">{vehicle.connector_types.map((type) => <span key={type} title={type}><ConnectorTypeIcon type={type} /><small>{type}</small></span>)}</div>
        <dl className="vehicle-specs"><div><dt><BatteryCharging size={15} /> Battery</dt><dd>{vehicle.battery_capacity_kwh ? `${vehicle.battery_capacity_kwh} kWh` : 'Not specified'}</dd></div><div><dt><Zap size={15} /> Max power</dt><dd>{vehicle.max_charging_power_kw ? `${vehicle.max_charging_power_kw} kW` : 'Not specified'}</dd></div></dl>
        <footer><span>{vehicle.license_plate ? vehicle.license_plate : 'No plate recorded'}</span><div><Button type="text" icon={<PencilLine size={15} />} onClick={() => openEdit(vehicle)}>Edit</Button>{!vehicle.is_default && <Button type="text" icon={<Star size={15} />} loading={defaultMutation.isPending} onClick={() => defaultMutation.mutate(vehicle)}>Set default</Button>}<Popconfirm title="Remove this vehicle?" description="Existing session history will remain available." okText="Remove" onConfirm={() => deleteMutation.mutate(vehicle.id)}><Button type="text" danger icon={<Trash2 size={15} />} loading={deleteMutation.isPending} aria-label={`Remove ${vehicle.name}`} /></Popconfirm></div></footer>
      </article>)}
    </section>}

    <Drawer className="vehicle-drawer" title={editingVehicle ? `Edit ${editingVehicle.name}` : 'Add vehicle'} open={drawerOpen} onClose={() => setDrawerOpen(false)} size={500} destroyOnHidden extra={<Button type="primary" loading={saveMutation.isPending} onClick={() => form.submit()}>Save vehicle</Button>}>
      <p className="vehicle-drawer-copy">Only compatibility and charging details are used to guide your next session. License plate is optional.</p>
      <Form form={form} layout="vertical" onFinish={(values) => saveMutation.mutate(values)} requiredMark="optional">
        <Form.Item name="name" label="Vehicle name" rules={[{ required: true, message: 'Enter a name for this vehicle.' }]}><Input placeholder="Example: My daily EV" /></Form.Item>
        <div className="vehicle-form-grid"><Form.Item name="make" label="Make"><Input placeholder="Tesla, BYD, Kia..." /></Form.Item><Form.Item name="model" label="Model"><Input placeholder="Model 3, Atto 3..." /></Form.Item></div>
        <div className="vehicle-form-grid"><Form.Item name="model_year" label="Model year"><InputNumber min={1990} max={new Date().getFullYear() + 1} className="full-width" /></Form.Item><Form.Item name="license_plate" label="License plate"><Input placeholder="Optional" /></Form.Item></div>
        <div className="vehicle-form-grid"><Form.Item name="battery_capacity_kwh" label="Battery capacity"><InputNumber min={1} max={250} step={0.1} className="full-width" suffix="kWh" /></Form.Item><Form.Item name="max_charging_power_kw" label="Maximum charging power"><InputNumber min={1} max={500} step={0.1} className="full-width" suffix="kW" /></Form.Item></div>
        <Form.Item name="connector_types" label="Compatible connectors" rules={[{ required: true, message: 'Choose at least one connector type.' }]}><Select mode="multiple" options={connectorOptions} /></Form.Item>
        <Form.Item name="is_default" valuePropName="checked"><Switch checkedChildren="Default vehicle" unCheckedChildren="Set as default" /></Form.Item>
      </Form>
    </Drawer>
  </div>
}
