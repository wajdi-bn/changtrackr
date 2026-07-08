import { ConfigProvider } from 'antd'
import { QueryClientProvider } from '@tanstack/react-query'
import { BrowserRouter } from 'react-router-dom'
import { AppRouter } from './routes/AppRouter'
import { queryClient } from './app/queryClient'
import { antdTheme } from './app/theme'

function App() {
  return (
    <ConfigProvider theme={antdTheme}>
      <QueryClientProvider client={queryClient}>
        <BrowserRouter>
          <AppRouter />
        </BrowserRouter>
      </QueryClientProvider>
    </ConfigProvider>
  )
}

export default App
