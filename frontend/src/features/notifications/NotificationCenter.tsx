import { useEffect, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Badge, Button, Empty, Popover, Spin, Tooltip } from 'antd'
import {
  BellRing,
  CalendarClock,
  CheckCheck,
  CircleAlert,
  CreditCard,
  Info,
  TriangleAlert,
  Wrench,
} from 'lucide-react'
import { useNavigate } from 'react-router-dom'
import type { UserNotification } from '../../types/notification'
import { useAuth } from '../auth/useAuth'
import { createRealtimeClient } from '../realtime/echo'
import { getNotifications, markAllNotificationsRead, markNotificationRead } from './notificationApi'

export function NotificationCenter() {
  const { user } = useAuth()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [open, setOpen] = useState(false)
  const notificationsQuery = useQuery({
    queryKey: ['notifications'],
    queryFn: () => getNotifications(),
    enabled: Boolean(user),
    refetchInterval: 30_000,
  })
  const readMutation = useMutation({
    mutationFn: markNotificationRead,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['notifications'] }),
  })
  const readAllMutation = useMutation({
    mutationFn: markAllNotificationsRead,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['notifications'] }),
  })

  useEffect(() => {
    if (!user) return
    const echo = createRealtimeClient()
    const channelName = `users.${user.id}.notifications`
    const channel = echo.private(channelName)
    channel.listen('.user-notification.created', () => {
      void Promise.all([
        queryClient.invalidateQueries({ queryKey: ['notifications'] }),
        queryClient.invalidateQueries({ queryKey: ['alerts'] }),
        queryClient.invalidateQueries({ queryKey: ['interventions'] }),
        queryClient.invalidateQueries({ queryKey: ['maintenance'] }),
        queryClient.invalidateQueries({ queryKey: ['payments'] }),
        queryClient.invalidateQueries({ queryKey: ['dashboard'] }),
      ])
    })

    if (import.meta.env.DEV) {
      channel.error((error: unknown) => console.error(`[Realtime] subscription failed ${channelName}`, error))
    }

    return () => echo.leave(channelName)
  }, [queryClient, user])

  async function openNotification(notification: UserNotification) {
    if (!notification.is_read) await readMutation.mutateAsync(notification.id)
    setOpen(false)
    if (notification.action_url) navigate(notification.action_url)
  }

  const unread = notificationsQuery.data?.summary.unread ?? 0
  const content = <div className="notification-panel">
    <header>
      <span><strong>Notifications</strong><small>{unread > 0 ? `${unread} unread` : 'You are up to date'}</small></span>
      {unread > 0 && <Button type="text" size="small" icon={<CheckCheck size={15} />} loading={readAllMutation.isPending} onClick={() => readAllMutation.mutate()}>Mark all read</Button>}
    </header>
    <div className="notification-list">
      {notificationsQuery.isLoading && <div className="notification-loading"><Spin size="small" /> Loading notifications</div>}
      {!notificationsQuery.isLoading && (notificationsQuery.data?.data.length ?? 0) === 0 && <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="No notifications yet" />}
      {notificationsQuery.data?.data.map((notification) => <button
        key={notification.id}
        type="button"
        className={`notification-item ${notification.is_read ? '' : 'unread'}`}
        onClick={() => void openNotification(notification)}
      >
        <span className={`notification-icon ${notification.severity}`}>{notificationIcon(notification)}</span>
        <span className="notification-copy"><strong>{notification.title}</strong><p>{notification.message}</p><small>{notification.created_relative}</small></span>
        {!notification.is_read && <i aria-label="Unread" />}
      </button>)}
    </div>
  </div>

  return <Popover
    open={open}
    onOpenChange={setOpen}
    trigger="click"
    placement="bottomRight"
    content={content}
    classNames={{ root: 'notification-popover' }}
  >
    <Tooltip title="Notifications" placement="bottom">
      <Badge count={unread} size="small" overflowCount={99} offset={[-2, 3]}>
        <Button className="notification-trigger" type="text" aria-label="Notifications" icon={<BellRing size={19} />} />
      </Badge>
    </Tooltip>
  </Popover>
}

function notificationIcon(notification: UserNotification) {
  if (notification.category === 'payment') return <CreditCard size={17} />
  if (notification.category === 'maintenance') return <CalendarClock size={17} />
  if (notification.category === 'intervention' || notification.category === 'assignment') return <Wrench size={17} />
  if (notification.category === 'sla' || notification.severity === 'critical') return <TriangleAlert size={17} />
  if (notification.severity === 'warning') return <CircleAlert size={17} />
  return <Info size={17} />
}
