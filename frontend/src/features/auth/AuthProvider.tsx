import { useCallback, useEffect, useMemo, useState } from 'react'
import type { ReactNode } from 'react'
import type { AuthUser } from '../../types/auth'
import { csrfCookieRequest, currentUserRequest, loginRequest, logoutRequest } from './authApi'
import { AuthContext } from './authContext'
import type { AuthContextValue } from './authContext'

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<AuthUser | null>(null)
  const [isLoading, setIsLoading] = useState(true)

  const clearSession = useCallback(() => {
    setUser(null)
  }, [])

  useEffect(() => {
    let mounted = true

    localStorage.removeItem('chargetrackr_access_token')
    localStorage.removeItem('chargetrackr_user')

    async function loadCurrentUser() {
      try {
        await csrfCookieRequest()
        const currentUser = await currentUserRequest()
        if (!mounted) {
          return
        }
        setUser(currentUser)
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
