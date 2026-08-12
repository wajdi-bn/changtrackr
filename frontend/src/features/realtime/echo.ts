import Echo from 'laravel-echo'
import Pusher from 'pusher-js'
import { backendClient, backendUrl } from '../../api/httpClient'

type ReverbEcho = Echo<'reverb'>
let realtimeClient: ReverbEcho | null = null

export function createRealtimeClient(): ReverbEcho {
  if (realtimeClient) return realtimeClient

  const scheme = import.meta.env.VITE_REVERB_SCHEME?.trim() || 'http'
  const host = import.meta.env.DEV ? window.location.hostname : (import.meta.env.VITE_REVERB_HOST?.trim() || window.location.hostname)
  const port = Number(import.meta.env.VITE_REVERB_PORT ?? 8080)
  const forceTLS = scheme === 'https' && window.location.protocol === 'https:'

  realtimeClient = new Echo<'reverb'>({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY ?? 'local-key',
    wsHost: host,
    wsPort: port,
    wssPort: port,
    forceTLS,
    enabledTransports: forceTLS ? ['wss'] : ['ws'],
    Pusher,
    channelAuthorization: {
      customHandler: async (params, callback) => {
        try {
          const response = await backendClient.post(`${backendUrl}/broadcasting/auth`, {
            socket_id: params.socketId,
            channel_name: params.channelName,
          }, {
            timeout: import.meta.env.DEV ? 45_000 : 10_000,
          })
          callback(null, response.data)
        } catch (error) {
          if (import.meta.env.DEV) console.error('[Realtime] channel authorization failed', error)
          callback(error instanceof Error ? error : new Error('Channel authorization failed.'), null)
        }
      },
    },
  })

  return realtimeClient
}
