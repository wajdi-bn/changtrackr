import { useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  App,
  Avatar,
  Button,
  DatePicker,
  Empty,
  Form,
  Input,
  Modal,
  Popconfirm,
  Select,
  Skeleton,
  Tag,
  Upload,
} from 'antd'
import type { UploadFile } from 'antd'
import type { Dayjs } from 'dayjs'
import dayjs from 'dayjs'
import {
  Download,
  Eye,
  FileImage,
  FileSpreadsheet,
  FileText,
  FileType2,
  FolderOpen,
  Plus,
  Search,
  ShieldCheck,
  Trash2,
  UploadCloud,
} from 'lucide-react'
import type { AssetDocument, DocumentContext, DocumentVisibility } from '../../types/documents'
import { downloadBlob } from '../../utils/downloadBlob'
import { deleteAssetDocument, getAssetDocumentContent, getAssetDocuments, uploadAssetDocument } from './documentApi'
import { DocumentPreviewModal } from './DocumentPreviewModal'

interface UploadValues {
  category: string
  title: string
  description?: string
  version_label?: string
  visibility?: DocumentVisibility
  dates?: [Dayjs, Dayjs]
}

export function DocumentManager({ context, recordId, title = 'Document library', subtitle = 'Controlled files and operational references for this record.' }: {
  context: DocumentContext
  recordId: number
  title?: string
  subtitle?: string
}) {
  const [search, setSearch] = useState('')
  const [category, setCategory] = useState<string>()
  const [uploadOpen, setUploadOpen] = useState(false)
  const [selectedFile, setSelectedFile] = useState<UploadFile[]>([])
  const [previewDocument, setPreviewDocument] = useState<AssetDocument | null>(null)
  const [form] = Form.useForm<UploadValues>()
  const { message } = App.useApp()
  const queryClient = useQueryClient()
  const queryKey = ['asset-documents', context, recordId]
  const documentsQuery = useQuery({ queryKey, queryFn: () => getAssetDocuments(context, recordId) })
  const refresh = () => queryClient.invalidateQueries({ queryKey })

  const uploadMutation = useMutation({
    mutationFn: async (values: UploadValues) => {
      const file = selectedFile[0]?.originFileObj
      if (!file) throw new Error('A file is required.')
      return uploadAssetDocument(context, recordId, {
        file,
        category: values.category,
        title: values.title,
        description: values.description,
        version_label: values.version_label,
        visibility: context === 'station' ? values.visibility : 'organization',
        issued_at: values.dates?.[0].format('YYYY-MM-DD'),
        expires_at: values.dates?.[1].format('YYYY-MM-DD'),
      })
    },
    onSuccess: async () => {
      await refresh()
      setUploadOpen(false)
      setSelectedFile([])
      form.resetFields()
      void message.success('Document uploaded securely.')
    },
    onError: () => void message.error('The document could not be uploaded. Check its format, size and metadata.'),
  })
  const deleteMutation = useMutation({
    mutationFn: deleteAssetDocument,
    onSuccess: async () => { await refresh(); void message.success('Document deleted.') },
    onError: () => void message.error('This document could not be deleted.'),
  })
  const downloadMutation = useMutation({
    mutationFn: (document: AssetDocument) => getAssetDocumentContent(document.id, false),
    onSuccess: (blob, document) => downloadBlob(blob, document.original_name),
    onError: () => void message.error('The document could not be downloaded.'),
  })

  const response = documentsQuery.data
  const documents = useMemo(() => {
    const term = search.trim().toLowerCase()
    return (response?.data ?? []).filter((document) => {
      const matchesCategory = !category || document.category === category
      const matchesSearch = !term || [document.title, document.original_name, document.description, document.version_label]
        .some((value) => value?.toLowerCase().includes(term))
      return matchesCategory && matchesSearch
    })
  }, [category, response?.data, search])

  if (documentsQuery.isLoading) return <div className="document-manager-loading"><Skeleton active paragraph={{ rows: 8 }} /></div>

  return (
    <section className="document-manager">
      <header className="document-manager-header">
        <div><span><FolderOpen size={20} /></span><div><h2>{title}</h2><p>{subtitle}</p></div></div>
        {response?.meta.can_manage && <Button type="primary" icon={<Plus size={15} />} onClick={() => setUploadOpen(true)}>Add document</Button>}
      </header>
      <div className="document-manager-toolbar">
        <Input value={search} onChange={(event) => setSearch(event.target.value)} prefix={<Search size={15} />} allowClear placeholder="Search title, file or version" />
        <Select value={category} onChange={setCategory} allowClear placeholder="All categories" options={(response?.meta.categories ?? []).map((value) => ({ value, label: humanize(value) }))} />
        <span><ShieldCheck size={14} /> Private storage · {response?.data.length ?? 0}/{response?.meta.max_files ?? 0} files</span>
      </div>
      {documents.length === 0 ? (
        <Empty className="document-manager-empty" image={Empty.PRESENTED_IMAGE_SIMPLE} description={response?.data.length ? 'No document matches these filters' : 'No document has been added yet'}>
          {response?.meta.can_manage && !response.data.length && <Button icon={<UploadCloud size={15} />} onClick={() => setUploadOpen(true)}>Upload the first document</Button>}
        </Empty>
      ) : (
        <div className="document-grid">
          {documents.map((document) => {
            const Icon = documentIcon(document.mime_type)
            const expired = document.expires_at ? dayjs(document.expires_at).isBefore(dayjs(), 'day') : false
            return <article key={document.id} className="document-card">
              <div className={`document-card-icon ${mimeTone(document.mime_type)}`}><Icon size={21} /></div>
              <div className="document-card-copy">
                <div><Tag>{humanize(document.category)}</Tag>{document.version_label && <Tag color="blue">{document.version_label}</Tag>}{expired && <Tag color="red">Expired</Tag>}</div>
                <h3>{document.title}</h3>
                <p>{document.description || document.original_name}</p>
                <footer>
                  <Avatar size={22} src={document.uploaded_by?.avatar_url ?? undefined}>{initials(document.uploaded_by?.name)}</Avatar>
                  <span><strong>{document.uploaded_by?.name ?? 'Former user'}</strong><small>{formatBytes(document.size_bytes)} · {dayjs(document.uploaded_at).format('DD MMM YYYY')}</small></span>
                  {document.visibility === 'public' && <Tag color="green">Client visible</Tag>}
                </footer>
              </div>
              <div className="document-card-actions">
                {document.previewable && <Button type="text" aria-label={`Preview ${document.title}`} icon={<Eye size={15} />} onClick={() => setPreviewDocument(document)} />}
                <Button type="text" aria-label={`Download ${document.title}`} icon={<Download size={15} />} loading={downloadMutation.isPending && downloadMutation.variables?.id === document.id} onClick={() => downloadMutation.mutate(document)} />
                {response?.meta.can_manage && <Popconfirm title="Delete this document?" description="The private file and its metadata will be removed." okText="Delete" okButtonProps={{ danger: true }} onConfirm={() => deleteMutation.mutate(document.id)}><Button type="text" danger aria-label={`Delete ${document.title}`} icon={<Trash2 size={15} />} /></Popconfirm>}
              </div>
            </article>
          })}
        </div>
      )}

      <Modal
        className="document-upload-modal"
        open={uploadOpen}
        title="Add operational document"
        okText="Upload document"
        okButtonProps={{ loading: uploadMutation.isPending, disabled: selectedFile.length === 0 }}
        onOk={() => form.submit()}
        onCancel={() => { setUploadOpen(false); setSelectedFile([]); form.resetFields() }}
        width={700}
      >
        <Upload.Dragger
          fileList={selectedFile}
          maxCount={1}
          accept={(response?.meta.accepted_extensions ?? []).map((extension) => `.${extension}`).join(',')}
          beforeUpload={() => false}
          onChange={({ fileList }) => {
            const next = fileList.slice(-1)
            setSelectedFile(next)
            const name = next[0]?.name?.replace(/\.[^.]+$/, '')
            if (name && !form.getFieldValue('title')) form.setFieldValue('title', name)
          }}
          onRemove={() => { setSelectedFile([]); return true }}
        >
          <UploadCloud size={28} />
          <p>Drop one file here or select it from your device</p>
          <span>PDF, image, Word or Excel · {response?.meta.max_file_size_mb ?? 10} MB maximum</span>
        </Upload.Dragger>
        <Form form={form} layout="vertical" requiredMark="optional" initialValues={{ visibility: 'organization' }} onFinish={(values) => uploadMutation.mutate(values)}>
          <div className="document-form-grid">
            <Form.Item name="title" label="Document title" rules={[{ required: true, min: 2, max: 180 }]}><Input placeholder="Installation and commissioning manual" /></Form.Item>
            <Form.Item name="category" label="Category" rules={[{ required: true }]}><Select options={(response?.meta.categories ?? []).map((value) => ({ value, label: humanize(value) }))} /></Form.Item>
          </div>
          <div className="document-form-grid">
            <Form.Item name="version_label" label="Version"><Input placeholder="v2.1 or 2026-07" /></Form.Item>
            <Form.Item name="dates" label="Issue and expiry dates"><DatePicker.RangePicker style={{ width: '100%' }} /></Form.Item>
          </div>
          {context === 'station' && <Form.Item name="visibility" label="Audience"><Select options={[{ value: 'organization', label: 'Organization employees only' }, { value: 'public', label: 'Clients viewing this station' }]} /></Form.Item>}
          <Form.Item name="description" label="Description"><Input.TextArea rows={3} maxLength={1200} showCount placeholder="Purpose, scope or important usage note" /></Form.Item>
        </Form>
      </Modal>
      <DocumentPreviewModal document={previewDocument} open={Boolean(previewDocument)} onClose={() => setPreviewDocument(null)} />
    </section>
  )
}

function documentIcon(mimeType: string) {
  if (mimeType.startsWith('image/')) return FileImage
  if (mimeType.includes('spreadsheet') || mimeType.includes('excel')) return FileSpreadsheet
  if (mimeType.includes('word')) return FileType2
  return FileText
}

function mimeTone(mimeType: string): string {
  if (mimeType === 'application/pdf') return 'pdf'
  if (mimeType.startsWith('image/')) return 'image'
  if (mimeType.includes('spreadsheet') || mimeType.includes('excel')) return 'sheet'
  return 'document'
}

function formatBytes(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`
  return `${(bytes / 1024 / 1024).toFixed(1)} MB`
}

function humanize(value: string): string {
  return value.replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase())
}

function initials(name?: string): string {
  return (name ?? 'User').split(' ').filter(Boolean).slice(0, 2).map((part) => part[0]).join('').toUpperCase()
}
