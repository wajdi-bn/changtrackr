import {
  LogoutOutlined,
  CompassOutlined,
  QuestionCircleOutlined,
  SettingOutlined,
  UserOutlined,
} from '@ant-design/icons'
import { Avatar, Badge, Dropdown, Layout, Menu, Space } from 'antd'
import type { MenuProps } from 'antd'
import { Navigate, Outlet, useLocation, useNavigate } from 'react-router-dom'
import { useAuth } from '../features/auth/useAuth'
import { getRoleConfig } from '../features/auth/roleConfig'
import { AvailabilityRealtimeSync } from '../features/realtime/AvailabilityRealtimeSync'
import { NotificationCenter } from '../features/notifications/NotificationCenter'
import { AnimatedSidebarIcon } from '../components/AnimatedIcon'
import { WorkspaceCoach } from '../features/onboarding/WorkspaceCoach'
import { GlobalSearch } from '../features/search/GlobalSearch'
import { navigationNotificationCount, useNotificationNavigation } from '../features/notifications/useNotificationNavigation'

function initials(name: string | undefined): string {
  return (name ?? 'User')
    .split(' ')
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0])
    .join('')
    .toUpperCase()
}

const { Header, Content } = Layout

export function AppLayout() {
  const { user, primaryRole, logout } = useAuth()
  const navigate = useNavigate()
  const location = useLocation()
  const roleConfig = getRoleConfig(primaryRole)
  const notificationSummary = useNotificationNavigation(location.pathname)
  const commercialBlocked = Boolean(user?.organization?.commercial?.operations_blocked)
  const allowedDuringSuspension = ['/profile', '/settings', '/help', '/organization-billing', '/subscription-required'].includes(location.pathname)

  if (commercialBlocked && !allowedDuringSuspension) {
    return <Navigate to={primaryRole === 'admin' ? '/organization-billing' : '/subscription-required'} replace />
  }

  const visibleNavItems = commercialBlocked
    ? roleConfig.navItems.filter((item) => primaryRole === 'admin' && item.path === '/organization-billing')
    : roleConfig.navItems

  const selectedKey =
    visibleNavItems.find((item) => location.pathname.startsWith(item.path))?.path ??
    roleConfig.defaultPath

  const avatarMenu: MenuProps['items'] = [
    {
      key: 'guide',
      icon: <CompassOutlined />,
      label: user?.onboarding.completed ? 'Workspace guide' : 'Resume setup guide',
      onClick: () => navigate('/welcome'),
    },
    {
      key: 'profile',
      icon: <UserOutlined />,
      label: 'Profile',
      onClick: () => navigate('/profile'),
    },
    {
      key: 'settings',
      icon: <SettingOutlined />,
      label: 'Settings',
      onClick: () => navigate('/settings'),
    },
    {
      key: 'help',
      icon: <QuestionCircleOutlined />,
      label: 'Help',
      onClick: () => navigate('/help'),
    },
    { type: 'divider' },
    {
      key: 'logout',
      icon: <LogoutOutlined />,
      label: 'Logout',
      onClick: async () => {
        await logout()
        navigate('/login', { replace: true })
      },
    },
  ]

  return (
    <Layout className="app-shell">
      <AvailabilityRealtimeSync />
      <WorkspaceCoach />
      <aside className="app-sidebar">
        <button className="brand-button" onClick={() => navigate(roleConfig.defaultPath)}>
          <img className="brand-mark" src="/assets/Logo.png" alt="" />
          <span className="brand-name">ChargeTrackr</span>
        </button>

        <Menu
          className="app-menu"
          mode="inline"
          selectedKeys={[selectedKey]}
          items={visibleNavItems.map((item) => ({
            key: item.path,
            icon: <Badge
              className="nav-notification-badge"
              count={navigationNotificationCount(item.path, notificationSummary)}
              size="small"
              overflowCount={99}
              offset={[5, -4]}
            >
              <AnimatedSidebarIcon active={selectedKey === item.path}>{item.icon}</AnimatedSidebarIcon>
            </Badge>,
            label: <span data-tour={item.path === roleConfig.navItems[1]?.path ? 'primary-action' : undefined}>{item.label}</span>,
            onClick: () => navigate(item.path),
          }))}
        />
      </aside>

      <Layout className="app-main-layout">
        <Header className="app-header">
          <div className="topbar-brand">
            <img src="/assets/Logo.png" alt="" />
            <div><strong>ChargeTrackr</strong><small>{roleConfig.shortLabel} workspace</small></div>
          </div>

          <GlobalSearch role={primaryRole} />

          <Space size="middle">
            <span data-tour="notifications"><NotificationCenter /></span>
            <Dropdown menu={{ items: avatarMenu }} trigger={['click']}>
              <button className="avatar-button" data-tour="account-menu">
                <Avatar src={user?.avatar_url ?? undefined} icon={!user?.avatar_url ? <UserOutlined /> : undefined}>{!user?.avatar_url ? initials(user?.name) : null}</Avatar>
                <span className="avatar-copy">
                  <strong>{user?.name}</strong>
                  <small>{roleConfig.label}</small>
                </span>
              </button>
            </Dropdown>
          </Space>
        </Header>

        <Content className="app-content">
          <div className="content-frame">
            <Outlet />
          </div>
        </Content>
      </Layout>
    </Layout>
  )
}
