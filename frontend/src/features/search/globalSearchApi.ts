import { httpClient } from '../../api/httpClient'
import type { GlobalSearchResponse } from '../../types/globalSearch'

export async function globalSearch(query: string, limit = 5): Promise<GlobalSearchResponse> {
  const response = await httpClient.get<GlobalSearchResponse>('/search', {
    params: { q: query, limit },
  })

  return response.data
}
