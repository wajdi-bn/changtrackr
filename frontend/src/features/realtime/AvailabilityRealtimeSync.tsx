import { useEffect } from 'react'
import { queryClient } from '../../app/queryClient'
import { useAuth } from '../auth/useAuth'
import { createRealtimeClient } from './echo'

export function AvailabilityRealtimeSync() {
  const { user, primaryRole } = useAuth()
  const userId = user?.id
  const organizationId = user?.organization?.id

  useEffect(() => {
    if (!userId) return

    const publicNetwork = primaryRole === 'client'
    const globalNetwork = primaryRole === 'super_admin'
    const channelName = globalNetwork
      ? 'stations.super-admin'
      : publicNetwork
        ? 'stations.public'
      : `organizations.${organizationId}.stations`

    if (!publicNetwork && !globalNetwork && !organizationId) return

    const echo = createRealtimeClient()
    const channel = echo.private(channelName)
    const canReceiveSessions = ['client', 'admin', 'operator', 'super_admin'].includes(primaryRole ?? '')
    const sessionChannelName = primaryRole === 'super_admin'
      ? 'sessions.super-admin'
      : primaryRole === 'client'
        ? `users.${userId}.sessions`
        : `organizations.${organizationId}.sessions`
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
        queryClient.invalidateQueries({ queryKey: ['dashboard'] }),
      ])
    })

    channel.listen('.ocpp-command.changed', () => {
      void Promise.all([
        queryClient.invalidateQueries({ queryKey: ['station-commands'] }),
        queryClient.invalidateQueries({ queryKey: ['station'] }),
        queryClient.invalidateQueries({ queryKey: ['stations'] }),
        queryClient.invalidateQueries({ queryKey: ['dashboard'] }),
      ])
    })

    sessionChannel?.listen('.charging-session.changed', () => {
      void Promise.all([
        queryClient.invalidateQueries({ queryKey: ['charging-sessions'] }),
        queryClient.invalidateQueries({ queryKey: ['payments'] }),
        queryClient.invalidateQueries({ queryKey: ['customers'] }),
        queryClient.invalidateQueries({ queryKey: ['stations'] }),
        queryClient.invalidateQueries({ queryKey: ['dashboard'] }),
      ])
    })

    sessionChannel?.listen('.charging-attempt.changed', () => {
      void Promise.all([
        queryClient.invalidateQueries({ queryKey: ['charging-attempts'] }),
        queryClient.invalidateQueries({ queryKey: ['charging-attempt'] }),
        queryClient.invalidateQueries({ queryKey: ['stations'] }),
        queryClient.invalidateQueries({ queryKey: ['dashboard'] }),
      ])
    })

    return () => {
      echo.leave(channelName)
      if (sessionChannel) echo.leave(sessionChannelName)
    }
  }, [organizationId, primaryRole, userId])

  return null
}
