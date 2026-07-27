export type DocumentContext = 'station' | 'intervention' | 'report'
export type DocumentVisibility = 'organization' | 'public'

export interface AssetDocument {
  id: number
  category: string
  title: string
  description: string | null
  version_label: string | null
  visibility: DocumentVisibility
  original_name: string
  mime_type: string
  size_bytes: number
  checksum_sha256: string
  previewable: boolean
  issued_at: string | null
  expires_at: string | null
  uploaded_at: string
  uploaded_by: {
    id: number
    name: string
    avatar_url: string | null
  } | null
}

export interface AssetDocumentResponse {
  data: AssetDocument[]
  meta: {
    categories: string[]
    can_manage: boolean
    max_files: number
    max_file_size_mb: number
    accepted_extensions: string[]
  }
}

export interface AssetDocumentPayload {
  file: File
  category: string
  title: string
  description?: string
  version_label?: string
  visibility?: DocumentVisibility
  issued_at?: string
  expires_at?: string
}
