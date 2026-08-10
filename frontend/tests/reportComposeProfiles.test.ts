import assert from 'node:assert/strict'
import test from 'node:test'
import { reportComposeProfiles } from '../src/components/reports/reportComposeProfiles.ts'

test('provides distinct decision, operations and field report templates', () => {
  assert.deepEqual(reportComposeProfiles.admin.templates.map((template) => template.key), [
    'performance-review',
    'sla-escalation',
    'capacity-plan',
  ])
  assert.deepEqual(reportComposeProfiles.operator.templates.map((template) => template.key), [
    'shift-handover',
    'incident-escalation',
    'availability-watch',
  ])
  assert.deepEqual(reportComposeProfiles.technician.templates.map((template) => template.key), [
    'diagnosis',
    'maintenance-completion',
    'field-blocker',
  ])
})

test('every compose template produces a valid report body and category', () => {
  for (const profile of Object.values(reportComposeProfiles)) {
    assert.ok(profile.categories.length > 0)
    for (const template of profile.templates) {
      assert.ok(profile.categories.includes(template.category))
      assert.ok(template.title.length >= 3)
      assert.ok(template.summary.length > 10)
      assert.ok(template.body.split('\n\n').length >= 4)
    }
  }
})
