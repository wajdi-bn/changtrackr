import { useDeferredValue, useEffect, useMemo, useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Alert, App, Button, Card, Checkbox, DatePicker, Drawer, Empty, Form, Input, InputNumber, Popconfirm, Segmented, Select, Skeleton, Steps, Tag, Upload } from 'antd'
import { isAxiosError } from 'axios'
import dayjs from 'dayjs'
import {
  CheckCircle2,
  CalendarDays,
  Camera,
  ClipboardCheck,
  Clock3,
  Eye,
  FileCheck2,
  FileDown,
  Grid2X2,
  ImagePlus,
  List,
  MessageSquarePlus,
  PackageOpen,
  Pause,
  Play,
  Search,
  Send,
  ShieldCheck,
  Trash2,
  UserRoundCog,
  XCircle,
} from 'lucide-react'
import { MountainBanner } from '../components/MountainBanner'
import {
  addInterventionNote,
  deleteInterventionPhoto,
  getInterventions,
  submitInterventionReport,
  updateIntervention,
  uploadInterventionPhoto,
  viewInterventionPhoto,
} from '../features/operations/operationsApi'
import { WorkflowTag } from '../features/operations/WorkflowTag'
import { useAuth } from '../features/auth/useAuth'
import { downloadOperationalDocument } from '../features/reports/reportingApi'
import type { InterventionItem, InterventionOutcome, InterventionReportPayload, InterventionStatus } from '../types/operations'
import { downloadBlob } from '../utils/downloadBlob'

