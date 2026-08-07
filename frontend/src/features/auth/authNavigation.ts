import type { AuthUser } from '../../types/auth'
import { getRoleConfig } from './roleConfig'
import { resolvePrimaryRole } from './roleResolution'

export function getAuthenticatedEntryPath(user: AuthUser): string {
  const role = resolvePrimaryRole(user.roles)

  if (!role) {
    return '/login?oauth_error=invalid_role'
  }

  return user.onboarding.should_show
    ? '/welcome'
    : getRoleConfig(role).defaultPath
}
