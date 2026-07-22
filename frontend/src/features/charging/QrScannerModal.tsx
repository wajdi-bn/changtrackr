import { useEffect, useRef, useState } from 'react'
import { BrowserQRCodeReader, type IScannerControls } from '@zxing/browser'
import { Alert, Button, Form, Input, Modal, Spin } from 'antd'
import { Camera, QrCode } from 'lucide-react'

interface QrScannerModalProps {
  open: boolean
  onClose: () => void
  onScan: (token: string) => void
}

export function QrScannerModal({ open, onClose, onScan }: QrScannerModalProps) {
  const videoRef = useRef<HTMLVideoElement | null>(null)
  const controlsRef = useRef<IScannerControls | null>(null)
  const onScanRef = useRef(onScan)
  const [cameraError, setCameraError] = useState<string | null>(null)
  const [starting, setStarting] = useState(false)

  useEffect(() => {
    onScanRef.current = onScan
  }, [onScan])

  useEffect(() => {
    if (!open) return undefined

    let active = true
    const reader = new BrowserQRCodeReader()
    setCameraError(null)
    setStarting(true)

    void reader.decodeFromConstraints(
      { video: { facingMode: { ideal: 'environment' } } },
      videoRef.current ?? undefined,
      (result) => {
        if (!active || !result) return
        const token = qrTokenFromValue(result.getText())
        if (!token) {
          setCameraError('This is not a ChargeTrackr connector QR code.')
          return
        }

        active = false
        controlsRef.current?.stop()
        onScanRef.current(token)
      },
    ).then((controls) => {
      if (!active) {
        controls.stop()
        return
      }
      controlsRef.current = controls
      setStarting(false)
    }).catch(() => {
      if (active) {
        setCameraError('Camera access was not available. Check the browser permission or enter the link manually.')
        setStarting(false)
      }
    })

    return () => {
      active = false
      controlsRef.current?.stop()
      controlsRef.current = null
    }
  }, [open])

  return <Modal open={open} title="Scan connector QR code" footer={null} onCancel={onClose} destroyOnHidden>
    <div className="qr-scanner-modal">
      <div className="qr-scanner-preview">
        <video ref={videoRef} muted playsInline />
        {starting && <div className="qr-scanner-loading"><Spin /><span>Opening camera</span></div>}
      </div>
      <p><Camera size={16} />Point your camera at the QR label attached to the connector.</p>
      {cameraError && <Alert type="warning" showIcon title={cameraError} />}
      <Form layout="vertical" onFinish={({ qrLink }: { qrLink: string }) => {
        const token = qrTokenFromValue(qrLink)
        if (token) onScan(token)
        else setCameraError('Enter a valid ChargeTrackr connector link or QR token.')
      }}>
        <Form.Item label="Use a link or QR token instead" name="qrLink" rules={[{ required: true, message: 'Paste the connector link or QR token.' }]}>
          <Input prefix={<QrCode size={15} />} placeholder="https://.../charge/scan/..." />
        </Form.Item>
        <Button htmlType="submit" block>Continue with this connector</Button>
      </Form>
    </div>
  </Modal>
}

function qrTokenFromValue(value: string): string | null {
  const trimmed = value.trim()
  if (/^[0-9a-f]{8}-[0-9a-f-]{27}$/i.test(trimmed)) return trimmed

  try {
    const url = new URL(trimmed, window.location.origin)
    return url.pathname.match(/^\/charge\/scan\/([0-9a-f-]{36})$/i)?.[1] ?? null
  } catch {
    return null
  }
}
