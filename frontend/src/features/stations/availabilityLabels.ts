export function availabilityReasonLabel(reason: string | null | undefined): string {
  if (!reason) return 'Manual status'
  return reason.replaceAll('_', ' ').replace(/^./, (letter) => letter.toUpperCase())
}