export function InterventionsPage() {
  const { primaryRole, user } = useAuth()
  const technicianMode = primaryRole === 'technician'
  const organizationMode = primaryRole === 'admin'
  const canManage = user?.permissions.includes('interventions.manage') ?? false
  const canReport = user?.permissions.includes('interventions.report') ?? false
  const [search, setSearch] = useState('')
  const deferredSearch = useDeferredValue(search)
  const [status, setStatus] = useState<'all' | InterventionStatus>('all')
  const [view, setView] = useState<'cards' | 'list'>('cards')
  const [selectedId, setSelectedId] = useState<number | null>(null)
  const [noteOpen, setNoteOpen] = useState(false)
  const [managementOpen, setManagementOpen] = useState(false)
  const [reportOpen, setReportOpen] = useState(false)
  const queryClient = useQueryClient()
  const { message } = App.useApp()

  const filters = useMemo(() => ({
    search: deferredSearch.trim() || undefined,
    status: status === 'all' ? undefined : status,
  }), [deferredSearch, status])
  const interventionsQuery = useQuery({ queryKey: ['interventions', filters], queryFn: () => getInterventions(filters) })
  const interventions = useMemo(() => interventionsQuery.data?.data ?? [], [interventionsQuery.data?.data])
  const selected = interventions.find((intervention) => intervention.id === selectedId) ?? interventions[0] ?? null

  useEffect(() => {
    if (selectedId === null && interventions[0]) setSelectedId(interventions[0].id)
    if (selectedId !== null && interventions.length > 0 && !interventions.some((item) => item.id === selectedId)) setSelectedId(interventions[0].id)
  }, [interventions, selectedId])

  const updateMutation = useMutation({
    mutationFn: ({ id, payload }: { id: number; payload: Parameters<typeof updateIntervention>[1] }) => updateIntervention(id, payload),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['interventions'] })
      await queryClient.invalidateQueries({ queryKey: ['alerts'] })
      void message.success('Intervention updated.')
    },
    onError: (error) => void message.error(apiErrorMessage(error, 'The intervention could not be updated.')),
  })

  const noteMutation = useMutation({
    mutationFn: ({ id, description }: { id: number; description: string }) => addInterventionNote(id, description),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['interventions'] })
      setNoteOpen(false)
      void message.success('Diagnostic note added.')
    },
    onError: () => void message.error('The note could not be added.'),
  })

  const evidenceMutation = useMutation({
    mutationFn: ({ id, photo, phase }: { id: number; photo: File; phase: 'before' | 'after' | 'evidence' }) => uploadInterventionPhoto(id, { photo, phase }),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['interventions'] })
      void message.success('Evidence photo added.')
    },
    onError: () => void message.error('The photo could not be uploaded. Use JPEG, PNG, or WebP under 5 MB.'),
  })

  const deleteEvidenceMutation = useMutation({
    mutationFn: ({ id, photoId }: { id: number; photoId: number }) => deleteInterventionPhoto(id, photoId),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['interventions'] })
      void message.success('Evidence photo removed.')
    },
    onError: () => void message.error('The photo could not be removed.'),
  })

  const reportMutation = useMutation({
    mutationFn: ({ id, payload }: { id: number; payload: InterventionReportPayload }) => submitInterventionReport(id, payload),
    onSuccess: async () => {
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: ['interventions'] }),
        queryClient.invalidateQueries({ queryKey: ['maintenances'] }),
        queryClient.invalidateQueries({ queryKey: ['alerts'] }),
        queryClient.invalidateQueries({ queryKey: ['stations'] }),
      ])
      setReportOpen(false)
      void message.success('Final report submitted. The intervention is now read-only.')
    },
    onError: (error) => void message.error(apiErrorMessage(error, 'The report could not be submitted. Check every step and the required photos.')),
  })
  const documentMutation = useMutation({
    mutationFn: (intervention: InterventionItem) => downloadOperationalDocument('intervention', intervention.id),
    onSuccess: (blob, intervention) => downloadBlob(blob, `intervention-${intervention.reference}.pdf`),
    onError: () => void message.error('The intervention document could not be generated.'),
  })

  return <div className="interventions-page">
    <MountainBanner
      color="orange"
      breadcrumb={[organizationMode ? 'Organization' : technicianMode ? 'Technician' : 'Operations', technicianMode ? 'My interventions' : 'Interventions']}
      title={technicianMode ? 'My interventions' : 'Organization interventions'}
      count={interventionsQuery.data?.summary.total ?? 0}
      subtitle={technicianMode
        ? 'Track assigned maintenance work, diagnostic notes, parts, timeline, and final resolution status.'
        : 'Plan assignments, review maintenance progress, and keep intervention decisions traceable.'}
    />

    <div className="interventions-toolbar">
      <Input value={search} onChange={(event) => setSearch(event.target.value)} prefix={<Search size={14} />} placeholder="Search interventions" allowClear />
      <Select value={status} onChange={(value) => setStatus(value)} options={['all', 'assigned', 'in-progress', 'paused', 'waiting-parts', 'resolved', 'cancelled'].map((value) => ({ value, label: value === 'all' ? 'All statuses' : value.replace('-', ' ') }))} />
      <Segmented value={view} onChange={(value) => setView(value as 'cards' | 'list')} options={[{ value: 'cards', icon: <Grid2X2 size={15} /> }, { value: 'list', icon: <List size={15} /> }]} />
    </div>

    <div className="interventions-split">
      <Card title={technicianMode ? 'Assigned interventions' : 'Organization interventions'} extra={<small>{interventions.length} matching interventions</small>}>
        {interventionsQuery.isLoading ? <Skeleton active paragraph={{ rows: 8 }} /> : interventions.length === 0 ? <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} /> : (
          <div className={view === 'cards' ? 'intervention-cards' : 'intervention-list'}>
            {interventions.map((intervention) => <button key={intervention.id} type="button" className={selected?.id === intervention.id ? 'selected' : ''} onClick={() => setSelectedId(intervention.id)}><InterventionSummary intervention={intervention} /></button>)}
          </div>
        )}
      </Card>
      <InterventionDetails
        intervention={selected}
        technicianMode={technicianMode}
        canManage={canManage}
        canReport={canReport}
        updating={updateMutation.isPending}
        downloading={documentMutation.isPending}
        onStatus={(nextStatus, extra = {}) => selected && updateMutation.mutate({ id: selected.id, payload: { status: nextStatus, ...extra } })}
        onAddNote={() => setNoteOpen(true)}
        onManage={() => setManagementOpen(true)}
        onCompleteReport={() => setReportOpen(true)}
        onViewPhoto={async (photoId) => {
          try {
            await viewInterventionPhoto(selected!.id, photoId)
          } catch {
            void message.error('The private photo could not be opened.')
          }
        }}
        onDownload={() => selected && documentMutation.mutate(selected)}
      />
    </div>

    <NoteDrawer open={noteOpen} intervention={selected} submitting={noteMutation.isPending} onClose={() => setNoteOpen(false)} onSubmit={(description) => selected && noteMutation.mutate({ id: selected.id, description })} />
    <ManagementDrawer
      open={managementOpen}
      intervention={selected}
      technicians={interventionsQuery.data?.technicians ?? []}
      submitting={updateMutation.isPending}
      onClose={() => setManagementOpen(false)}
      onSubmit={(payload) => selected && updateMutation.mutate(
        { id: selected.id, payload },
        { onSuccess: () => setManagementOpen(false) },
      )}
    />
    <ReportDrawer
      open={reportOpen}
      intervention={selected}
      submitting={reportMutation.isPending}
      uploading={evidenceMutation.isPending}
      deleting={deleteEvidenceMutation.isPending}
      onClose={() => setReportOpen(false)}
      onUpload={(photo, phase) => selected ? evidenceMutation.mutateAsync({ id: selected.id, photo, phase }) : Promise.reject(new Error('No intervention selected'))}
      onDelete={(photoId) => selected && deleteEvidenceMutation.mutate({ id: selected.id, photoId })}
      onView={async (photoId) => {
        if (!selected) return
        try {
          await viewInterventionPhoto(selected.id, photoId)
        } catch {
          void message.error('The private photo could not be opened.')
        }
      }}
      onSubmit={(payload) => selected && reportMutation.mutate({ id: selected.id, payload })}
    />
  </div>
}

