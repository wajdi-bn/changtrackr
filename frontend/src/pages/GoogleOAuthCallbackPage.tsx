import { Space, Spin, Typography } from 'antd'
import { useEffect } from 'react'
import { useNavigate } from 'react-router-dom'
import { getAuthenticatedEntryPath } from '../features/auth/authNavigation'
import { useAuth } from '../features/auth/useAuth'

export function GoogleOAuthCallbackPage() {
  const { isAuthenticated, isLoading, user } = useAuth()
  const navigate = useNavigate()

  useEffect(() => {
    if (isLoading) {
      return
    }

    navigate(
      isAuthenticated
        ? getAuthenticatedEntryPath(user!)
        : '/login?oauth_error=session_not_created',
      { replace: true },
    )
  }, [isAuthenticated, isLoading, navigate, user])

  return (
    <div className="auth-loading">
      <Space direction="vertical" align="center" size="middle">
        <Spin size="large" />
        <Typography.Text>Completing Google sign in...</Typography.Text>
      </Space>
    </div>
  )
}
