import { useEffect, useState } from 'react'
import { Alert, Button, Modal, Skeleton } from 'antd'
import { Download, ExternalLink, FileText } from 'lucide-react'
import type { AssetDocument } from '../../types/documents'
import { downloadBlob } from '../../utils/downloadBlob'
import { getAssetDocumentContent } from './documentApi'
import { PdfCanvasPreview } from './PdfCanvasPreview'

export function DocumentPreviewModal({ document, open, onClose }: {
  document: AssetDocument | null
  open: boolean
  onClose: () => void
}) {
  const [contentUrl, setContentUrl] = useState<string>()
  const [blob, setBlob] = useState<Blob>()
  const [loading, setLoading] = useState(false)
  const [failed, setFailed] = useState(false)

  useEffect(() => {
    if (!open || !document) {
      setContentUrl(undefined)
      setBlob(undefined)
      setFailed(false)
      return
    }

    let active = true
    let objectUrl: string | undefined
    setLoading(true)
    setFailed(false)
    void getAssetDocumentContent(document.id, true)
      .then((file) => {
        if (!active) return
        objectUrl = URL.createObjectURL(file)
        setBlob(file)
        setContentUrl(objectUrl)
      })
      .catch(() => active && setFailed(true))
      .finally(() => active && setLoading(false))

    return () => {
      active = false
      if (objectUrl) URL.revokeObjectURL(objectUrl)
    }
  }, [document, open])

  const footer = document ? [
    <Button key="download" icon={<Download size={15} />} disabled={!blob} onClick={() => blob && downloadBlob(blob, document.original_name)}>Download</Button>,
    ...(contentUrl ? [<Button key="new-tab" icon={<ExternalLink size={15} />} onClick={() => window.open(contentUrl, '_blank', 'noopener,noreferrer')}>Open separately</Button>] : []),
    <Button key="close" type="primary" onClick={onClose}>Done</Button>,
  ] : null

  return (
    <Modal className="document-preview-modal" open={open} title={document?.title ?? 'Document preview'} footer={footer} onCancel={onClose} width={940} destroyOnHidden>
      {loading && <div className="document-preview-loading"><Skeleton active paragraph={{ rows: 8 }} /></div>}
      {failed && <Alert type="error" showIcon title="Unable to preview this private document" description="The file may have been removed or your access may have changed." />}
      {!loading && !failed && document && !document.previewable && (
        <div className="document-preview-unavailable"><FileText size={42} /><h3>Preview is not available for this format</h3><p>Download the original file to open it with the appropriate desktop application.</p></div>
      )}
      {!loading && !failed && document?.previewable && contentUrl && (
        document.mime_type === 'application/pdf'
          ? blob && <PdfCanvasPreview blob={blob} />
          : <div className="document-preview-image"><img src={contentUrl} alt={document.title} /></div>
      )}
    </Modal>
  )
}
