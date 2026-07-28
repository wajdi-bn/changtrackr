import { useQuery } from '@tanstack/react-query'
import { Alert, Button, Divider, Drawer, Form, Input, InputNumber, Radio, Select, Steps } from 'antd'
import { Building2, Cable, Check, Crosshair, Database, Keyboard, Plus, RadioTower, Server, Trash2, Zap } from 'lucide-react'
import { useEffect, useState } from 'react'
import { httpClient } from '../../api/httpClient'
import type { CommissioningTarget, ConnectorPayload, StationCommissioningPayload } from '../../types/station'
import { useAuth } from '../auth/useAuth'
import { LocationPickerMap } from '../maps/LocationPickerMap'
import { formatCoordinates } from '../maps/mapUtils'

interface StationCommissioningDrawerProps {
  open: boolean
  submitting: boolean
  initialCoordinates?: { latitude: number; longitude: number } | null
  onClose: () => void
  onSubmit: (values: StationCommissioningPayload) => void
}

interface OrganizationOption {
  id: number
  name: string
  status: string
}

const connectorTypes: ConnectorPayload['type'][] = ['CCS2', 'Type 2', 'CHAdeMO']
const steps = [
  { title: 'Station', icon: <Building2 size={16} /> },
  { title: 'Hardware', icon: <Cable size={16} /> },
  { title: 'Connection', icon: <RadioTower size={16} /> },
  { title: 'Review', icon: <Check size={16} /> },
]

