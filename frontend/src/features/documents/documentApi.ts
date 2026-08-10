import { httpClient } from '../../api/httpClient'
import type {
  AssetDocument,
  AssetDocumentPayload,
  AssetDocumentResponse,
  DocumentContext,
} from '../../types/documents'
import { buildAssetDocumentFormData, multipartRequestHeaders } from './multipartPayload'

const contextPaths: Record<DocumentContext, (recordId: number) => string> = {
  station: (recordId) => `/stations/${recordId}/documents`,
  intervention: (recordId) => `/interventions/${recordId}/documents`,
  report: (recordId) => `/internal-reports/${recordId}/attachments`,
}

export async function getAssetDocuments(context: DocumentContext, recordId: number): Promise<AssetDocumentResponse> {
  return (await httpClient.get<AssetDocumentResponse>(contextPaths[context](recordId))).data
}

export async function uploadAssetDocument(context: DocumentContext, recordId: number, payload: AssetDocumentPayload): Promise<AssetDocument> {
  return (await httpClient.post<{ data: AssetDocument }>(
    contextPaths[context](recordId),
    buildAssetDocumentFormData(payload),
    { headers: multipartRequestHeaders, timeout: 30000 },
  )).data.data
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
