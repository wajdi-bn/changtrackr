import { useEffect, useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { App, Avatar, Button, DatePicker, Drawer, Empty, Form, Input, Popconfirm, Select, Spin, Tag, Tooltip, Upload } from 'antd'
import type { UploadFile } from 'antd'
import type { Dayjs } from 'dayjs'
import dayjs from 'dayjs'
import { Archive, Check, Download, Eye, FilePenLine, Inbox, Mail, MailOpen, Paperclip, Plus, Reply, Search, Send, Sparkles, Trash2, UploadCloud } from 'lucide-react'
import { deleteAssetDocument, getAssetDocumentContent, uploadAssetDocument } from '../../features/documents/documentApi'
import { getApiErrorMessage } from '../../api/apiErrors'
import { DocumentPreviewModal } from '../../features/documents/DocumentPreviewModal'
import {
  archiveInternalReport,
  createInternalReport,
  deleteInternalReport,
  downloadInternalReport,
  getInternalReportRecipients,
  getInternalReports,
  readInternalReport,
  sendInternalReport,
  updateInternalReport,
} from '../../features/reports/reportingApi'
import type { InternalReport, InternalReportCategory, InternalReportPayload, InternalReportPriority, ReportMailbox } from '../../types/reporting'
import type { AssetDocument } from '../../types/documents'
import { downloadBlob } from '../../utils/downloadBlob'
import { humanize } from './reportingUtils'
import { reportComposeProfiles, type ReportComposeTemplate, type ReportComposeVariant } from './reportComposeProfiles'

interface ComposeValues {
  recipient_id?: number
  title: string
  category: InternalReportCategory
  priority: InternalReportPriority
  summary?: string
  body: string
  period?: [Dayjs, Dayjs]
}

class ReportAttachmentUploadError extends Error {
  readonly draft: InternalReport
  readonly failedFileName: string
  readonly remainingFiles: UploadFile[]

  constructor(
    draft: InternalReport,
    failedFileName: string,
    remainingFiles: UploadFile[],
    cause: unknown,
  ) {
    super(getApiErrorMessage(cause, `The attachment ${failedFileName} could not be uploaded.`))
    this.draft = draft
    this.failedFileName = failedFileName
    this.remainingFiles = remainingFiles
  }
}

const mailboxOptions: Array<{ value: ReportMailbox; label: string; icon: typeof Inbox }> = [
  { value: 'inbox', label: 'Inbox', icon: Inbox },
  { value: 'sent', label: 'Sent', icon: Send },
  { value: 'drafts', label: 'Drafts', icon: FilePenLine },
  { value: 'archived', label: 'Archive', icon: Archive },
]

const categoryOptions: InternalReportCategory[] = ['operations', 'incident', 'intervention', 'maintenance', 'performance', 'handover']

export function InternalReportMailbox({ variant, title = 'Internal report exchange', subtitle = 'Send protected operational reports to employees in your organization.' }: { variant: ReportComposeVariant; title?: string; subtitle?: string }) {
  const [mailbox, setMailbox] = useState<ReportMailbox>('inbox')
  const [search, setSearch] = useState('')
  const [category, setCategory] = useState<string>()
  const [selectedId, setSelectedId] = useState<number>()
  const [composeOpen, setComposeOpen] = useState(false)
  const [editing, setEditing] = useState<InternalReport | null>(null)
  const [pendingFiles, setPendingFiles] = useState<UploadFile[]>([])
  const [selectedTemplate, setSelectedTemplate] = useState<string>()
  const [previewDocument, setPreviewDocument] = useState<AssetDocument | null>(null)
  const [form] = Form.useForm<ComposeValues>()
  const queryClient = useQueryClient()
  const { message } = App.useApp()
  const reportsQuery = useQuery({ queryKey: ['internal-reports', mailbox, search, category], queryFn: () => getInternalReports(mailbox, search.trim(), category) })
  const recipientsQuery = useQuery({ queryKey: ['internal-report-recipients'], queryFn: getInternalReportRecipients })
  const reports = useMemo(() => reportsQuery.data?.data ?? [], [reportsQuery.data?.data])
  const selected = reports.find((report) => report.id === selectedId) ?? reports[0]
  const refresh = async () => { await queryClient.invalidateQueries({ queryKey: ['internal-reports'] }) }

  useEffect(() => {
    if (reports.length === 0) setSelectedId(undefined)
    else if (!reports.some((report) => report.id === selectedId)) setSelectedId(reports[0]?.id)
  }, [reports, selectedId])

  const saveMutation = useMutation({
    mutationFn: async ({ values, sendNow }: { values: ComposeValues; sendNow: boolean }) => {
      const payload: InternalReportPayload = {
        recipient_id: values.recipient_id,
        title: values.title,
        category: values.category,
        priority: values.priority,
        summary: values.summary,
        body: values.body,
        period_start: values.period?.[0].format('YYYY-MM-DD'),
        period_end: values.period?.[1].format('YYYY-MM-DD'),
        send_now: sendNow && !editing,
      }
      const draft = editing
        ? await updateInternalReport(editing.id, payload)
        : await createInternalReport({ ...payload, send_now: false })
      for (const [index, pendingFile] of pendingFiles.entries()) {
        const file = pendingFile.originFileObj
        if (!file) continue
        try {
          const baseTitle = file.name.replace(/\.[^.]+$/, '').trim()
          await uploadAssetDocument('report', draft.id, {
            file,
            category: 'report_attachment',
            title: baseTitle.length >= 2 ? baseTitle : `Report attachment - ${file.name}`,
          })
        } catch (error) {
          throw new ReportAttachmentUploadError(draft, file.name, pendingFiles.slice(index), error)
        }
      }
      return sendNow ? sendInternalReport(draft.id, values.recipient_id) : draft
    },
    onSuccess: async (_, variables) => { await refresh(); setComposeOpen(false); setEditing(null); setPendingFiles([]); form.resetFields(); void message.success(variables.sendNow ? 'Report and attachments sent securely.' : 'Draft saved.') },
    onError: async (error) => {
      if (error instanceof ReportAttachmentUploadError) {
        setEditing(error.draft)
        setPendingFiles(error.remainingFiles)
        await refresh()
        void message.error(`${error.message} The report remains saved as draft; retry the remaining files.`)
        return
      }
      void message.error(getApiErrorMessage(error, 'The report could not be saved. Review the fields and recipient.'))
    },
  })
  const readMutation = useMutation({ mutationFn: readInternalReport, onSuccess: refresh })
  const archiveMutation = useMutation({ mutationFn: archiveInternalReport, onSuccess: async () => { await refresh(); void message.success('Report archived.') } })
  const deleteMutation = useMutation({ mutationFn: deleteInternalReport, onSuccess: async () => { await refresh(); void message.success('Draft deleted.') } })
  const downloadMutation = useMutation({ mutationFn: downloadInternalReport, onSuccess: (blob, id) => downloadBlob(blob, `internal-report-${id}.pdf`), onError: () => void message.error('The PDF could not be generated.') })
  const attachmentDownloadMutation = useMutation({
    mutationFn: (document: AssetDocument) => getAssetDocumentContent(document.id, false),
    onSuccess: (blob, document) => downloadBlob(blob, document.original_name),
    onError: () => void message.error('The attachment could not be downloaded.'),
  })
  const attachmentDeleteMutation = useMutation({
    mutationFn: deleteAssetDocument,
    onSuccess: async () => { await refresh(); void message.success('Attachment removed from the draft.') },
    onError: () => void message.error('The attachment could not be removed.'),
  })
  const summary = reportsQuery.data?.summary
  const composeProfile = reportComposeProfiles[variant]

  const recipientOptions = useMemo(() => (recipientsQuery.data ?? []).map((person) => ({ value: person.id, label: `${person.name} - ${humanize(person.role)}` })), [recipientsQuery.data])

  const openCompose = (report?: InternalReport, reply = false) => {
    const isDraft = report?.status === 'draft' && !reply
    setEditing(isDraft ? report : null)
    setPendingFiles([])
    setSelectedTemplate(undefined)
    form.setFieldsValue(report ? {
      recipient_id: reply ? report.sender?.id : report.recipient?.id,
      title: reply ? `Re: ${report.title}` : report.title,
      category: report.category,
      priority: report.priority,
      summary: reply ? `Response to report #${report.id}` : (report.summary ?? undefined),
      body: reply ? '' : report.body,
      period: report.period_start && report.period_end ? [dayjs(report.period_start), dayjs(report.period_end)] : undefined,
    } : { category: composeProfile.categories[0], priority: 'normal' })
    setComposeOpen(true)
  }
  const applyTemplate = (template: ReportComposeTemplate) => {
    setSelectedTemplate(template.key)
    form.setFieldsValue({
      title: template.title,
      category: template.category,
      priority: template.priority,
      summary: template.summary,
      body: template.body,
    })
  }
  const save = async (sendNow: boolean) => {
    try { saveMutation.mutate({ values: await form.validateFields(), sendNow }) } catch { /* Ant Design displays field errors. */ }
  }
  const selectReport = (report: InternalReport) => {
    setSelectedId(report.id)
    if (mailbox === 'inbox' && !report.read_at) readMutation.mutate(report.id)
  }

  return <section className={`report-mailbox report-mailbox--${variant}`}>
    <header className="report-mailbox__header"><div><span><Mail size={19} /></span><div><h2>{title}</h2><p>{subtitle}</p></div></div><Button type="primary" icon={<Plus size={15} />} onClick={() => { form.resetFields(); openCompose() }}>Compose report</Button></header>
    <nav className="report-mailbox__nav">
      {mailboxOptions.map(({ value, label, icon: Icon }) => <button key={value} className={mailbox === value ? 'active' : ''} onClick={() => setMailbox(value)}><Icon size={15} /><span>{label}</span>{value !== 'archived' && <b>{value === 'inbox' ? summary?.inbox ?? 0 : summary?.[value] ?? 0}</b>}{value === 'inbox' && Boolean(summary?.unread) && <i>{summary?.unread}</i>}</button>)}
      <Input value={search} onChange={(event) => setSearch(event.target.value)} prefix={<Search size={14} />} allowClear placeholder="Search reports" />
      <Select value={category} onChange={setCategory} allowClear placeholder="All categories" options={categoryOptions.map((value) => ({ value, label: humanize(value) }))} />
    </nav>
    <div className="report-mailbox__workspace">
      <div className="report-mailbox__list">
        {reportsQuery.isLoading ? <Spin /> : reports.length === 0 ? <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description={`No reports in ${mailbox}`} /> : reports.map((report) => {
          const person = mailbox === 'sent' || mailbox === 'drafts' ? report.recipient : report.sender
          return <button key={report.id} className={`${selected?.id === report.id ? 'selected' : ''} ${!report.read_at && mailbox === 'inbox' ? 'unread' : ''}`} onClick={() => selectReport(report)}>
            <Avatar src={person?.avatar_url ?? undefined}>{initials(person?.name ?? 'Draft')}</Avatar>
            <span><span><strong>{report.title}</strong><time>{dayjs(report.sent_at ?? report.updated_at).format('DD MMM')}</time></span><small>{person?.name ?? 'Recipient not selected'} / {humanize(report.category)}</small><p>{report.summary ?? report.body}</p></span>
            <Tag color={priorityColor(report.priority)}>{humanize(report.priority)}</Tag>
          </button>
        })}
      </div>
      <div className="report-mailbox__detail">
        {!selected ? <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="Select a report to read it" /> : <>
          <header><div><Tag color={priorityColor(selected.priority)}>{humanize(selected.priority)}</Tag><Tag>{humanize(selected.category)}</Tag><h3>{selected.title}</h3><p>{mailbox === 'sent' || mailbox === 'drafts' ? `To ${selected.recipient?.name ?? 'No recipient'}` : `From ${selected.sender?.name ?? 'Unknown sender'}`} / {dayjs(selected.sent_at ?? selected.updated_at).format('DD MMM YYYY, HH:mm')}</p></div><div>
            {mailbox === 'inbox' && <Tooltip title="Reply"><Button icon={<Reply size={15} />} onClick={() => openCompose(selected, true)} /></Tooltip>}
            {selected.status === 'draft' && <Tooltip title="Edit draft"><Button icon={<FilePenLine size={15} />} onClick={() => openCompose(selected)} /></Tooltip>}
            {selected.status !== 'draft' && <Tooltip title="Download signed PDF"><Button icon={<Download size={15} />} loading={downloadMutation.isPending} onClick={() => downloadMutation.mutate(selected.id)} /></Tooltip>}
            {selected.status !== 'draft' && mailbox !== 'archived' && <Tooltip title="Archive"><Button icon={<Archive size={15} />} onClick={() => archiveMutation.mutate(selected.id)} /></Tooltip>}
            {selected.status === 'draft' && <Popconfirm title="Delete this draft?" onConfirm={() => deleteMutation.mutate(selected.id)}><Button danger icon={<Trash2 size={15} />} /></Popconfirm>}
          </div></header>
          {selected.summary && <blockquote>{selected.summary}</blockquote>}
          <div className="report-mailbox__body">{selected.body.split('\n').map((line, index) => <p key={`${line}-${index}`}>{line || <br />}</p>)}</div>
          {(selected.attachments ?? []).length > 0 && <section className="report-attachments">
            <h4><Paperclip size={14} />Attachments <span>{selected.attachments?.length ?? 0}</span></h4>
            <div>{(selected.attachments ?? []).map((document) => <article key={document.id}>
              <FilePenLine size={17} />
              <span><strong>{document.title}</strong><small>{document.original_name} / {formatBytes(document.size_bytes)}</small></span>
              {document.previewable && <Tooltip title="Preview"><Button type="text" icon={<Eye size={14} />} onClick={() => setPreviewDocument(document)} /></Tooltip>}
              <Tooltip title="Download"><Button type="text" icon={<Download size={14} />} loading={attachmentDownloadMutation.isPending && attachmentDownloadMutation.variables?.id === document.id} onClick={() => attachmentDownloadMutation.mutate(document)} /></Tooltip>
              {selected.status === 'draft' && <Popconfirm title="Remove this attachment?" onConfirm={() => attachmentDeleteMutation.mutate(document.id)}><Button type="text" danger icon={<Trash2 size={14} />} /></Popconfirm>}
            </article>)}</div>
          </section>}
          {(selected.period_start || selected.related) && <footer>{selected.period_start && <span>Period: {dayjs(selected.period_start).format('DD MMM YYYY')} - {dayjs(selected.period_end).format('DD MMM YYYY')}</span>}{selected.related && <span>Linked {humanize(selected.related.type)} #{selected.related.id}</span>}</footer>}
        </>}
      </div>
    </div>
    <Drawer className="report-compose-drawer" open={composeOpen} size={620} onClose={() => { setComposeOpen(false); setEditing(null) }} title={editing ? 'Edit report draft' : 'Compose internal report'} extra={<MailOpen size={18} />}>
      <Form form={form} layout="vertical" requiredMark="optional">
        {!editing && <section className={`report-template-picker report-template-picker--${variant}`}>
          <header><span><Sparkles size={17} /></span><div><small>{composeProfile.eyebrow}</small><strong>Start from a role-specific structure</strong><p>{composeProfile.guidance}</p></div></header>
          <div>{composeProfile.templates.map((template) => <button type="button" key={template.key} className={selectedTemplate === template.key ? 'active' : ''} onClick={() => applyTemplate(template)}><span><strong>{template.label}</strong><small>{template.description}</small></span>{selectedTemplate === template.key ? <Check size={16} /> : <span>{humanize(template.category)}</span>}</button>)}</div>
        </section>}
        <div className="report-compose-grid"><Form.Item name="recipient_id" label="Recipient" rules={[{ required: true }]}><Select showSearch optionFilterProp="label" loading={recipientsQuery.isLoading} options={recipientOptions} placeholder="Organization employee" /></Form.Item><Form.Item name="priority" label="Priority" rules={[{ required: true }]}><Select options={(['normal', 'important', 'urgent'] as InternalReportPriority[]).map((value) => ({ value, label: humanize(value) }))} /></Form.Item></div>
        <Form.Item name="title" label="Report title" rules={[{ required: true, min: 3, max: 180 }]}><Input placeholder="Clear operational subject" /></Form.Item>
        <div className="report-compose-grid"><Form.Item name="category" label="Category" rules={[{ required: true }]}><Select options={composeProfile.categories.map((value) => ({ value, label: humanize(value) }))} /></Form.Item><Form.Item name="period" label="Covered period"><DatePicker.RangePicker style={{ width: '100%' }} /></Form.Item></div>
        <Form.Item name="summary" label="Executive summary"><Input.TextArea rows={3} maxLength={800} showCount placeholder="Decision-ready summary" /></Form.Item>
        <Form.Item name="body" label="Report details" rules={[{ required: true, min: 10 }]}><Input.TextArea rows={10} maxLength={20000} showCount placeholder="Facts, observations, actions and recommendations" /></Form.Item>
        <div className="report-compose-attachments">
          <label><Paperclip size={15} />Supporting files <span>Optional / PDF, image, Word or Excel / 10 MB each</span></label>
          <Upload.Dragger
            fileList={pendingFiles}
            multiple
            maxCount={5}
            accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx,.xls,.xlsx"
            beforeUpload={() => false}
            onChange={({ fileList }) => setPendingFiles(fileList.slice(-5))}
          >
            <UploadCloud size={22} />
            <p>Drop supporting files here or browse your device</p>
          </Upload.Dragger>
          {editing && (editing.attachments ?? []).length > 0 && <small>{editing.attachments?.length ?? 0} existing attachment(s) will remain linked to this draft.</small>}
        </div>
        <div className="report-compose-actions"><Button onClick={() => setComposeOpen(false)}>Cancel</Button><Button icon={<FilePenLine size={15} />} loading={saveMutation.isPending} onClick={() => void save(false)}>Save draft</Button><Button type="primary" icon={<Send size={15} />} loading={saveMutation.isPending} onClick={() => void save(true)}>Send report</Button></div>
      </Form>
    </Drawer>
    <DocumentPreviewModal document={previewDocument} open={Boolean(previewDocument)} onClose={() => setPreviewDocument(null)} />
  </section>
}

function priorityColor(priority: InternalReportPriority): string { return priority === 'urgent' ? 'red' : priority === 'important' ? 'gold' : 'blue' }
function initials(name: string): string { return name.split(' ').filter(Boolean).slice(0, 2).map((part) => part[0]).join('').toUpperCase() }
function formatBytes(bytes: number): string { return bytes < 1024 * 1024 ? `${(bytes / 1024).toFixed(1)} KB` : `${(bytes / 1024 / 1024).toFixed(1)} MB` }
