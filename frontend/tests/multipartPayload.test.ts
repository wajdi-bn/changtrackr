import assert from 'node:assert/strict'
import test from 'node:test'
import { File } from 'node:buffer'
import { buildAssetDocumentFormData, multipartRequestHeaders } from '../src/features/documents/multipartPayload.ts'

test('builds a multipart document payload without JSON serialization', () => {
  const file = new File(['report evidence'], 'evidence.pdf', { type: 'application/pdf' })
  const payload = buildAssetDocumentFormData({
    file,
    category: 'report_attachment',
    title: 'Evidence',
    description: 'Supporting diagnosis',
  })

  assert.equal(multipartRequestHeaders['Content-Type'], 'multipart/form-data')
  assert.equal(payload.get('file'), file)
  assert.equal(payload.get('category'), 'report_attachment')
  assert.equal(payload.get('title'), 'Evidence')
  assert.equal(payload.get('description'), 'Supporting diagnosis')
})