function InterventionSummary({ intervention }: { intervention: InterventionItem }) {
  return <div className="intervention-summary">
    <div><span><strong>{intervention.reference}</strong><small>{intervention.station.name} - {intervention.connector ? `${intervention.connector.external_id} / ${intervention.connector.type}` : 'All connectors'}</small></span><WorkflowTag value={intervention.status} /></div>
    <p>{intervention.problem}</p>
    <footer><WorkflowTag value={intervention.priority} /><span><Clock3 size={12} />{intervention.scheduled_at ? dayjs(intervention.scheduled_at).format('MMM D, HH:mm') : 'Not scheduled'}</span><span>{intervention.estimated_duration_minutes ?? 0} min</span></footer>
  </div>
}

function InterventionDetails({ intervention, technicianMode, canManage, canReport, updating, downloading, onStatus, onAddNote, onManage, onCompleteReport, onViewPhoto, onDownload }: {
  intervention: InterventionItem | null
  technicianMode: boolean
  canManage: boolean
  canReport: boolean
  updating: boolean
  downloading: boolean
  onStatus: (status: InterventionStatus, extra?: Parameters<typeof updateIntervention>[1]) => void
  onAddNote: () => void
  onManage: () => void
  onCompleteReport: () => void
  onViewPhoto: (photoId: number) => void
  onDownload: () => void
}) {
  if (!intervention) return <Card title="Intervention detail"><Empty description="Select an intervention" /></Card>
  const maintenanceStartBlockedReason = intervention.maintenance_plan_id === null
    ? null
    : intervention.station.maintenance_intervention_id !== null && intervention.station.maintenance_intervention_id !== intervention.id
      ? 'Another maintenance intervention is already active for this station. Complete or cancel it before starting this work order.'
      : intervention.station.availability_override === 'maintenance'
        ? 'This station is already in manually controlled maintenance mode. Clear the manual maintenance mode before starting this work order.'
        : null

  return <Card className="intervention-detail-card" title="Intervention detail" extra={<WorkflowTag value={intervention.status} />}>
    <header><h2>{intervention.problem}</h2><p>{intervention.reference} - {intervention.station.name}</p></header>
    <div className="intervention-facts">
      <Fact label="Priority" value={intervention.priority} />
      <Fact label="Scheduled" value={intervention.scheduled_at ? dayjs(intervention.scheduled_at).format('MMM D, HH:mm') : 'Not scheduled'} />
      <Fact label="Started" value={intervention.started_at ? dayjs(intervention.started_at).format('MMM D, HH:mm') : 'Not started'} />
      <Fact label="Duration" value={`${intervention.estimated_duration_minutes ?? 0} min`} />
    </div>
    <section className="field-suggestion"><strong>Field checklist</strong><p>Compare the current OCPP error with the last successful session, inspect connector pins before replacing hardware, and record the result before resolving.</p></section>
    {maintenanceStartBlockedReason && <Alert className="intervention-maintenance-block" type="warning" showIcon title="Maintenance start blocked" description={maintenanceStartBlockedReason} />}
    <section className="intervention-section"><h3>Parts</h3><div className="parts-list">{intervention.parts.length ? intervention.parts.map((part) => <span key={part}>{part}</span>) : <small>No parts specified</small>}</div></section>
    <section className="workflow-timeline"><h3>Timeline and notes</h3>{intervention.events.map((event) => <div key={event.id}><span><CheckCircle2 size={13} /></span><p>{event.description}<small>{event.occurred_relative}</small></p></div>)}</section>
    <ReportSummary intervention={intervention} onViewPhoto={onViewPhoto} />
    <div className="intervention-actions">
      <Button icon={<FileDown size={14} />} loading={downloading} onClick={onDownload}>Download report</Button>
      {canManage && !isTerminal(intervention.status) && <Button icon={<UserRoundCog size={14} />} onClick={onManage}>Assignment & schedule</Button>}
      {technicianMode && intervention.status === 'assigned' && <Button type="primary" icon={<Play size={14} />} loading={updating} disabled={Boolean(maintenanceStartBlockedReason)} title={maintenanceStartBlockedReason ?? undefined} onClick={() => onStatus('in-progress')}>Start</Button>}
      {technicianMode && intervention.status === 'in-progress' && <Button icon={<Pause size={14} />} loading={updating} onClick={() => onStatus('paused')}>Pause</Button>}
      {technicianMode && (intervention.status === 'paused' || intervention.status === 'waiting-parts') && <Button type="primary" icon={<Play size={14} />} loading={updating} onClick={() => onStatus('in-progress')}>Resume</Button>}
      {technicianMode && intervention.status === 'in-progress' && <Button icon={<PackageOpen size={14} />} loading={updating} onClick={() => onStatus('waiting-parts')}>Waiting parts</Button>}
      {canReport && !isTerminal(intervention.status) && <Button icon={<MessageSquarePlus size={14} />} onClick={onAddNote}>Add note</Button>}
      {technicianMode && canReport && intervention.status !== 'assigned' && !isTerminal(intervention.status) && <Button className="resolve-button" icon={<ClipboardCheck size={14} />} onClick={onCompleteReport}>Complete report</Button>}
      {canManage && !isTerminal(intervention.status) && <Popconfirm title="Cancel this intervention?" description="The linked alert will return to the assignment queue." okText="Cancel intervention" okButtonProps={{ danger: true }} onConfirm={() => onStatus('cancelled', { comments: 'Cancelled by organization management.' })}><Button danger icon={<XCircle size={14} />} loading={updating}>Cancel</Button></Popconfirm>}
      {intervention.status === 'resolved' && <Button icon={<Send size={14} />} disabled>Report submitted</Button>}
    </div>
  </Card>
}

