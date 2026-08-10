(function () {
  try {
    var mode = localStorage.getItem('chargetrackr_theme_mode')
    var systemDark = window.matchMedia('(prefers-color-scheme: dark)').matches
    var theme = mode === 'dark' || (mode !== 'light' && systemDark) ? 'dark' : 'light'
    document.documentElement.dataset.theme = theme
    document.documentElement.style.colorScheme = theme
  } catch {
    document.documentElement.dataset.theme = 'light'
  }
})()
