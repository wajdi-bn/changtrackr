import { useQuery } from '@tanstack/react-query'
import { Alert, Button, Drawer, Form, Input, InputNumber, Radio, Select, Skeleton, Steps } from 'antd'
import { Building2, Check, Crosshair, Keyboard, RadioTower, Zap } from 'lucide-react'
import { useEffect, useState } from 'react'
import type { ReactNode } from 'react'
import { httpClient } from '../../api/httpClient'
import type { SimulatorHardwareProfile, StationCommissioningPayload } from '../../types/station'
import { useAuth } from '../auth/useAuth'
import { LocationPickerMap } from '../maps/LocationPickerMap'
import { formatCoordinates } from '../maps/mapUtils'
import { getSimulatorHardwareProfiles } from './stationApi'

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

const steps = [
  { title: 'Station', icon: <Building2 size={16} /> },
  { title: 'Simulator profile', icon: <RadioTower size={16} /> },
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
  const selectedProfileKey = Form.useWatch('simulator_profile', form)
  const values = Form.useWatch([], form)

  const organizationsQuery = useQuery({
    queryKey: ['platform-organizations', 'station-commissioning-options'],
    queryFn: async () => (
      await httpClient.get<{ data: OrganizationOption[] }>('/organizations', { params: { status: 'active' } })
    ).data.data,
    enabled: open && isSuperAdmin,
  })
  const profilesQuery = useQuery({
    queryKey: ['simulator-hardware-profiles'],
    queryFn: getSimulatorHardwareProfiles,
    enabled: open,
    staleTime: 5 * 60 * 1000,
  })
  const selectedProfile = profilesQuery.data?.find((profile) => profile.key === selectedProfileKey)

  useEffect(() => {
    if (!open) return
    setCurrentStep(0)
    setManualCoordinates(false)
    form.resetFields()
    form.setFieldsValue({
      latitude: initialCoordinates?.latitude ?? 36.8065,
      longitude: initialCoordinates?.longitude ?? 10.1815,
      commissioning_target: 'simulator',
    })
  }, [form, initialCoordinates, open])

  useEffect(() => {
    const firstProfile = profilesQuery.data?.[0]
    if (open && firstProfile && !form.getFieldValue('simulator_profile')) {
      form.setFieldValue('simulator_profile', firstProfile.key)
    }
  }, [form, open, profilesQuery.data])

  async function next() {
    const fields: Array<keyof StationCommissioningPayload> = currentStep === 0
      ? ['name', 'reference', 'location_name', 'city', 'address', 'latitude', 'longitude', ...(isSuperAdmin ? ['organization_id' as const] : [])]
      : ['simulator_profile']
    await form.validateFields(fields)
    setCurrentStep((step) => Math.min(step + 1, steps.length - 1))
  }

  function close() {
    form.resetFields()
    onClose()
  }

  return (
    <Drawer
      className="station-commissioning-drawer"
      width="min(980px, calc(100vw - 80px))"
      open={open}
      onClose={close}
      destroyOnHidden
      title={<div className="commissioning-drawer-title"><span>Commission a simulated station</span><small>Create the operational asset and its OCPP simulator instance in one workflow.</small></div>}
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
        onFinish={(formValues) => onSubmit({
          ...formValues,
          commissioning_target: 'simulator',
          ocpp_identity: formValues.reference,
        })}
      >
        <div hidden={currentStep !== 0}>
          <StepHeading eyebrow="01 / Station identity" title="Place the station on the network" description="Use a unique operational reference and set the simulated asset's exact public location." />
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
            <Form.Item name="reference" label="Unique OCPP reference" rules={[{ required: true, max: 80, pattern: /^[A-Za-z0-9._:-]+$/, message: 'Use letters, numbers, dots, colons, underscores or hyphens.' }]}>
              <Input placeholder="CT-TUN-101" />
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
          <StepHeading eyebrow="02 / Simulator profile" title="Choose the simulated charger" description="Each verified profile defines the model, station capacity and OCPP connector layout. These values stay consistent across the database and simulator." />
          {profilesQuery.isLoading && <Skeleton active paragraph={{ rows: 5 }} />}
          {profilesQuery.isError && <Alert type="error" showIcon title="Simulator profiles unavailable" description="The simulator service is temporarily unavailable. Retry in a moment." action={<Button onClick={() => void profilesQuery.refetch()}>Retry</Button>} />}
          {!profilesQuery.isLoading && !profilesQuery.isError && (
            <Form.Item name="simulator_profile" rules={[{ required: true, message: 'Select a simulator profile.' }]}>
              <Radio.Group className="simulator-profile-grid">
                {(profilesQuery.data ?? []).map((profile) => <SimulatorProfileOption key={profile.key} profile={profile} />)}
              </Radio.Group>
            </Form.Item>
          )}
        </div>

        <div hidden={currentStep !== 2}>
          <StepHeading eyebrow="03 / Review" title="Confirm the commissioning record" description="Creation, connector setup and simulator provisioning will begin automatically after confirmation." />
          <section className="commissioning-review">
            <ReviewGroup title="Station">
              <ReviewValue label="Name" value={values?.name} />
              <ReviewValue label="OCPP identity" value={values?.reference} />
              <ReviewValue label="Location" value={[values?.location_name, values?.city].filter(Boolean).join(', ')} />
              <ReviewValue label="Position" value={formatCoordinates(Number(values?.latitude), Number(values?.longitude))} />
            </ReviewGroup>
            <ReviewGroup title="Simulator hardware">
              <ReviewValue label="Profile" value={selectedProfile?.label} />
              <ReviewValue label="Charger" value={[selectedProfile?.manufacturer, selectedProfile?.model].filter(Boolean).join(' ')} />
              <ReviewValue label="Maximum power" value={selectedProfile ? `${selectedProfile.max_power_kw} kW` : '-'} />
              <ReviewValue label="Connectors" value={selectedProfile?.connectors.map((connector) => `${connector.external_id} ${connector.type}`).join(' · ')} />
            </ReviewGroup>
          </section>
          <Alert type="success" showIcon title="Ready for automatic provisioning" description="The worker will add and start this station in the OCPP simulator. No terminal command or service restart is required." />
        </div>
      </Form>
    </Drawer>
  )
}

function SimulatorProfileOption({ profile }: { profile: SimulatorHardwareProfile }) {
  return (
    <Radio.Button value={profile.key} className="simulator-profile-option">
      <article>
        <img src={profile.model_image ?? '/assets/stations/models/evbox-troniq.webp'} alt="" />
        <div className="simulator-profile-copy">
          <header><span>{profile.label}</span><strong>{profile.max_power_kw} kW</strong></header>
          <h3>{profile.manufacturer} {profile.model}</h3>
          <p>{profile.description}</p>
          <div>{profile.connectors.map((connector) => <span key={connector.ocpp_connector_id}><Zap size={13} />{connector.external_id} · {connector.type} · {connector.max_power_kw} kW {connector.current_type}</span>)}</div>
        </div>
      </article>
    </Radio.Button>
  )
}

function StepHeading({ eyebrow, title, description }: { eyebrow: string; title: string; description: string }) {
  return <header className="commissioning-step-heading"><span>{eyebrow}</span><h2>{title}</h2><p>{description}</p></header>
}

function ReviewGroup({ title, children }: { title: string; children: ReactNode }) {
  return <article><header>{title}</header><div>{children}</div></article>
}

function ReviewValue({ label, value }: { label: string; value?: string | number | null }) {
  return <span><small>{label}</small><strong>{value || '-'}</strong></span>
}
