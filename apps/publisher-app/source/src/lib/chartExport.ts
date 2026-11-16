/**
 * Exports a rendered Recharts <svg> as a downloadable PNG — so the
 * "ডাউনলোড" button on an engagement chart gives someone a real image file
 * they can save or share, not just a screenshot workaround. Pure browser
 * APIs (SVG -> Image -> canvas -> PNG data URL), no added dependency.
 */
export async function downloadChartAsPng(
  container: HTMLElement | null,
  filename: string,
  background = '#ffffff'
) {
  const svg = container?.querySelector('svg')
  if (!svg) return

  const rect = svg.getBoundingClientRect()
  const width = Math.max(1, Math.round(rect.width))
  const height = Math.max(1, Math.round(rect.height))

  const clone = svg.cloneNode(true) as SVGSVGElement
  clone.setAttribute('xmlns', 'http://www.w3.org/2000/svg')
  clone.setAttribute('width', String(width))
  clone.setAttribute('height', String(height))

  const svgString = new XMLSerializer().serializeToString(clone)
  const svgBlob = new Blob([svgString], { type: 'image/svg+xml;charset=utf-8' })
  const url = URL.createObjectURL(svgBlob)

  try {
    const img = new Image()
    const loaded = new Promise<void>((resolve, reject) => {
      img.onload = () => resolve()
      img.onerror = () => reject(new Error('svg-load-failed'))
    })
    img.src = url
    await loaded

    const scale = 2 // a sharper-than-screen export
    const canvas = document.createElement('canvas')
    canvas.width = width * scale
    canvas.height = height * scale

    const ctx = canvas.getContext('2d')
    if (!ctx) return
    ctx.scale(scale, scale)
    ctx.fillStyle = background
    ctx.fillRect(0, 0, width, height)
    ctx.drawImage(img, 0, 0, width, height)

    const link = document.createElement('a')
    link.href = canvas.toDataURL('image/png')
    link.download = filename
    link.click()
  } finally {
    URL.revokeObjectURL(url)
  }
}