function ReportSummary({ intervention, onViewPhoto }: { intervention: InterventionItem; onViewPhoto: (photoId: number) => void }) {
  const report = intervention.report
  if (!report) {
    return <section className="intervention-final empty-report">
      <span><FileCheck2 size={18} /></span>
      <div><small>Final report</small><strong>{intervention.status === 'resolved' ? 'Legacy report unavailable' : 'Not submitted'}</strong><p>The assigned technician must complete the guided report before this intervention can be resolved.</p></div>
    </section>
  }

  return <section className="intervention-report-summary">
    <header><span><FileCheck2 size={17} /></span><div><small>Final report</small><strong>{report.submitted_by?.name ?? 'Field technician'} - {dayjs(report.submitted_at).format('MMM D, YYYY HH:mm')}</strong></div><OutcomeTag outcome={report.final_outcome} /></header>
    <div className="report-copy-grid">
      <article><small>Diagnosis</small><p>{report.diagnosis}</p></article>
      <article><small>Actions performed</small><p>{report.actions_taken}</p></article>
    </div>
    {report.observations && <article className="report-observations"><small>Observations and follow-up</small><p>{report.observations}</p></article>}
    <div className="report-checks">
      <span><ShieldCheck size={14} /> Work area safe</span>
      <span><CheckCircle2 size={14} /> Connector inspected</span>
      <span><CheckCircle2 size={14} /> Station status verified</span>
      <span><Clock3 size={14} /> {report.actual_duration_minutes} min actual</span>
    </div>
    <EvidenceGallery photos={intervention.photos} readOnly onView={onViewPhoto} />
  </section>
}

