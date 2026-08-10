import type { AssetDocumentPayload } from '../../types/documents'

export const multipartRequestHeaders = { 'Content-Type': 'multipart/form-data' } as const

export function buildAssetDocumentFormData(payload: AssetDocumentPayload): FormData {
  const formData = new FormData()
  formData.append('file', payload.file)
  formData.append('category', payload.category)
  formData.append('title', payload.title)
  if (payload.description) formData.append('description', payload.description)
  if (payload.version_label) formData.append('version_label', payload.version_label)
  if (payload.visibility) formData.append('visibility', payload.visibility)
  if (payload.issued_at) formData.append('issued_at', payload.issued_at)
  if (payload.expires_at) formData.append('expires_at', payload.expires_at)

  return formData
}
