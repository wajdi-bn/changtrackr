import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { MotionConfig } from 'framer-motion'
import 'leaflet/dist/leaflet.css'
import './index.css'
import App from './App'
import { installChunkLoadRecovery } from './app/chunkLoadRecovery'

installChunkLoadRecovery()

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <MotionConfig reducedMotion="user">
      <App />
    </MotionConfig>
  </StrictMode>,
)