function OutcomeTag({ outcome }: { outcome: InterventionOutcome }) {
  if (outcome === 'operational') return <Tag color="success">Operational</Tag>
  if (outcome === 'operational-monitoring') return <Tag color="processing">Monitor</Tag>
  return <Tag color="warning">Follow-up required</Tag>
}

function isTerminal(status: InterventionStatus) {
  return status === 'resolved' || status === 'cancelled'
}

function Fact({ label, value }: { label: string; value: string }) {
  return <div><small>{label}</small><strong>{value}</strong></div>
}

function NoteDrawer({ open, intervention, submitting, onClose, onSubmit }: {
  open: boolean
  intervention: InterventionItem | null
  submitting: boolean
  onClose: () => void
  onSubmit: (description: string) => void
}) {
  const [form] = Form.useForm<{ description: string }>()
  return <Drawer title="Add diagnostic note" open={open} onClose={onClose} size={440} extra={<Button type="primary" loading={submitting} onClick={() => form.submit()}>Save note</Button>}>
    <p className="drawer-context">{intervention?.reference} - {intervention?.station.name}</p>
    <Form form={form} layout="vertical" onFinish={(values) => onSubmit(values.description)}>
      <Form.Item label="Diagnostic note" name="description" rules={[{ required: true, min: 5 }]}><Input.TextArea rows={7} placeholder="Describe the diagnosis, work completed, measurements, or next action." /></Form.Item>
      <Button type="primary" htmlType="submit" loading={submitting} block>Save note</Button>
    </Form>
  </Drawer>
}

