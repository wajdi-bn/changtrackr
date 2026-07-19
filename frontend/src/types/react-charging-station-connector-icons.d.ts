declare module 'react-charging-station-connector-icons' {
  import type { FunctionComponent, SVGAttributes } from 'react'

  type IconProps = {
    variant: 'solid' | 'light'
    subtitled: boolean
  }

  export const Chademo: FunctionComponent<IconProps & SVGAttributes<SVGElement>>
  export const IEC62196T2: FunctionComponent<IconProps & SVGAttributes<SVGElement>>
  export const IEC62196T2Combo: FunctionComponent<IconProps & SVGAttributes<SVGElement>>
}
