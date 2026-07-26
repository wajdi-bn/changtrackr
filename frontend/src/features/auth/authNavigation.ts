import type { AuthUser } from '../../types/auth'
import { getRoleConfig } from './roleConfig'

export function getAuthenticatedEntryPath(user: AuthUser): string {
  return user.onboarding.should_show
    ? '/welcome'
    : getRoleConfig(user.roles[0] ?? null).defaultPath
}
