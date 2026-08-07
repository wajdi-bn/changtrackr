import { useMutation } from '@tanstack/react-query'
import { App, Tour } from 'antd'
import type { TourProps } from 'antd'
import { useState } from 'react'
import { getApiErrorMessage } from '../../api/apiErrors'
import type { UserRole } from '../../types/auth'
import { useAuth } from '../auth/useAuth'
import { updateOnboarding } from './onboardingApi'

const primaryTips: Record<UserRole, { title: string; description: string }> = {
  super_admin: {
    title: 'Start with organizations',
    description: 'Provision and inspect tenant workspaces here. Operational data stays isolated inside each organization.',
  },
  admin: {
    title: 'Build your organization team',
    description: 'Invite operators and technicians before assigning daily network responsibilities.',
  },
  operator: {
    title: 'Check the station network',
    description: 'Begin each shift with connectivity, availability and connector state.',
  },
  technician: {
    title: 'Open your assigned alerts',
    description: 'Your queue keeps field work focused on the incidents assigned to you.',
  },
  client: {
    title: 'Find your next connector',
    description: 'Compare station availability and connector compatibility before starting a session.',
  },
}

export function WorkspaceCoach() {
  const { message } = App.useApp()
  const { user, primaryRole, updateCurrentUser } = useAuth()
  const [closedLocally, setClosedLocally] = useState(false)
  const progress = user?.onboarding.progress
  const shouldOpen = Boolean(
    user?.onboarding.completed
    && progress?.tour_completed === false
    && !closedLocally,
  )
  const saveMutation = useMutation({
    mutationFn: updateOnboarding,
    onSuccess: updateCurrentUser,
    onError: (error) => void message.error(getApiErrorMessage(error, 'The workspace tour state could not be saved.')),
  })

  if (!user || !primaryRole) return null

  const role = primaryRole

  function finishTour() {
    if (closedLocally) return
    setClosedLocally(true)
    saveMutation.mutate({
      action: 'progress',
      current_step: progress?.current_step ?? 2,
      completed_steps: progress?.completed_steps ?? ['welcome', 'first-win', 'ready'],
      tour_completed: true,
    })
  }

  const steps: TourProps['steps'] = [
    {
      title: primaryTips[role].title,
      description: primaryTips[role].description,
      target: () => document.querySelector('[data-tour="primary-action"]')?.closest('li') as HTMLElement,
      placement: 'right',
    },
    {
      title: 'Stay aware without leaving your task',
      description: 'Notifications bring you directly to the related alert, session, report or account event.',
      target: () => document.querySelector('[data-tour="notifications"]') as HTMLElement,
      placement: 'bottomRight',
    },
    {
      title: 'Your guide remains available',
      description: 'Open the account menu to review this guide, update your profile, change settings or get help.',
      target: () => document.querySelector('[data-tour="account-menu"]') as HTMLElement,
      placement: 'bottomRight',
    },
  ]

  return (
    <Tour
      open={shouldOpen}
      steps={steps}
      onClose={finishTour}
      onFinish={finishTour}
      mask={{ color: 'rgba(10, 32, 23, 0.42)' }}
    />
  )
}
