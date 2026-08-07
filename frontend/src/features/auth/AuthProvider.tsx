import { useCallback, useEffect, useMemo, useState } from 'react'
import type { ReactNode } from 'react'
import type { AuthUser } from '../../types/auth'
import { queryClient } from '../../app/queryClient'
import { loginRequest, logoutRequest, resetSessionRequestCache, sessionRequest } from './authApi'
import { markSessionActive, subscribeToSessionExpiration } from './authSession'
import { AuthContext } from './authContext'
import type { AuthContextValue } from './authContext'
import { hasAnyRole, resolvePrimaryRole } from './roleResolution'

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<AuthUser | null>(null)
  const [isLoading, setIsLoading] = useState(true)

  const clearSession = useCallback(() => {
    resetSessionRequestCache()
    queryClient.clear()
    setUser(null)
  }, [])

  useEffect(() => {
    let mounted = true
    const unsubscribe = subscribeToSessionExpiration(clearSession)

    localStorage.removeItem('chargetrackr_access_token')
    localStorage.removeItem('chargetrackr_user')

    async function loadCurrentUser() {
      try {
        const session = await sessionRequest()
        if (!mounted) {
          return
        }
        setUser(session.authenticated ? session.user : null)
        if (session.authenticated) markSessionActive()
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
      unsubscribe()
    }
  }, [clearSession])

  const login = useCallback(async (email: string, password: string) => {
    const response = await loginRequest({ email, password })
    queryClient.clear()
    markSessionActive()
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

  const updateCurrentUser = useCallback((nextUser: AuthUser) => {
    setUser(nextUser)
  }, [])

  const primaryRole = resolvePrimaryRole(user?.roles)

  const value = useMemo<AuthContextValue>(
    () => ({
      user,
      isAuthenticated: Boolean(user && primaryRole),
      isLoading,
      primaryRole,
      login,
      logout,
      updateCurrentUser,
      hasRole: (roles) => hasAnyRole(user?.roles, roles),
    }),
    [isLoading, login, logout, primaryRole, updateCurrentUser, user],
  )

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}