function ReportDrawer({ open, intervention, submitting, uploading, deleting, onClose, onUpload, onDelete, onView, onSubmit }: {
  open: boolean
  intervention: InterventionItem | null
  submitting: boolean
  uploading: boolean
  deleting: boolean
  onClose: () => void
  onUpload: (photo: File, phase: 'before' | 'after') => Promise<unknown>
  onDelete: (photoId: number) => void
  onView: (photoId: number) => void
  onSubmit: (payload: InterventionReportPayload) => void
}) {
  const [form] = Form.useForm<InterventionReportPayload>()
  const [current, setCurrent] = useState(0)
  const drawerContentRef = useRef<HTMLDivElement>(null)
  const initializedInterventionRef = useRef<number | null>(null)
  const { message } = App.useApp()
  const finalOutcome = Form.useWatch('final_outcome', form)
  const photos = intervention?.photos ?? []

  useEffect(() => {
    if (!open) {
      initializedInterventionRef.current = null
      return
    }
    if (!intervention || initializedInterventionRef.current === intervention.id) return
    initializedInterventionRef.current = intervention.id
    setCurrent(0)
    form.resetFields()
    form.setFieldsValue({
      diagnosis: intervention?.diagnosis ?? '',
      actions_taken: intervention?.resolution ?? '',
      observations: intervention?.comments ?? '',
      parts: intervention?.parts ?? [],
      final_outcome: 'operational',
      safety_checks: {
        work_area_safe: false,
        connector_inspected: false,
        station_status_verified: false,
      },
    })
  }, [form, intervention, open])

  useEffect(() => {
    drawerContentRef.current?.closest('.ant-drawer-body')?.scrollTo({ top: 0, behavior: 'auto' })
  }, [current])

  async function next() {
    if (current === 0) await form.validateFields(['diagnosis'])
    if (current === 1) await form.validateFields(['actions_taken', 'parts'])
    if (current === 2) {
      const hasBefore = photos.some((photo) => photo.phase === 'before')
      const hasAfter = photos.some((photo) => photo.phase === 'after')
      if (!hasBefore || !hasAfter) {
        void message.warning('Add at least one before photo and one after photo to continue.')
        return
      }
    }
    setCurrent((step) => Math.min(step + 1, 3))
  }

  function submitFinalReport() {
    const values = form.getFieldsValue(true) as Partial<InterventionReportPayload>
    const diagnosis = values.diagnosis?.trim() ?? ''
    if (diagnosis.length < 10) {
      form.setFields([{ name: 'diagnosis', errors: ['Enter at least 10 characters for the diagnosis.'] }])
      setCurrent(0)
      void message.error('Complete the diagnosis before submitting the report.')
      return
    }

    const actionsTaken = values.actions_taken?.trim() ?? ''
    if (actionsTaken.length < 10) {
      form.setFields([{ name: 'actions_taken', errors: ['Enter at least 10 characters for the work performed.'] }])
      setCurrent(1)
      void message.error('Complete the work performed before submitting the report.')
      return
    }

    const hasBefore = photos.some((photo) => photo.phase === 'before')
    const hasAfter = photos.some((photo) => photo.phase === 'after')
    if (!hasBefore || !hasAfter) {
      setCurrent(2)
      void message.error('Add at least one before photo and one after photo before submitting the report.')
      return
    }

    onSubmit(normalizeReportPayload(values))
  }

  return <Drawer
    className="intervention-report-drawer"
    title="Complete intervention report"
    open={open}
    onClose={onClose}
    size={620}
    maskClosable={!submitting && !uploading}
  >
    <div ref={drawerContentRef} className="report-drawer-context">
      <span><ClipboardCheck size={18} /></span>
      <div><strong>{intervention?.reference} - {intervention?.station.name}</strong><small>The submitted report and its evidence become read-only.</small></div>
    </div>
    <Steps current={current} size="small" responsive={false} items={[
      { title: 'Diagnosis' },
      { title: 'Work' },
      { title: 'Evidence' },
      { title: 'Review' },
    ]} />
    <Form form={form} layout="vertical" requiredMark="optional" className="intervention-report-form" onFinish={submitFinalReport}>
      {current === 0 && <div className="report-step">
        <div className="report-step-heading"><span><ClipboardCheck size={18} /></span><div><strong>Document the diagnosis</strong><small>Record what was observed and the confirmed root cause.</small></div></div>
        <Form.Item label="Diagnosis" name="diagnosis" rules={[{ required: true, min: 10, max: 5000 }]}>
          <Input.TextArea rows={9} placeholder="Describe symptoms, measurements, OCPP errors, and the confirmed cause." showCount maxLength={5000} />
        </Form.Item>
      </div>}
      {current === 1 && <div className="report-step">
        <div className="report-step-heading"><span><FileCheck2 size={18} /></span><div><strong>Record the work performed</strong><small>Keep the actions precise enough for a future technician to reproduce.</small></div></div>
        <Form.Item label="Actions performed" name="actions_taken" rules={[{ required: true, min: 10, max: 5000 }]}>
          <Input.TextArea rows={8} placeholder="List inspections, repairs, configuration changes, and final tests." showCount maxLength={5000} />
        </Form.Item>
        <Form.Item label="Parts used" name="parts" initialValue={[]}><Select mode="tags" tokenSeparators={[',']} placeholder="Type a part name and press Enter" maxCount={30} /></Form.Item>
      </div>}
      {current === 2 && <div className="report-step">
        <div className="report-step-heading"><span><Camera size={18} /></span><div><strong>Add before and after evidence</strong><small>Images are private and available only to authorized organization users.</small></div></div>
        <div className="evidence-upload-grid">
          <EvidenceUpload phase="before" uploading={uploading} onUpload={onUpload} />
          <EvidenceUpload phase="after" uploading={uploading} onUpload={onUpload} />
        </div>
        <EvidenceGallery photos={photos} deleting={deleting} onDelete={onDelete} onView={onView} />
      </div>}
      {current === 3 && <div className="report-step">
        <div className="report-step-heading"><span><ShieldCheck size={18} /></span><div><strong>Verify and submit</strong><small>Confirm the physical checks and select the final field outcome.</small></div></div>
        <Form.Item label="Final outcome" name="final_outcome" rules={[{ required: true }]}>
          <Select options={[
            { value: 'operational', label: 'Operational' },
            { value: 'operational-monitoring', label: 'Operational - monitoring required' },
            { value: 'follow-up-required', label: 'Follow-up required' },
          ]} />
        </Form.Item>
        <div className="report-verification-list">
          <Form.Item name={['safety_checks', 'work_area_safe']} valuePropName="checked" rules={[{ validator: (_, value) => value ? Promise.resolve() : Promise.reject(new Error('Confirm the work area is safe.')) }]}><Checkbox>The work area is safe and secured.</Checkbox></Form.Item>
          <Form.Item name={['safety_checks', 'connector_inspected']} valuePropName="checked" rules={[{ validator: (_, value) => value ? Promise.resolve() : Promise.reject(new Error('Confirm the connector inspection.')) }]}><Checkbox>The connector and cable were inspected.</Checkbox></Form.Item>
          <Form.Item name={['safety_checks', 'station_status_verified']} valuePropName="checked" rules={[{ validator: (_, value) => value ? Promise.resolve() : Promise.reject(new Error('Confirm the final station status.')) }]}><Checkbox>The final station status was physically verified.</Checkbox></Form.Item>
        </div>
        <Form.Item label={finalOutcome === 'follow-up-required' ? 'Required follow-up' : 'Final observations'} name="observations" rules={[{ required: finalOutcome === 'follow-up-required', min: finalOutcome === 'follow-up-required' ? 10 : undefined, max: 5000 }]}>
          <Input.TextArea rows={5} placeholder={finalOutcome === 'follow-up-required' ? 'Explain what remains, the required part, and the recommended next action.' : 'Add readings, monitoring advice, or final observations.'} />
        </Form.Item>
        <div className="report-submit-note"><ShieldCheck size={17} /><span><strong>Immutable submission</strong><small>Submitting completes the intervention. Further edits and photo deletion will be blocked.</small></span></div>
      </div>}
    </Form>
    <div className="report-drawer-footer">
      <Button onClick={current === 0 ? onClose : () => setCurrent((step) => step - 1)} disabled={submitting || uploading}>{current === 0 ? 'Cancel' : 'Back'}</Button>
      {current < 3
        ? <Button type="primary" onClick={() => void next()} loading={uploading}>Continue</Button>
        : <Button className="resolve-button" icon={<Send size={14} />} loading={submitting} onClick={() => form.submit()}>Submit final report</Button>}
    </div>
  </Drawer>
}

