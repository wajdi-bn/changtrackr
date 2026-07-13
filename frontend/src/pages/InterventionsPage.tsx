import { useDeferredValue, useEffect, useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { App, Button, Card, Drawer, Empty, Form, Input, Segmented, Select, Skeleton } from 'antd'
import dayjs from 'dayjs'
import {
  CheckCircle2,
  Clock3,
  Grid2X2,
  List,
  MessageSquarePlus,
  PackageOpen,
  Pause,
  Play,
  Search,
  Send,
} from 'lucide-react'
import { MountainBanner } from '../components/MountainBanner'
import { addInterventionNote, getInterventions, updateIntervention } from '../features/operations/operationsApi'
import { WorkflowTag } from '../features/operations/WorkflowTag'
import type { InterventionItem, InterventionStatus } from '../types/operations'

export function InterventionsPage() {
  const [search, setSearch] = useState('')
  const deferredSearch = useDeferredValue(search)
  const [status, setStatus] = useState<'all' | InterventionStatus>('all')
  const [view, setView] = useState<'cards' | 'list'>('cards')
  const [selectedId, setSelectedId] = useState<number | null>(null)
  const [noteOpen, setNoteOpen] = useState(false)
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
    onError: () => void message.error('The intervention could not be updated.'),
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

  return <div className="interventions-page">
    <MountainBanner
      color="orange"
      breadcrumb={['Technician', 'My interventions']}
      title="My interventions"
      count={interventionsQuery.data?.summary.total ?? 0}
      subtitle="Track assigned maintenance work, diagnostic notes, parts, timeline, and final resolution status."
    />

    <div className="interventions-toolbar">
      <Input value={search} onChange={(event) => setSearch(event.target.value)} prefix={<Search size={14} />} placeholder="Search interventions" allowClear />
      <Select value={status} onChange={(value) => setStatus(value)} options={['all', 'assigned', 'in-progress', 'paused', 'waiting-parts', 'resolved'].map((value) => ({ value, label: value === 'all' ? 'All statuses' : value.replace('-', ' ') }))} />
      <Segmented value={view} onChange={(value) => setView(value as 'cards' | 'list')} options={[{ value: 'cards', icon: <Grid2X2 size={15} /> }, { value: 'list', icon: <List size={15} /> }]} />
    </div>

    <div className="interventions-split">
      <Card title="Assigned interventions" extra={<small>{interventions.length} matching interventions</small>}>
        {interventionsQuery.isLoading ? <Skeleton active paragraph={{ rows: 8 }} /> : interventions.length === 0 ? <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} /> : (
          <div className={view === 'cards' ? 'intervention-cards' : 'intervention-list'}>
            {interventions.map((intervention) => <button key={intervention.id} type="button" className={selected?.id === intervention.id ? 'selected' : ''} onClick={() => setSelectedId(intervention.id)}><InterventionSummary intervention={intervention} /></button>)}
          </div>
        )}
      </Card>
      <InterventionDetails
        intervention={selected}
        updating={updateMutation.isPending}
        onStatus={(nextStatus, extra = {}) => selected && updateMutation.mutate({ id: selected.id, payload: { status: nextStatus, ...extra } })}
        onAddNote={() => setNoteOpen(true)}
      />
    </div>

    <NoteDrawer open={noteOpen} intervention={selected} submitting={noteMutation.isPending} onClose={() => setNoteOpen(false)} onSubmit={(description) => selected && noteMutation.mutate({ id: selected.id, description })} />
  </div>
}

function InterventionSummary({ intervention }: { intervention: InterventionItem }) {
  return <div className="intervention-summary">
    <div><span><strong>{intervention.reference}</strong><small>{intervention.station.name} - {intervention.connector ? `${intervention.connector.external_id} / ${intervention.connector.type}` : 'All connectors'}</small></span><WorkflowTag value={intervention.status} /></div>
    <p>{intervention.problem}</p>
    <footer><WorkflowTag value={intervention.priority} /><span><Clock3 size={12} />{intervention.scheduled_at ? dayjs(intervention.scheduled_at).format('MMM D, HH:mm') : 'Not scheduled'}</span><span>{intervention.estimated_duration_minutes ?? 0} min</span></footer>
  </div>
}

function InterventionDetails({ intervention, updating, onStatus, onAddNote }: {
  intervention: InterventionItem | null
  updating: boolean
  onStatus: (status: InterventionStatus, extra?: Parameters<typeof updateIntervention>[1]) => void
  onAddNote: () => void
}) {
  if (!intervention) return <Card title="Intervention detail"><Empty description="Select an intervention" /></Card>
  return <Card className="intervention-detail-card" title="Intervention detail" extra={<WorkflowTag value={intervention.status} />}>
    <header><h2>{intervention.problem}</h2><p>{intervention.reference} - {intervention.station.name}</p></header>
    <div className="intervention-facts">
      <Fact label="Priority" value={intervention.priority} />
      <Fact label="Scheduled" value={intervention.scheduled_at ? dayjs(intervention.scheduled_at).format('MMM D, HH:mm') : 'Not scheduled'} />
      <Fact label="Started" value={intervention.started_at ? dayjs(intervention.started_at).format('MMM D, HH:mm') : 'Not started'} />
      <Fact label="Duration" value={`${intervention.estimated_duration_minutes ?? 0} min`} />
    </div>
    <section className="field-suggestion"><strong>Field checklist</strong><p>Compare the current OCPP error with the last successful session, inspect connector pins before replacing hardware, and record the result before resolving.</p></section>
    <section className="intervention-section"><h3>Parts</h3><div className="parts-list">{intervention.parts.length ? intervention.parts.map((part) => <span key={part}>{part}</span>) : <small>No parts specified</small>}</div></section>
    <section className="workflow-timeline"><h3>Timeline and notes</h3>{intervention.events.map((event) => <div key={event.id}><span><CheckCircle2 size={13} /></span><p>{event.description}<small>{event.occurred_relative}</small></p></div>)}</section>
    <section className="intervention-final"><small>Final status</small><strong>{intervention.final_status ?? 'Not submitted'}</strong><p>{intervention.comments ?? 'No final comments yet.'}</p></section>
    <div className="intervention-actions">
      {intervention.status === 'assigned' && <Button type="primary" icon={<Play size={14} />} loading={updating} onClick={() => onStatus('in-progress')}>Start</Button>}
      {intervention.status === 'in-progress' && <Button icon={<Pause size={14} />} loading={updating} onClick={() => onStatus('paused')}>Pause</Button>}
      {(intervention.status === 'paused' || intervention.status === 'waiting-parts') && <Button type="primary" icon={<Play size={14} />} loading={updating} onClick={() => onStatus('in-progress')}>Resume</Button>}
      {intervention.status === 'in-progress' && <Button icon={<PackageOpen size={14} />} loading={updating} onClick={() => onStatus('waiting-parts')}>Waiting parts</Button>}
      {intervention.status !== 'resolved' && <Button icon={<MessageSquarePlus size={14} />} onClick={onAddNote}>Add note</Button>}
      {intervention.status !== 'resolved' && <Button className="resolve-button" icon={<CheckCircle2 size={14} />} loading={updating} onClick={() => onStatus('resolved', { resolution: 'Resolved by field technician.', final_status: 'Resolved' })}>Resolve</Button>}
      {intervention.status === 'resolved' && <Button icon={<Send size={14} />} disabled>Report submitted</Button>}
    </div>
  </Card>
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
