import { ConfigProvider } from 'antd'
import { useEffect, useMemo, useState, type ReactNode } from 'react'
import { createAntdTheme } from '../../app/theme'
import { ThemeContext } from './themeContext'
import {
  normalizeThemeMode,
  resolveThemeMode,
  THEME_STORAGE_KEY,
  type ThemeMode,
} from './themePreference'

function getStoredMode(): ThemeMode {
  try {
    return normalizeThemeMode(window.localStorage.getItem(THEME_STORAGE_KEY))
  } catch {
    return 'system'
  }
}

function getSystemPreference() {
  return window.matchMedia?.('(prefers-color-scheme: dark)').matches ?? false
}

export function ThemeProvider({ children }: { children: ReactNode }) {
  const [mode, setMode] = useState<ThemeMode>(getStoredMode)
  const [prefersDark, setPrefersDark] = useState(getSystemPreference)
  const resolvedTheme = resolveThemeMode(mode, prefersDark)

  useEffect(() => {
    const mediaQuery = window.matchMedia('(prefers-color-scheme: dark)')
    const handleChange = (event: MediaQueryListEvent) => setPrefersDark(event.matches)
    mediaQuery.addEventListener('change', handleChange)
    return () => mediaQuery.removeEventListener('change', handleChange)
  }, [])

  useEffect(() => {
    try {
      window.localStorage.setItem(THEME_STORAGE_KEY, mode)
    } catch {
      // The selected theme still applies when browser storage is unavailable.
    }
    document.documentElement.dataset.theme = resolvedTheme
    document.documentElement.style.colorScheme = resolvedTheme
  }, [mode, resolvedTheme])

  const contextValue = useMemo(
    () => ({ mode, resolvedTheme, setMode }),
    [mode, resolvedTheme],
  )

  return (
    <ThemeContext.Provider value={contextValue}>
      <ConfigProvider theme={createAntdTheme(resolvedTheme)}>{children}</ConfigProvider>
    </ThemeContext.Provider>
  )
}
