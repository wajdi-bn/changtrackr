import { Card, Col, Row, Statistic, Typography } from 'antd'
import { Activity, AlertTriangle, BatteryCharging, MapPinned } from 'lucide-react'

const stats = [
  { title: 'Stations', value: 1248, icon: <MapPinned size={22} /> },
  { title: 'Available', value: 756, icon: <BatteryCharging size={22} /> },
  { title: 'Active sessions', value: 341, icon: <Activity size={22} /> },
  { title: 'Open alerts', value: 54, icon: <AlertTriangle size={22} /> },
]

export function HomePage() {
  return (
    <div className="page-stack">
      <section className="hero-panel">
        <Typography.Text className="breadcrumb">Workspace / Setup</Typography.Text>
        <Typography.Title level={1}>ChargeTrackr development workspace</Typography.Title>
        <Typography.Paragraph>
          Frontend environment is ready. Backend Laravel setup is documented and will be
          scaffolded after PHP and Composer are installed.
        </Typography.Paragraph>
      </section>

      <Row gutter={[16, 16]}>
        {stats.map((item) => (
          <Col xs={24} sm={12} lg={6} key={item.title}>
            <Card>
              <div className="stat-icon">{item.icon}</div>
              <Statistic title={item.title} value={item.value} />
            </Card>
          </Col>
        ))}
      </Row>
    </div>
  )
}
