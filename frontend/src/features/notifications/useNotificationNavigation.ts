import { useEffect, useRef } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import type { NotificationContext, NotificationSummary } from '../../types/notification'
import { getNotifications, markNotificationContextRead } from './notificationApi'

export function useNotificationNavigation(pathname: string): NotificationSummary | undefined {
  const queryClient = useQueryClient()
  const observedPath = useRef<string | null>(null)
  const summaryQuery = useQuery({
    queryKey: ['notifications', 'summary'],
    queryFn: () => getNotifications({ limit: 1 }),
    refetchInterval: 30_000,
  })
  const contextMutation = useMutation({
    mutationFn: markNotificationContextRead,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['notifications'] }),
  })

  useEffect(() => {
    if (!summaryQuery.data || observedPath.current === pathname) return
    observedPath.current = pathname
    const context = notificationContextForPath(pathname)
    if (context && (summaryQuery.data.summary.unread_by_context[context] ?? 0) > 0) {
      contextMutation.mutate(context)
    }
  }, [contextMutation, pathname, summaryQuery.data])

  return summaryQuery.data?.summary
}

export function navigationNotificationCount(path: string, summary?: NotificationSummary): number {
  const context = notificationContextForPath(path)
  return context ? summary?.unread_by_context[context] ?? 0 : 0
}

function notificationContextForPath(path: string): NotificationContext | null {
  if (path.startsWith('/alerts') || path.startsWith('/assigned-alerts')) return 'alerts'
  if (path.startsWith('/interventions') || path.startsWith('/my-interventions')) return 'interventions'
  if (path.startsWith('/maintenance') && !path.startsWith('/maintenance-reports')) return 'maintenance'
  if (path.startsWith('/payments')) return 'payments'
  if (['/reports', '/analytics-reports', '/platform-reports', '/maintenance-reports'].some((prefix) => path.startsWith(prefix))) return 'reports'
  return null
}
