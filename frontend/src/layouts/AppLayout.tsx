import { BellOutlined, DashboardOutlined, UserOutlined } from '@ant-design/icons'
import { Avatar, Button, Input, Layout, Space, Typography } from 'antd'
import { Outlet } from 'react-router-dom'

const { Header, Sider, Content } = Layout

export function AppLayout() {
  return (
    <Layout className="app-shell">
      <Sider width={76} className="app-sidebar">
        <div className="brand-mark">CT</div>
        <Button className="sidebar-button active" icon={<DashboardOutlined />} />
      </Sider>
      <Layout>
        <Header className="app-header">
          <div>
            <Typography.Title level={4} className="app-title">
              ChargeTrackr
            </Typography.Title>
            <Typography.Text type="secondary">EV station supervision</Typography.Text>
          </div>
          <Input.Search
            className="global-search"
            placeholder="Search stations, sessions, alerts"
            allowClear
          />
          <Space size="middle">
            <Button icon={<BellOutlined />} />
            <Avatar icon={<UserOutlined />} />
          </Space>
        </Header>
        <Content className="app-content">
          <Outlet />
        </Content>
      </Layout>
    </Layout>
  )
}
