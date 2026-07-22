import { Button, Dropdown } from 'antd'
import { Braces, ChevronDown, FileSpreadsheet, FileText } from 'lucide-react'

export type ExportFormat = 'csv' | 'json' | 'pdf'

interface ExportDropdownProps {
  loading?: boolean
  className?: string
  label?: string
  onExport: (format: ExportFormat) => void
}

export function ExportDropdown({ loading = false, className, label = 'Export', onExport }: ExportDropdownProps) {
  return <Dropdown menu={{
    items: [
      { key: 'csv', icon: <FileSpreadsheet size={15} />, label: 'Export CSV' },
      { key: 'json', icon: <Braces size={15} />, label: 'Export JSON' },
      { key: 'pdf', icon: <FileText size={15} />, label: 'Export PDF' },
    ],
    onClick: ({ key }) => onExport(key as ExportFormat),
  }}>
    <Button className={className} loading={loading}><FileText size={14} />{label}<ChevronDown size={13} /></Button>
  </Dropdown>
}
