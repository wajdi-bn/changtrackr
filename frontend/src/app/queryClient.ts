import { QueryClient } from '@tanstack/react-query'
import { shouldRetryApiQuery } from '../api/apiErrors'

export const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      refetchOnWindowFocus: false,
      retry: shouldRetryApiQuery,
      staleTime: 30_000,
    },
  },
})