function EvidenceUpload({ phase, uploading, onUpload }: {
  phase: 'before' | 'after'
  uploading: boolean
  onUpload: (photo: File, phase: 'before' | 'after') => Promise<unknown>
}) {
  const { message } = App.useApp()
  return <Upload.Dragger
    accept="image/jpeg,image/png,image/webp"
    multiple={false}
    showUploadList={false}
    disabled={uploading}
    beforeUpload={(file) => {
      if (file.size <= 5 * 1024 * 1024) return true
      void message.error('The photo must be smaller than 5 MB.')
      return Upload.LIST_IGNORE
    }}
    customRequest={({ file, onSuccess, onError }) => {
      void onUpload(file as File, phase).then(() => onSuccess?.({})).catch((error: Error) => onError?.(error))
    }}
  >
    <ImagePlus size={22} />
    <strong>{phase === 'before' ? 'Before intervention' : 'After intervention'}</strong>
    <small>Click or drop one JPEG, PNG, or WebP</small>
  </Upload.Dragger>
}

function EvidenceGallery({ photos, readOnly = false, deleting = false, onDelete, onView }: {
  photos: InterventionItem['photos']
  readOnly?: boolean
  deleting?: boolean
  onDelete?: (photoId: number) => void
  onView: (photoId: number) => void
}) {
  if (photos.length === 0) return <div className="evidence-empty"><Camera size={17} /><span>No evidence photos yet.</span></div>
  return <div className="evidence-gallery">
    {photos.map((photo) => <article key={photo.id}>
      <span className={`evidence-phase ${photo.phase}`}><Camera size={15} /></span>
      <div><strong>{photo.original_name}</strong><small>{photo.phase} - {formatBytes(photo.size_bytes)}</small></div>
      <Button type="text" aria-label="View private photo" icon={<Eye size={15} />} onClick={() => onView(photo.id)} />
      {!readOnly && onDelete && <Popconfirm title="Remove this evidence photo?" onConfirm={() => onDelete(photo.id)}><Button type="text" danger loading={deleting} aria-label="Delete photo" icon={<Trash2 size={15} />} /></Popconfirm>}
    </article>)}
  </div>
}

