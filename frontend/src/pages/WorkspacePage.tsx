import { Card, Col, Empty, Row, Typography } from 'antd'

interface WorkspacePageProps {
  title: string
  subtitle: string
}

export function WorkspacePage({ title, subtitle }: WorkspacePageProps) {
  return (
    <div className="page-stack">
      <section className="workspace-hero compact">
        <Typography.Text className="breadcrumb">Workspace / {title}</Typography.Text>
        <Typography.Title level={1}>{title}</Typography.Title>
        <Typography.Paragraph>{subtitle}</Typography.Paragraph>
      </section>

      <Row gutter={[16, 16]}>
        <Col xs={24} lg={16}>
          <Card title="Workflow area">
            <Empty
              description="The domain model and API endpoints for this module will be implemented after the auth and organization foundation."
              image={Empty.PRESENTED_IMAGE_SIMPLE}
            />
          </Card>
        </Col>
        <Col xs={24} lg={8}>
          <Card title="Planned controls">
            <ul className="plain-list">
              <li>Search and filters</li>
              <li>Table, grid and detail views</li>
              <li>Export action where relevant</li>
              <li>Role-based create/update permissions</li>
            </ul>
          </Card>
        </Col>
      </Row>
    </div>
  )
}
