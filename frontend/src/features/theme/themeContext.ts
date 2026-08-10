import { createContext } from 'react'
import type { ResolvedTheme, ThemeMode } from './themePreference'

export type ThemeContextValue = {
  mode: ThemeMode
  resolvedTheme: ResolvedTheme
  setMode: (mode: ThemeMode) => void
}

export const ThemeContext = createContext<ThemeContextValue | null>(null)
