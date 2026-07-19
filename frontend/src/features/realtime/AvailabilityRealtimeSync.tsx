import { useEffect } from 'react'
import { queryClient } from '../../app/queryClient'
import { useAuth } from '../auth/useAuth'
import { createRealtimeClient } from './echo'

export function AvailabilityRealtimeSync() {
  const { user, primaryRole } = useAuth()

  useEffect(() => {
    if (!user) return

    const publicNetwork = primaryRole === 'client'
    const globalNetwork = primaryRole === 'super_admin'
    const channelName = globalNetwork
      ? 'stations.super-admin'
      : publicNetwork
        ? 'stations.public'
      : `organizations.${user.organization?.id}.stations`

    if (!publicNetwork && !globalNetwork && !user.organization?.id) return

    const echo = createRealtimeClient()
    const channel = echo.private(channelName)
    const canReceiveSessions = ['client', 'operator', 'super_admin'].includes(primaryRole ?? '')
    const sessionChannelName = primaryRole === 'super_admin'
      ? 'sessions.super-admin'
      : primaryRole === 'client'
        ? `users.${user.id}.sessions`
        : `organizations.${user.organization?.id}.sessions`
    const sessionChannel = canReceiveSessions ? echo.private(sessionChannelName) : null

    if (import.meta.env.DEV) {
      channel.error((error: unknown) => console.error(`[Realtime] subscription failed ${channelName}`, error))
      sessionChannel?.error((error: unknown) => console.error(`[Realtime] subscription failed ${sessionChannelName}`, error))
    }

    channel.listen('.station.availability.changed', () => {
      void Promise.all([
        queryClient.invalidateQueries({ queryKey: ['stations'] }),
        queryClient.invalidateQueries({ queryKey: ['station'] }),
        queryClient.invalidateQueries({ queryKey: ['alerts'] }),
      ])
    })

    sessionChannel?.listen('.charging-session.changed', () => {
      void Promise.all([
        queryClient.invalidateQueries({ queryKey: ['charging-sessions'] }),
        queryClient.invalidateQueries({ queryKey: ['payments'] }),
        queryClient.invalidateQueries({ queryKey: ['customers'] }),
        queryClient.invalidateQueries({ queryKey: ['stations'] }),
      ])
    })

    sessionChannel?.listen('.charging-attempt.changed', () => {
      void Promise.all([
        queryClient.invalidateQueries({ queryKey: ['charging-attempts'] }),
        queryClient.invalidateQueries({ queryKey: ['charging-attempt'] }),
        queryClient.invalidateQueries({ queryKey: ['stations'] }),
      ])
    })

    return () => {
      echo.leave(channelName)
      if (sessionChannel) echo.leave(sessionChannelName)
    }
  }, [primaryRole, user])

  return null
}
