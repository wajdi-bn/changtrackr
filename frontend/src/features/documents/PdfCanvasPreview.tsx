import { useEffect, useRef, useState } from 'react'
import { Alert, Button, Skeleton } from 'antd'
import { ChevronLeft, ChevronRight, ZoomIn, ZoomOut } from 'lucide-react'
import { getDocument, GlobalWorkerOptions, type PDFDocumentProxy } from 'pdfjs-dist'
import PdfWorker from 'pdfjs-dist/build/pdf.worker.min.mjs?worker'

GlobalWorkerOptions.workerPort ??= new PdfWorker()

export function PdfCanvasPreview({ blob }: { blob: Blob }) {
  const canvasRef = useRef<HTMLCanvasElement>(null)
  const [pdf, setPdf] = useState<PDFDocumentProxy>()
  const [pageNumber, setPageNumber] = useState(1)
  const [scale, setScale] = useState(1.15)
  const [loading, setLoading] = useState(true)
  const [rendering, setRendering] = useState(false)
  const [failed, setFailed] = useState(false)

  useEffect(() => {
    let active = true
    let loadedDocument: PDFDocumentProxy | undefined
    setLoading(true)
    setFailed(false)
    setPageNumber(1)

    void blob.arrayBuffer()
      .then((data) => getDocument({ data }).promise)
      .then((document) => {
        loadedDocument = document
        if (active) setPdf(document)
      })
      .catch(() => active && setFailed(true))
      .finally(() => active && setLoading(false))

    return () => {
      active = false
      setPdf(undefined)
      if (loadedDocument) void loadedDocument.cleanup()
    }
  }, [blob])

  useEffect(() => {
    if (!pdf || !canvasRef.current) return
    let active = true
    setRendering(true)

    void pdf.getPage(pageNumber).then(async (page) => {
      if (!active || !canvasRef.current) return
      const viewport = page.getViewport({ scale })
      const canvas = canvasRef.current
      const context = canvas.getContext('2d')
      if (!context) throw new Error('Canvas is unavailable.')
      const pixelRatio = window.devicePixelRatio || 1
      canvas.width = Math.floor(viewport.width * pixelRatio)
      canvas.height = Math.floor(viewport.height * pixelRatio)
      canvas.style.width = `${Math.floor(viewport.width)}px`
      canvas.style.height = `${Math.floor(viewport.height)}px`
      await page.render({
        canvas,
        canvasContext: context,
        viewport,
        transform: pixelRatio === 1 ? undefined : [pixelRatio, 0, 0, pixelRatio, 0, 0],
      }).promise
    }).catch(() => active && setFailed(true))
      .finally(() => active && setRendering(false))

    return () => { active = false }
  }, [pageNumber, pdf, scale])

  if (loading) return <div className="document-preview-loading"><Skeleton active paragraph={{ rows: 8 }} /></div>
  if (failed || !pdf) return <Alert type="error" showIcon title="Unable to render this PDF" description="Download the original document or open it separately." />

  return (
    <div className="pdf-canvas-preview">
      <div className="pdf-canvas-toolbar">
        <div>
          <Button aria-label="Previous page" icon={<ChevronLeft size={15} />} disabled={pageNumber <= 1} onClick={() => setPageNumber((current) => current - 1)} />
          <span>Page <strong>{pageNumber}</strong> of {pdf.numPages}</span>
          <Button aria-label="Next page" icon={<ChevronRight size={15} />} disabled={pageNumber >= pdf.numPages} onClick={() => setPageNumber((current) => current + 1)} />
        </div>
        <div>
          <Button aria-label="Zoom out" icon={<ZoomOut size={15} />} disabled={scale <= 0.75} onClick={() => setScale((current) => Math.max(0.75, current - 0.2))} />
          <span>{Math.round(scale * 100)}%</span>
          <Button aria-label="Zoom in" icon={<ZoomIn size={15} />} disabled={scale >= 1.75} onClick={() => setScale((current) => Math.min(1.75, current + 0.2))} />
        </div>
      </div>
      <div className={`pdf-canvas-stage ${rendering ? 'rendering' : ''}`}>
        <canvas ref={canvasRef} />
      </div>
    </div>
  )
}
