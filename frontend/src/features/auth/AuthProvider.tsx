import { useCallback, useEffect, useMemo, useState } from 'react'
import type { ReactNode } from 'react'
import type { AuthUser } from '../../types/auth'
import { queryClient } from '../../app/queryClient'
import { loginRequest, logoutRequest, sessionRequest } from './authApi'
import { AuthContext } from './authContext'
import type { AuthContextValue } from './authContext'

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<AuthUser | null>(null)
  const [isLoading, setIsLoading] = useState(true)

  const clearSession = useCallback(() => {
    queryClient.clear()
    setUser(null)
  }, [])

  useEffect(() => {
    let mounted = true

    localStorage.removeItem('chargetrackr_access_token')
    localStorage.removeItem('chargetrackr_user')

    async function loadCurrentUser() {
      try {
        const session = await sessionRequest()
        if (!mounted) {
          return
        }
        setUser(session.authenticated ? session.user : null)
      } catch {
        if (mounted) {
          clearSession()
        }
      } finally {
        if (mounted) {
          setIsLoading(false)
        }
      }
    }

    void loadCurrentUser()

    return () => {
      mounted = false
    }
  }, [clearSession])

  const login = useCallback(async (email: string, password: string) => {
    const response = await loginRequest({ email, password })
    queryClient.clear()
    setUser(response.user)
    return response.user
  }, [])

  const logout = useCallback(async () => {
    try {
      if (user) {
        await logoutRequest()
      }
    } finally {
      clearSession()
    }
  }, [clearSession, user])

  const primaryRole = user?.roles[0] ?? null

  const value = useMemo<AuthContextValue>(
    () => ({
      user,
      isAuthenticated: Boolean(user),
      isLoading,
      primaryRole,
      login,
      logout,
      hasRole: (roles) => Boolean(primaryRole && roles.includes(primaryRole)),
    }),
    [isLoading, login, logout, primaryRole, user],
  )

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}
