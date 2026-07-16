import { Space, Spin, Typography } from 'antd'
import { useEffect } from 'react'
import { useNavigate } from 'react-router-dom'
import { getRoleConfig } from '../features/auth/roleConfig'
import { useAuth } from '../features/auth/useAuth'

export function GoogleOAuthCallbackPage() {
  const { isAuthenticated, isLoading, primaryRole } = useAuth()
  const navigate = useNavigate()

  useEffect(() => {
    if (isLoading) {
      return
    }

    navigate(
      isAuthenticated
        ? getRoleConfig(primaryRole).defaultPath
        : '/login?oauth_error=session_not_created',
      { replace: true },
    )
  }, [isAuthenticated, isLoading, navigate, primaryRole])

  return (
    <div className="auth-loading">
      <Space direction="vertical" align="center" size="middle">
        <Spin size="large" />
        <Typography.Text>Completing Google sign in...</Typography.Text>
      </Space>
    </div>
  )
}
