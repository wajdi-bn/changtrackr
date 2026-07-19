import {
  BellOutlined,
  LogoutOutlined,
  QuestionCircleOutlined,
  SettingOutlined,
  UserOutlined,
} from '@ant-design/icons'
import { Avatar, Badge, Button, Dropdown, Input, Layout, Menu, Select, Space } from 'antd'
import type { MenuProps } from 'antd'
import { Outlet, useLocation, useNavigate } from 'react-router-dom'
import { useAuth } from '../features/auth/useAuth'
import { getRoleConfig } from '../features/auth/roleConfig'
import { AvailabilityRealtimeSync } from '../features/realtime/AvailabilityRealtimeSync'

const { Header, Content } = Layout

export function AppLayout() {
  const { user, primaryRole, logout } = useAuth()
  const navigate = useNavigate()
  const location = useLocation()
  const roleConfig = getRoleConfig(primaryRole)

  const selectedKey =
    roleConfig.navItems.find((item) => location.pathname.startsWith(item.path))?.path ??
    roleConfig.defaultPath

  const avatarMenu: MenuProps['items'] = [
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
      <aside className="app-sidebar">
        <button className="brand-button" onClick={() => navigate(roleConfig.defaultPath)}>
          <img className="brand-mark" src="/assets/Logo.png" alt="" />
          <span className="brand-name">ChargeTrackr</span>
        </button>

        <Menu
          className="app-menu"
          mode="inline"
          selectedKeys={[selectedKey]}
          items={roleConfig.navItems.map((item) => ({
            key: item.path,
            icon: item.icon,
            label: item.label,
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

          <Select
            className="network-select"
            value={user?.organization?.name ?? 'Tunisia network'}
            options={[{ value: user?.organization?.name ?? 'Tunisia network', label: user?.organization?.name ?? 'Tunisia network' }]}
          />

          <Input.Search
            className="global-search"
            placeholder="Search stations, sessions, alerts"
            allowClear
          />

          <Space size="middle">
            <Badge dot>
              <Button icon={<BellOutlined />} />
            </Badge>
            <Dropdown menu={{ items: avatarMenu }} trigger={['click']}>
              <button className="avatar-button">
                <Avatar src={user?.avatar_url ?? '/assets/avatar-vendor-1.jpg'} icon={<UserOutlined />} />
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
