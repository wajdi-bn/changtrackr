import { useCallback, useEffect, useMemo, useState } from 'react'
import type { ReactNode } from 'react'
import { authTokenStorageKey } from '../../api/httpClient'
import type { AuthUser } from '../../types/auth'
import { currentUserRequest, loginRequest, logoutRequest } from './authApi'
import { AuthContext } from './authContext'
import type { AuthContextValue } from './authContext'

const userStorageKey = 'chargetrackr_user'

function readStoredUser(): AuthUser | null {
  const storedUser = localStorage.getItem(userStorageKey)

  if (!storedUser) {
    return null
  }

  try {
    return JSON.parse(storedUser) as AuthUser
  } catch {
    localStorage.removeItem(userStorageKey)
    return null
  }
}

export function AuthProvider({ children }: { children: ReactNode }) {
  const [token, setToken] = useState<string | null>(() =>
    localStorage.getItem(authTokenStorageKey),
  )
  const [user, setUser] = useState<AuthUser | null>(() => readStoredUser())
  const [isLoading, setIsLoading] = useState(true)

  const clearSession = useCallback(() => {
    localStorage.removeItem(authTokenStorageKey)
    localStorage.removeItem(userStorageKey)
    setToken(null)
    setUser(null)
  }, [])

  useEffect(() => {
    let mounted = true

    async function loadCurrentUser() {
      if (!token) {
        setIsLoading(false)
        return
      }

      try {
        const currentUser = await currentUserRequest()
        if (!mounted) {
          return
        }
        localStorage.setItem(userStorageKey, JSON.stringify(currentUser))
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
  }, [clearSession, token])

  const login = useCallback(async (email: string, password: string) => {
    const response = await loginRequest({ email, password })
    localStorage.setItem(authTokenStorageKey, response.access_token)
    localStorage.setItem(userStorageKey, JSON.stringify(response.user))
    setToken(response.access_token)
    setUser(response.user)
    return response.user
  }, [])

  const logout = useCallback(async () => {
    try {
      if (token) {
        await logoutRequest()
      }
    } finally {
      clearSession()
    }
  }, [clearSession, token])

  const primaryRole = user?.roles[0] ?? null

  const value = useMemo<AuthContextValue>(
    () => ({
      user,
      token,
      isAuthenticated: Boolean(token && user),
      isLoading,
      primaryRole,
      login,
      logout,
      hasRole: (roles) => Boolean(primaryRole && roles.includes(primaryRole)),
    }),
    [isLoading, login, logout, primaryRole, token, user],
  )

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}
