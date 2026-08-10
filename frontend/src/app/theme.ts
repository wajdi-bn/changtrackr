import { theme as antdThemeEngine, type ThemeConfig } from 'antd'
import type { ResolvedTheme } from '../features/theme/themePreference'

export function createAntdTheme(resolvedTheme: ResolvedTheme): ThemeConfig {
  const isDark = resolvedTheme === 'dark'

  return {
    algorithm: isDark ? antdThemeEngine.darkAlgorithm : antdThemeEngine.defaultAlgorithm,
    token: {
      colorPrimary: isDark ? '#27b979' : '#159a63',
      colorSuccess: '#10b981',
      colorWarning: '#f59e0b',
      colorError: '#ef4444',
      colorInfo: isDark ? '#54a985' : '#16845a',
      colorBgBase: isDark ? '#0b1410' : '#ffffff',
      colorBgLayout: isDark ? '#09110d' : '#f3f8ff',
      colorBgContainer: isDark ? '#131e18' : '#ffffff',
      colorBgElevated: isDark ? '#18251e' : '#ffffff',
      colorText: isDark ? '#f1f6f3' : '#17251f',
      colorTextSecondary: isDark ? '#a5b5ad' : '#687970',
      colorBorder: isDark ? '#34483d' : '#dfe8e3',
      colorBorderSecondary: isDark ? '#293a31' : '#e8efeb',
      borderRadius: 12,
      fontSize: 15,
      fontSizeSM: 13,
      fontSizeLG: 17,
      lineHeight: 1.5,
      fontFamily:
        'Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif',
    },
    components: {
      Button: {
        borderRadius: 10,
        controlHeight: 40,
      },
      Card: {
        borderRadiusLG: 16,
      },
      Layout: {
        bodyBg: isDark ? '#09110d' : '#f6f8fb',
        headerBg: isDark ? '#101a15' : '#ffffff',
        siderBg: '#159a63',
      },
    },
  }
}
