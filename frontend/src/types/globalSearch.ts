export type GlobalSearchResultType =
  | 'organization'
  | 'user'
  | 'station'
  | 'alert'
  | 'intervention'
  | 'session'
  | 'payment'

export interface GlobalSearchResult {
  type: GlobalSearchResultType
  id: number
  group: string
  title: string
  subtitle: string
  status: string | null
  url: string
}

export interface GlobalSearchResponse {
  data: GlobalSearchResult[]
  summary: {
    total: number
    groups: Record<string, number>
  }
}
