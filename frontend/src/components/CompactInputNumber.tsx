import { Button, InputNumber, Space, type InputNumberProps } from 'antd'
import type { ReactNode } from 'react'

interface CompactInputNumberProps extends InputNumberProps {
  addon: ReactNode
}

export function CompactInputNumber({ addon, className, style, ...inputProps }: CompactInputNumberProps) {
  return (
    <Space.Compact block className={`compact-number-input ${className ?? ''}`} style={style}>
      <InputNumber {...inputProps} style={{ width: '100%' }} />
      <Button className="compact-number-addon" disabled>{addon}</Button>
    </Space.Compact>
  )
}
