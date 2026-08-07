import type { UserRole } from '../../types/auth'

const rolePriority: readonly UserRole[] = [
  'super_admin',
  'admin',
  'operator',
  'technician',
  'client',
]

export function resolvePrimaryRole(roles: readonly UserRole[] | null | undefined): UserRole | null {
  if (!roles) return null

  return rolePriority.find((role) => roles.includes(role)) ?? null
}

export function hasAnyRole(
  userRoles: readonly UserRole[] | null | undefined,
  allowedRoles: readonly UserRole[],
): boolean {
  return Boolean(userRoles?.some((role) => allowedRoles.includes(role)))
}