function formatBytes(size: number) {
  if (size < 1024 * 1024) return `${Math.max(1, Math.round(size / 1024))} KB`
  return `${(size / (1024 * 1024)).toFixed(1)} MB`
}

function normalizeReportPayload(payload: Partial<InterventionReportPayload>): InterventionReportPayload {
  return {
    diagnosis: payload.diagnosis?.trim() ?? '',
    actions_taken: payload.actions_taken?.trim() ?? '',
    final_outcome: payload.final_outcome ?? 'operational',
    observations: payload.observations?.trim() || null,
    parts: (payload.parts ?? []).filter((part): part is string => typeof part === 'string').map((part) => part.trim()).filter(Boolean),
    safety_checks: {
      work_area_safe: Boolean(payload.safety_checks?.work_area_safe),
      connector_inspected: Boolean(payload.safety_checks?.connector_inspected),
      station_status_verified: Boolean(payload.safety_checks?.station_status_verified),
    },
  }
}

function apiErrorMessage(error: unknown, fallback: string): string {
  if (!isAxiosError<{ message?: string; errors?: Record<string, string[]> }>(error)) return fallback
  const errors = error.response?.data.errors
  const validationMessage = errors ? Object.values(errors).flat()[0] : undefined

  return validationMessage ?? error.response?.data.message ?? fallback
}

function ManagementDrawer({ open, intervention, technicians, submitting, onClose, onSubmit }: {
  open: boolean
  intervention: InterventionItem | null
  technicians: Array<{ id: number; name: string }>
  submitting: boolean
  onClose: () => void
  onSubmit: (payload: Parameters<typeof updateIntervention>[1]) => void
}) {
  const [form] = Form.useForm<{ assigned_technician_id: number; scheduled_at: dayjs.Dayjs | null; estimated_duration_minutes: number | null }>()

  useEffect(() => {
    if (!open || !intervention) return
    form.setFieldsValue({
      assigned_technician_id: intervention.assigned_technician?.id,
      scheduled_at: intervention.scheduled_at ? dayjs(intervention.scheduled_at) : null,
      estimated_duration_minutes: intervention.estimated_duration_minutes,
    })
  }, [form, intervention, open])

  return <Drawer title="Assignment and schedule" open={open} onClose={onClose} size={460} extra={<Button type="primary" loading={submitting} onClick={() => form.submit()}>Save changes</Button>}>
    <p className="drawer-context">{intervention?.reference} - {intervention?.station.name}</p>
    <Form form={form} layout="vertical" requiredMark="optional" onFinish={(values) => onSubmit({
      assigned_technician_id: values.assigned_technician_id,
      scheduled_at: values.scheduled_at?.toISOString() ?? null,
      estimated_duration_minutes: values.estimated_duration_minutes,
    })}>
      <Form.Item label="Assigned technician" name="assigned_technician_id" rules={[{ required: true }]}><Select prefix={<UserRoundCog size={14} />} options={technicians.map((technician) => ({ value: technician.id, label: technician.name }))} /></Form.Item>
      <Form.Item label="Scheduled date" name="scheduled_at"><DatePicker prefix={<CalendarDays size={14} />} showTime style={{ width: '100%' }} /></Form.Item>
      <Form.Item label="Estimated duration (minutes)" name="estimated_duration_minutes"><InputNumber min={5} max={1440} style={{ width: '100%' }} /></Form.Item>
      <Button type="primary" htmlType="submit" loading={submitting} block>Save changes</Button>
    </Form>
  </Drawer>
}