export function StationCommissioningDrawer({
  open,
  submitting,
  initialCoordinates,
  onClose,
  onSubmit,
}: StationCommissioningDrawerProps) {
  const [form] = Form.useForm<StationCommissioningPayload>()
  const [currentStep, setCurrentStep] = useState(0)
  const [manualCoordinates, setManualCoordinates] = useState(false)
  const { primaryRole } = useAuth()
  const isSuperAdmin = primaryRole === 'super_admin'
  const latitude = Form.useWatch('latitude', form) ?? 36.8065
  const longitude = Form.useWatch('longitude', form) ?? 10.1815
  const target = Form.useWatch('commissioning_target', form) ?? 'external'
  const reference = Form.useWatch('reference', form)
  const values = Form.useWatch([], form)

  const organizationsQuery = useQuery({
    queryKey: ['platform-organizations', 'station-commissioning-options'],
    queryFn: async () => (
      await httpClient.get<{ data: OrganizationOption[] }>('/organizations', { params: { status: 'active' } })
    ).data.data,
    enabled: open && isSuperAdmin,
  })

  useEffect(() => {
    if (!open) return
    setCurrentStep(0)
    setManualCoordinates(false)
    form.resetFields()
    form.setFieldsValue({
      latitude: initialCoordinates?.latitude ?? 36.8065,
      longitude: initialCoordinates?.longitude ?? 10.1815,
      max_power_kw: 120,
      ocpp_version: 'OCPP 1.6J',
      model_image: '/assets/charger-terra-hp-150.png',
      commissioning_target: 'external',
      connectors: [{
        external_id: 'A1',
        ocpp_connector_id: 1,
        type: 'CCS2',
        current_type: 'DC',
        max_power_kw: 120,
      }],
    })
  }, [form, initialCoordinates, open])

  async function next() {
    const fieldGroups: Array<Array<string | ['connectors']>> = [
      [
        ...(isSuperAdmin ? ['organization_id'] : []),
        'name', 'reference', 'location_name', 'city', 'address', 'latitude', 'longitude',
      ],
      ['manufacturer', 'model', 'max_power_kw', 'ocpp_version', 'connectors'],
      ['commissioning_target', 'ocpp_identity'],
    ]
    await form.validateFields(fieldGroups[currentStep] as never)
    setCurrentStep((step) => Math.min(step + 1, steps.length - 1))
  }

  function close() {
    if (submitting) return
    onClose()
  }

  return (
    <Drawer
      className="station-commissioning-drawer"
      size={820}
      zIndex={1300}
      open={open}
      onClose={close}
      destroyOnHidden
      title={<div className="commissioning-drawer-title"><span>Commission a station</span><small>Create the station, its physical connectors and its OCPP access together.</small></div>}
      footer={(
        <div className="commissioning-footer">
          <Button onClick={currentStep === 0 ? close : () => setCurrentStep((step) => step - 1)}>
            {currentStep === 0 ? 'Cancel' : 'Back'}
          </Button>
          {currentStep < steps.length - 1
            ? <Button type="primary" onClick={() => void next()}>Continue</Button>
            : <Button type="primary" loading={submitting} onClick={() => form.submit()}>Create station</Button>}
        </div>
      )}
    >
      <Steps className="commissioning-steps" current={currentStep} items={steps} responsive={false} />
      <Form<StationCommissioningPayload>
        form={form}
        layout="vertical"
        requiredMark="optional"
        onFinish={onSubmit}
      >
        <div hidden={currentStep !== 0}>
          <StepHeading eyebrow="01 / Station identity" title="Place the station on the network" description="Use a unique operational reference and set its exact public location." />
          <div className="commissioning-form-grid">
            {isSuperAdmin && (
              <Form.Item className="commissioning-field-wide" name="organization_id" label="Owning organization" rules={[{ required: true, message: 'Select the organization that owns this station.' }]}>
                <Select
                  showSearch
                  optionFilterProp="label"
                  loading={organizationsQuery.isLoading}
                  placeholder="Select an active organization"
                  options={(organizationsQuery.data ?? []).map((organization) => ({ value: organization.id, label: organization.name }))}
                />
              </Form.Item>
            )}
            <Form.Item name="name" label="Station name" rules={[{ required: true, min: 2, max: 160 }]}><Input placeholder="Lac 1 Fast Hub" /></Form.Item>
            <Form.Item name="reference" label="Internal reference" rules={[{ required: true, max: 80, pattern: /^[A-Za-z0-9._:-]+$/, message: 'Use letters, numbers, dots, colons, underscores or hyphens.' }]}>
              <Input placeholder="CT-TUN-101" onBlur={() => { if (!form.getFieldValue('ocpp_identity')) form.setFieldValue('ocpp_identity', form.getFieldValue('reference')) }} />
            </Form.Item>
            <Form.Item name="location_name" label="Location name" rules={[{ required: true, max: 160 }]}><Input placeholder="Les Berges du Lac 1" /></Form.Item>
            <Form.Item name="city" label="City" rules={[{ required: true, max: 100 }]}><Input placeholder="Tunis" /></Form.Item>
            <Form.Item className="commissioning-field-wide" name="address" label="Street address" rules={[{ required: true, max: 255 }]}><Input placeholder="Rue du Lac Biwa, Tunis" /></Form.Item>
          </div>
          <section className="commissioning-location">
            <header>
              <div><span><Crosshair size={18} /></span><div><h3>Exact station position</h3><p>Click the map or drag the marker. Coordinates remain editable when needed.</p></div></div>
              <Button type="text" icon={<Keyboard size={15} />} onClick={() => setManualCoordinates((shown) => !shown)}>{manualCoordinates ? 'Hide coordinates' : 'Enter manually'}</Button>
            </header>
            <LocationPickerMap
              value={{ latitude: Number(latitude), longitude: Number(longitude) }}
              onChange={(coordinates) => form.setFieldsValue(coordinates)}
            />
            <div className="commissioning-coordinate"><Crosshair size={14} />{formatCoordinates(Number(latitude), Number(longitude))}</div>
            {manualCoordinates && <div className="commissioning-form-grid commissioning-coordinate-fields">
              <Form.Item name="latitude" label="Latitude" rules={[{ required: true }, { type: 'number', min: -90, max: 90 }]}><InputNumber precision={7} style={{ width: '100%' }} /></Form.Item>
              <Form.Item name="longitude" label="Longitude" rules={[{ required: true }, { type: 'number', min: -180, max: 180 }]}><InputNumber precision={7} style={{ width: '100%' }} /></Form.Item>
            </div>}
            {!manualCoordinates && <>
              <Form.Item name="latitude" hidden><InputNumber /></Form.Item>
              <Form.Item name="longitude" hidden><InputNumber /></Form.Item>
            </>}
          </section>
        </div>

        <div hidden={currentStep !== 1}>
          <StepHeading eyebrow="02 / Hardware" title="Describe the charger and its connectors" description="Connector identifiers must match the labels and OCPP connector IDs used by the device." />
          <div className="commissioning-form-grid">
            <Form.Item name="manufacturer" label="Manufacturer" rules={[{ required: true, max: 120 }]}><Input placeholder="ABB" /></Form.Item>
            <Form.Item name="model" label="Model" rules={[{ required: true, max: 120 }]}><Input placeholder="Terra 124" /></Form.Item>
            <Form.Item name="max_power_kw" label="Station maximum power" rules={[{ required: true }]}><InputNumber min={1} max={1000} suffix="kW" style={{ width: '100%' }} /></Form.Item>
            <Form.Item name="ocpp_version" label="OCPP version" rules={[{ required: true }]}><Select options={[{ value: 'OCPP 1.6J' }, { value: 'OCPP 2.0.1', disabled: true, label: 'OCPP 2.0.1 - planned' }]} /></Form.Item>
            <Form.Item className="commissioning-field-wide" name="model_image" label="Station visual"><Select options={[
              { value: '/assets/charger-terra-hp-150.png', label: 'ABB Terra HP 150' },
              { value: '/assets/charger-evbox-troniq.png', label: 'EVBox Troniq' },
              { value: '/assets/charger-enext-park-dc.png', label: 'eNext Park DC' },
              { value: '/assets/charger-raption-100.png', label: 'Raption 100' },
            ]} /></Form.Item>
          </div>
          <Divider />
          <Form.List name="connectors" rules={[{ validator: async (_, items) => { if (!items?.length) throw new Error('Add at least one connector.') } }]}>
            {(fields, { add, remove }, { errors }) => (
              <section className="commissioning-connectors">
                <header><div><h3>Physical connectors</h3><p>Each connector is created with the station.</p></div><Button icon={<Plus size={15} />} onClick={() => {
                  const next = fields.length + 1
                  add({ external_id: `A${next}`, ocpp_connector_id: next, type: 'CCS2', current_type: 'DC', max_power_kw: form.getFieldValue('max_power_kw') ?? 120 })
                }}>Add connector</Button></header>
                {fields.map(({ key, ...field }, index) => (
                  <article className="commissioning-connector-row" key={key}>
                    <span className="commissioning-connector-index"><Zap size={16} />{index + 1}</span>
                    <Form.Item {...field} name={[field.name, 'external_id']} label="Label" rules={[{ required: true }]}><Input placeholder="A1" /></Form.Item>
                    <Form.Item {...field} name={[field.name, 'ocpp_connector_id']} label="OCPP ID" rules={[{ required: true }]}><InputNumber min={1} max={65535} style={{ width: '100%' }} /></Form.Item>
                    <Form.Item {...field} name={[field.name, 'type']} label="Plug" rules={[{ required: true }]}><Select options={connectorTypes.map((type) => ({ value: type }))} /></Form.Item>
                    <Form.Item {...field} name={[field.name, 'current_type']} label="Current" rules={[{ required: true }]}><Select options={[{ value: 'AC' }, { value: 'DC' }]} /></Form.Item>
                    <Form.Item {...field} name={[field.name, 'max_power_kw']} label="Power" rules={[{ required: true }]}><InputNumber min={1} max={1000} suffix="kW" style={{ width: '100%' }} /></Form.Item>
                    <Button aria-label={`Remove connector ${index + 1}`} type="text" danger icon={<Trash2 size={16} />} disabled={fields.length === 1} onClick={() => remove(field.name)} />
                  </article>
                ))}
                <Form.ErrorList errors={errors} />
              </section>
            )}
          </Form.List>
        </div>

        <div hidden={currentStep !== 2}>
          <StepHeading eyebrow="03 / OCPP connection" title="Choose how this station will connect" description="Production credentials and local simulator configuration follow separate security boundaries." />
          <Form.Item name="commissioning_target" rules={[{ required: true }]}>
            <Radio.Group className="commissioning-targets">
              <TargetOption value="external" icon={<RadioTower size={21} />} title="Physical or external station" description="Generate a unique Basic Auth secret and display it once for device configuration." />
              <TargetOption value="simulator" icon={<Server size={21} />} title="Local SAP simulator" description="Prepare the database record, then register it through the local developer command." />
              <TargetOption value="inventory" icon={<Database size={21} />} title="Inventory only" description="Create the asset and connectors without enabling OCPP access yet." />
            </Radio.Group>
          </Form.Item>
          <div className="commissioning-identity-panel">
            <div><span><RadioTower size={18} /></span><div><h3>Charge point identity</h3><p>Must exactly match the identity sent in the OCPP WebSocket URL.</p></div></div>
            <Form.Item name="ocpp_identity" label="OCPP identity" rules={[{ required: true }, { pattern: /^[A-Za-z0-9._:-]+$/, message: 'Use letters, numbers, dots, colons, underscores or hyphens.' }]}>
              <Input placeholder={reference || 'CT-TUN-101'} />
            </Form.Item>
          </div>
          {target === 'external' && <Alert type="warning" showIcon title="Save the generated secret after creation" description="The plaintext station password will be displayed once. Only its secure hash is stored by the backend." />}
          {target === 'simulator' && <Alert type="info" showIcon title="Local developer action required" description="After creation, run the generated command from the repository root and restart the simulator containers." />}
          {target === 'inventory' && <Alert type="info" showIcon title="No live status until provisioning" description="The station remains unavailable to clients until OCPP credentials are configured later." />}
        </div>

        <div hidden={currentStep !== 3}>
          <StepHeading eyebrow="04 / Review" title="Confirm the commissioning record" description="The station and every connector will be committed atomically." />
          <section className="commissioning-review">
            <ReviewGroup title="Station">
              <ReviewValue label="Name" value={values?.name} />
              <ReviewValue label="Reference" value={values?.reference} />
              <ReviewValue label="Location" value={[values?.location_name, values?.city].filter(Boolean).join(', ')} />
              <ReviewValue label="Position" value={formatCoordinates(Number(values?.latitude), Number(values?.longitude))} />
            </ReviewGroup>
            <ReviewGroup title="Hardware">
              <ReviewValue label="Charger" value={[values?.manufacturer, values?.model].filter(Boolean).join(' ')} />
              <ReviewValue label="Power" value={`${values?.max_power_kw ?? '-'} kW`} />
              <ReviewValue label="Protocol" value={values?.ocpp_version} />
              <ReviewValue label="Connectors" value={`${values?.connectors?.length ?? 0} configured`} />
            </ReviewGroup>
            <ReviewGroup title="Connection">
              <ReviewValue label="Target" value={targetLabels[target]} />
              <ReviewValue label="OCPP identity" value={values?.ocpp_identity} />
              <ReviewValue label="Initial state" value={target === 'external' ? 'Awaiting first connection' : target === 'simulator' ? 'Awaiting local registration' : 'Inventory only'} />
            </ReviewGroup>
          </section>
          <Alert type="success" showIcon title="Ready to create" description="If any field is invalid or duplicated, nothing will be partially created." />
        </div>
      </Form>
    </Drawer>
  )
}

function StepHeading({ eyebrow, title, description }: { eyebrow: string; title: string; description: string }) {
  return <header className="commissioning-step-heading"><span>{eyebrow}</span><h2>{title}</h2><p>{description}</p></header>
}

function TargetOption({ value, icon, title, description }: { value: CommissioningTarget; icon: React.ReactNode; title: string; description: string }) {
  return <Radio value={value}><span className="commissioning-target-icon">{icon}</span><span><strong>{title}</strong><small>{description}</small></span></Radio>
}

function ReviewGroup({ title, children }: { title: string; children: React.ReactNode }) {
  return <article><header>{title}</header><div>{children}</div></article>
}

function ReviewValue({ label, value }: { label: string; value?: string | number | null }) {
  return <span><small>{label}</small><strong>{value || '-'}</strong></span>
}

const targetLabels: Record<CommissioningTarget, string> = {
  external: 'Physical or external station',
  simulator: 'Local SAP simulator',
  inventory: 'Inventory only',
}
