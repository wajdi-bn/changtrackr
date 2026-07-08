import {
  BellOutlined,
  LogoutOutlined,
  QuestionCircleOutlined,
  SettingOutlined,
  UserOutlined,
} from '@ant-design/icons'
import { Avatar, Badge, Button, Dropdown, Input, Layout, Menu, Space, Typography } from 'antd'
import type { MenuProps } from 'antd'
import { Outlet, useLocation, useNavigate } from 'react-router-dom'
import { useAuth } from '../features/auth/useAuth'
import { getRoleConfig } from '../features/auth/roleConfig'

const { Header, Sider, Content } = Layout

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
      <Sider width={248} collapsedWidth={76} className="app-sidebar">
        <button className="brand-button" onClick={() => navigate(roleConfig.defaultPath)}>
          <span className="brand-mark">CT</span>
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
      </Sider>

      <Layout>
        <Header className="app-header">
          <div>
            <Typography.Title level={4} className="app-title">
              ChargeTrackr
            </Typography.Title>
            <Typography.Text type="secondary">{roleConfig.shortLabel} workspace</Typography.Text>
          </div>

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
                <Avatar src={user?.avatar_url ?? undefined} icon={<UserOutlined />} />
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
