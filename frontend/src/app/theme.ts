import type { ThemeConfig } from 'antd'

export const antdTheme: ThemeConfig = {
  token: {
    colorPrimary: '#159a63',
    colorSuccess: '#10b981',
    colorWarning: '#f59e0b',
    colorError: '#ef4444',
    colorInfo: '#6366f1',
    borderRadius: 12,
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
      bodyBg: '#f6f8fb',
      headerBg: '#ffffff',
      siderBg: '#159a63',
    },
  },
}
