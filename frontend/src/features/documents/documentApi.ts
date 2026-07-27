import { httpClient } from '../../api/httpClient'
import type {
  AssetDocument,
  AssetDocumentPayload,
  AssetDocumentResponse,
  DocumentContext,
} from '../../types/documents'

const contextPaths: Record<DocumentContext, (recordId: number) => string> = {
  station: (recordId) => `/stations/${recordId}/documents`,
  intervention: (recordId) => `/interventions/${recordId}/documents`,
  report: (recordId) => `/internal-reports/${recordId}/attachments`,
}

export async function getAssetDocuments(context: DocumentContext, recordId: number): Promise<AssetDocumentResponse> {
  return (await httpClient.get<AssetDocumentResponse>(contextPaths[context](recordId))).data
}

export async function uploadAssetDocument(context: DocumentContext, recordId: number, payload: AssetDocumentPayload): Promise<AssetDocument> {
  const formData = new FormData()
  formData.append('file', payload.file)
  formData.append('category', payload.category)
  formData.append('title', payload.title)
  if (payload.description) formData.append('description', payload.description)
  if (payload.version_label) formData.append('version_label', payload.version_label)
  if (payload.visibility) formData.append('visibility', payload.visibility)
  if (payload.issued_at) formData.append('issued_at', payload.issued_at)
  if (payload.expires_at) formData.append('expires_at', payload.expires_at)

  return (await httpClient.post<{ data: AssetDocument }>(contextPaths[context](recordId), formData)).data.data
}

export async function getAssetDocumentContent(documentId: number, inline = true): Promise<Blob> {
  return (await httpClient.get<Blob>(`/asset-documents/${documentId}/content`, {
    params: { inline: inline ? 1 : 0 },
    responseType: 'blob',
    timeout: 30000,
  })).data
}

export async function deleteAssetDocument(documentId: number): Promise<void> {
  await httpClient.delete(`/asset-documents/${documentId}`)
}
