import { useEffect, useMemo, useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Badge, Button, Empty, Popover, Segmented, Select, Spin, Tooltip } from 'antd'
import dayjs from 'dayjs'
import {
  BellRing,
  CalendarClock,
  CheckCheck,
  CircleAlert,
  CreditCard,
  FileText,
  Info,
  TriangleAlert,
  Wrench,
} from 'lucide-react'
import { useNavigate } from 'react-router-dom'
import type { UserNotification } from '../../types/notification'
import { safeInternalPath } from '../../utils/navigation'
import { useAuth } from '../auth/useAuth'
import { createRealtimeClient } from '../realtime/echo'
import { getNotifications, markAllNotificationsRead, markNotificationRead } from './notificationApi'
import { AnimatedBellIcon } from '../../components/AnimatedIcon'

export function NotificationCenter() {
  const { user } = useAuth()
  const userId = user?.id
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [open, setOpen] = useState(false)
  const [bellSignal, setBellSignal] = useState(0)
  const [status, setStatus] = useState<'all' | 'unread'>('all')
  const [category, setCategory] = useState<string | undefined>()
  const previousUnread = useRef<number | null>(null)
  const notificationsQuery = useQuery({
    queryKey: ['notifications', 'list', status, category],
    queryFn: () => getNotifications({ status, category, limit: 30 }),
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
    if (!userId) return
    const echo = createRealtimeClient()
    const channelName = `users.${userId}.notifications`
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
  }, [queryClient, userId])

  async function openNotification(notification: UserNotification) {
    if (!notification.is_read) await readMutation.mutateAsync(notification.id)
    setOpen(false)
    const actionPath = safeInternalPath(notification.action_url)
    if (actionPath) navigate(actionPath)
  }

  const unread = notificationsQuery.data?.summary.unread ?? 0
  const groupedNotifications = useMemo(() => {
    const groups = new Map<string, UserNotification[]>()
    for (const notification of notificationsQuery.data?.data ?? []) {
      const label = dayjs(notification.created_at).isSame(dayjs(), 'day') ? 'Today' : 'Earlier'
      groups.set(label, [...(groups.get(label) ?? []), notification])
    }
    return Array.from(groups.entries())
  }, [notificationsQuery.data?.data])

  useEffect(() => {
    if (previousUnread.current !== null && unread > previousUnread.current) {
      setBellSignal((current) => current + 1)
    }
    previousUnread.current = unread
  }, [unread])
  const content = <div className="notification-panel">
    <header>
      <span><strong>Notifications</strong><small>{unread > 0 ? `${unread} unread` : 'You are up to date'}</small></span>
      {unread > 0 && <Button type="text" size="small" icon={<CheckCheck size={15} />} loading={readAllMutation.isPending} onClick={() => readAllMutation.mutate()}>Mark all read</Button>}
    </header>
    <div className="notification-filters">
      <Segmented
        size="small"
        value={status}
        options={[{ label: 'All', value: 'all' }, { label: 'Unread', value: 'unread' }]}
        onChange={(value) => setStatus(value as 'all' | 'unread')}
      />
      <Select
        size="small"
        value={category}
        allowClear
        placeholder="All activity"
        options={[
          { value: 'alert', label: 'Alerts' },
          { value: 'assignment', label: 'Assignments' },
          { value: 'sla', label: 'SLA' },
          { value: 'intervention', label: 'Interventions' },
          { value: 'maintenance', label: 'Maintenance' },
          { value: 'payment', label: 'Payments' },
          { value: 'report', label: 'Reports' },
        ]}
        onChange={setCategory}
      />
    </div>
    <div className="notification-list">
      {notificationsQuery.isLoading && <div className="notification-loading"><Spin size="small" /> Loading notifications</div>}
      {!notificationsQuery.isLoading && (notificationsQuery.data?.data.length ?? 0) === 0 && <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="No notifications yet" />}
      {groupedNotifications.map(([label, notifications]) => <section className="notification-list-section" key={label}>
        <h4>{label}<span>{notifications.length}</span></h4>
        {notifications.map((notification) => <button
          key={notification.id}
          type="button"
          className={`notification-item ${notification.is_read ? '' : 'unread'}`}
          onClick={() => void openNotification(notification)}
        >
          <span className={`notification-icon ${notification.severity}`}>{notificationIcon(notification)}</span>
          <span className="notification-copy"><strong>{notification.title}</strong><p>{notification.message}</p><small>{notification.created_relative}</small></span>
          {!notification.is_read && <i aria-label="Unread" />}
        </button>)}
      </section>)}
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
        <Button className="notification-trigger" type="text" aria-label="Notifications" icon={<AnimatedBellIcon signal={bellSignal}><BellRing size={19} /></AnimatedBellIcon>} />
      </Badge>
    </Tooltip>
  </Popover>
}

function notificationIcon(notification: UserNotification) {
  if (notification.category === 'payment') return <CreditCard size={17} />
  if (notification.category === 'report') return <FileText size={17} />
  if (notification.category === 'maintenance') return <CalendarClock size={17} />
  if (notification.category === 'intervention' || notification.category === 'assignment') return <Wrench size={17} />
  if (notification.category === 'sla' || notification.severity === 'critical') return <TriangleAlert size={17} />
  if (notification.severity === 'warning') return <CircleAlert size={17} />
  return <Info size={17} />
}
