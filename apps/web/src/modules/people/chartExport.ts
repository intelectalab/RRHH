import { dataUrlBytes, imagePdf } from '@/lib/pdf'
import { chartSvg, type Layout, type Palette } from './orgChartLayout'

/** Lado mayor máximo del lienzo: los navegadores no dibujan lienzos más grandes. */
const MAX_CANVAS = 8000

function save(blob: Blob, filename: string) {
  const href = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = href
  a.download = filename
  document.body.appendChild(a)
  a.click()
  a.remove()
  setTimeout(() => URL.revokeObjectURL(href), 1000)
}

/**
 * Colores del tema claro tomados de los tokens (.tema-claro): la imagen y el PDF salen claros aunque
 * la pantalla esté en modo oscuro, y no hay colores escritos a mano fuera de tokens.css.
 */
function lightPalette(): Palette {
  const probe = document.createElement('div')
  probe.className = 'tema-claro'
  probe.style.display = 'none'
  document.body.appendChild(probe)
  const css = getComputedStyle(probe)
  const v = (name: string) => css.getPropertyValue(name).trim()
  const palette: Palette = {
    bg: v('--sup'), box: v('--sup'), border: v('--bd'), title: v('--pe-700') || v('--tx'),
    text: v('--tx'), muted: v('--tx2'), line: v('--bd2'), accent: v('--marca-hr'),
  }
  probe.remove()
  return palette
}

async function toCanvas(layout: Layout, heading: string): Promise<HTMLCanvasElement> {
  const palette = lightPalette()
  const svg = chartSvg(layout, palette, heading)
  const img = new Image()
  const url = URL.createObjectURL(new Blob([svg], { type: 'image/svg+xml' }))
  try {
    await new Promise<void>((resolve, reject) => {
      img.onload = () => resolve()
      img.onerror = () => reject(new Error('svg'))
      img.src = url
    })
    const w = img.width
    const h = img.height
    const ratio = Math.min(2, MAX_CANVAS / Math.max(w, h))
    const canvas = document.createElement('canvas')
    canvas.width = Math.max(1, Math.round(w * ratio))
    canvas.height = Math.max(1, Math.round(h * ratio))
    const ctx = canvas.getContext('2d')
    if (!ctx) throw new Error('canvas')
    ctx.fillStyle = palette.bg
    ctx.fillRect(0, 0, canvas.width, canvas.height)
    ctx.drawImage(img, 0, 0, canvas.width, canvas.height)
    return canvas
  } finally {
    URL.revokeObjectURL(url)
  }
}

/** ORG-6 · Descarga el organigrama visible (con sus ramas plegadas o abiertas) como PNG o PDF. */
export async function exportChart(layout: Layout, kind: 'png' | 'pdf', heading: string, filename: string): Promise<void> {
  const canvas = await toCanvas(layout, heading)
  if (kind === 'png') {
    const blob = await new Promise<Blob | null>((resolve) => canvas.toBlob(resolve, 'image/png'))
    if (!blob) throw new Error('png')
    save(blob, `${filename}.png`)
    return
  }
  const pdf = imagePdf(dataUrlBytes(canvas.toDataURL('image/jpeg', 0.92)), canvas.width, canvas.height)
  save(new Blob([pdf as BlobPart], { type: 'application/pdf' }), `${filename}.pdf`)
}
