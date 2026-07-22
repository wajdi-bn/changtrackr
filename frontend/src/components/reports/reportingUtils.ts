export const REPORT_COLORS = ['#139a61', '#7c3aed', '#0b83c9', '#ef8b19', '#dc4b4b', '#64748b']

export function formatMoney(millimes: number): string {
  return `TND ${(millimes / 1000).toLocaleString(undefined, { minimumFractionDigits: 3, maximumFractionDigits: 3 })}`
}
export function humanize(value: string): string {
  return value.replaceAll('_', ' ').replaceAll('-', ' ').replace(/^./, (letter) => letter.toUpperCase())
}
