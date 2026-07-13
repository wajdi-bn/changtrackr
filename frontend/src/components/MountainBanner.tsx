type MountainBannerColor = 'pink' | 'orange' | 'green' | 'blue' | 'cyan' | 'purple' | 'gold'

interface MountainBannerProps {
  color: MountainBannerColor
  breadcrumb?: string[]
  title: string
  count?: number
  subtitle?: string
}

export function MountainBanner({ color, breadcrumb, title, count, subtitle }: MountainBannerProps) {
  return (
    <section className={`mountain-banner mountain-banner--${color}`}>
      <svg
        aria-hidden="true"
        className="mountain-banner__layers"
        viewBox="0 0 800 120"
        preserveAspectRatio="none"
      >
        <path
          d="M0,120 Q50,70 100,90 T200,80 T300,95 T400,75 T500,90 T600,80 T700,95 T800,85 V120 H0 Z"
          fill="white"
          fillOpacity="0.15"
        />
        <path
          d="M0,120 Q60,85 120,100 T240,80 T360,95 T480,78 T600,92 T720,82 T800,95 V120 H0 Z"
          fill="white"
          fillOpacity="0.25"
        />
        <path
          d="M0,120 Q80,95 160,110 T320,88 T480,105 T640,90 T800,100 V120 H0 Z"
          fill="white"
          fillOpacity="0.4"
        />
      </svg>

      <div className="mountain-banner__content">
        {breadcrumb && breadcrumb.length > 0 && (
          <div className="mountain-banner__breadcrumb">
            {breadcrumb.map((item, index) => (
              <span key={item}>
                <span className={index === breadcrumb.length - 1 ? 'current' : undefined}>
                  {index === 0 && <i aria-hidden="true" />}
                  {item}
                </span>
                {index < breadcrumb.length - 1 && <b aria-hidden="true">/</b>}
              </span>
            ))}
          </div>
        )}
        <h1>
          {title}
          {count !== undefined && <span>{count}</span>}
        </h1>
        {subtitle && <p>{subtitle}</p>}
      </div>
    </section>
  )
}
