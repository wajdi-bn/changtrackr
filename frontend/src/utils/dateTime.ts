export function deviceTimeZone(): string {
  return Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC'
}

export function formatDateTime(value: string | null, timeZone: string | null): string {
  if (!value) return 'Not recorded yet'

  return new Intl.DateTimeFormat(undefined, {
    dateStyle: 'medium',
    timeStyle: 'short',
    timeZone: timeZone ?? deviceTimeZone(),
  }).format(new Date(value))
}
