import { useEffect, useState } from 'react'
import { Alert, Button, Modal, Skeleton } from 'antd'
import { Download, ExternalLink } from 'lucide-react'
import { downloadBlob } from '../../utils/downloadBlob'
import { PdfCanvasPreview } from '../documents/PdfCanvasPreview'
import { downloadOperationalDocument, type OperationalDocument } from './reportingApi'

export interface OperationalPreviewTarget {
  type: OperationalDocument
  id: number
  title: string
  filename: string
}

export function OperationalDocumentPreviewModal({ target, onClose }: {
  target: OperationalPreviewTarget | null
  onClose: () => void
}) {
  const [blob, setBlob] = useState<Blob>()
  const [url, setUrl] = useState<string>()
  const [loading, setLoading] = useState(false)
  const [failed, setFailed] = useState(false)

  useEffect(() => {
    if (!target) {
      setBlob(undefined)
      setUrl(undefined)
      setFailed(false)
      return
    }
    let active = true
    let objectUrl: string | undefined
    setLoading(true)
    setFailed(false)
    void downloadOperationalDocument(target.type, target.id)
      .then((file) => {
        if (!active) return
        objectUrl = URL.createObjectURL(file)
        setBlob(file)
        setUrl(objectUrl)
      })
      .catch(() => active && setFailed(true))
      .finally(() => active && setLoading(false))

    return () => {
      active = false
      if (objectUrl) URL.revokeObjectURL(objectUrl)
    }
  }, [target])

  return (
    <Modal
      className="document-preview-modal"
      open={Boolean(target)}
      title={target?.title ?? 'Document preview'}
      onCancel={onClose}
      width={940}
      destroyOnHidden
      footer={[
        <Button key="download" icon={<Download size={15} />} disabled={!blob} onClick={() => blob && target && downloadBlob(blob, target.filename)}>Download PDF</Button>,
        ...(url ? [<Button key="separate" icon={<ExternalLink size={15} />} onClick={() => window.open(url, '_blank', 'noopener,noreferrer')}>Open separately</Button>] : []),
        <Button key="done" type="primary" onClick={onClose}>Done</Button>,
      ]}
    >
      {loading && <div className="document-preview-loading"><Skeleton active paragraph={{ rows: 8 }} /></div>}
      {failed && <Alert type="error" showIcon title="Unable to generate this document" description="Retry later or verify that your account still has access to this record." />}
      {!loading && !failed && blob && <PdfCanvasPreview blob={blob} />}
    </Modal>
  )
}
