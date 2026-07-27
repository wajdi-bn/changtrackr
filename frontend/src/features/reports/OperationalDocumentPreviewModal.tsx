import { PdfDocumentPreviewModal } from '../documents/PdfDocumentPreviewModal'
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
  return <PdfDocumentPreviewModal
    target={target ? {
      title: target.title,
      filename: target.filename,
      load: () => downloadOperationalDocument(target.type, target.id),
    } : null}
    onClose={onClose}
  />
}
