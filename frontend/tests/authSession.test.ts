import assert from 'node:assert/strict'
import test from 'node:test'
import {
  markSessionActive,
  notifySessionExpired,
  subscribeToSessionExpiration,
} from '../src/features/auth/authSession.ts'

test('notifies each subscriber only once until a new session becomes active', () => {
  let notifications = 0
  const unsubscribe = subscribeToSessionExpiration(() => {
    notifications += 1
  })

  markSessionActive()
  notifySessionExpired()
  notifySessionExpired()
  assert.equal(notifications, 1)

  markSessionActive()
  notifySessionExpired()
  assert.equal(notifications, 2)
  unsubscribe()
})

test('stops notifying an unsubscribed listener', () => {
  markSessionActive()
  let notifications = 0
  const unsubscribe = subscribeToSessionExpiration(() => {
    notifications += 1
  })
  unsubscribe()

  notifySessionExpired()
  assert.equal(notifications, 0)
})
