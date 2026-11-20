/** UI-12 · Cálculos puros de los gráficos (escala, trazos, porciones y niveles), probados aparte. */
export type Series = { name: string; values: number[] }

/** Máximo «redondo» del eje (1, 2, 2.5, 5 × 10^n) para que las marcas del eje sean legibles. */
export function niceMax(values: number[]): number {
  const max = Math.max(0, ...values)
  if (max <= 0) return 1
  const exp = Math.pow(10, Math.floor(Math.log10(max)))
  for (const m of [1, 2, 2.5, 5, 10]) {
    if (max <= m * exp) return m * exp
  }
  return 10 * exp
}

export function linePath(values: number[], max: number, box: { x: number; y: number; w: number; h: number }): { d: string; points: Array<{ x: number; y: number }> } {
  const n = values.length
  const points = values.map((v, i) => ({
    x: box.x + (n < 2 ? box.w / 2 : (box.w * i) / (n - 1)),
    y: box.y + box.h * (1 - (max > 0 ? v / max : 0)),
  }))
  const d = points.map((p, i) => `${i === 0 ? 'M' : 'L'}${p.x.toFixed(1)},${p.y.toFixed(1)}`).join(' ')
  return { d, points }
}

/** Trazos SVG de una dona; una porción de 100 % se dibuja como dos medias para que el arco cierre. */
export function donutArcs(values: number[], outer: number, inner: number, c: { cx: number; cy: number }): string[] {
  const total = values.reduce((a, v) => a + Math.max(0, v), 0)
  if (total <= 0) return []
  const pt = (r: number, a: number) => `${(c.cx + r * Math.sin(a)).toFixed(2)},${(c.cy - r * Math.cos(a)).toFixed(2)}`
  const arc = (a0: number, a1: number) => {
    const large = a1 - a0 > Math.PI ? 1 : 0
    return `M${pt(outer, a0)} A${outer},${outer} 0 ${large} 1 ${pt(outer, a1)} L${pt(inner, a1)} A${inner},${inner} 0 ${large} 0 ${pt(inner, a0)} Z`
  }
  let a = 0
  return values.map((v) => {
    const span = (Math.max(0, v) / total) * Math.PI * 2
    const a0 = a
    a += span
    if (span >= Math.PI * 2 - 1e-9) return `${arc(0, Math.PI)} ${arc(Math.PI, Math.PI * 2 - 1e-6)}`
    return span <= 0 ? '' : arc(a0, a)
  })
}

/** Nivel 0–4 de la gama del mapa de calor (0 solo para el valor cero). */
export function heatLevel(value: number, max: number): number {
  if (value <= 0 || max <= 0) return 0
  return Math.min(4, Math.max(1, Math.ceil((value / max) * 4)))
}

/** Pasa una fecha AAAA-MM-DD n días (aritmética en UTC). */
export function addDaysIso(day: string, n: number): string {
  const d = new Date(`${day}T00:00:00Z`)
  d.setUTCDate(d.getUTCDate() + n)
  return d.toISOString().slice(0, 10)
}

/** Días entre dos fechas AAAA-MM-DD (b − a). */
export function daysBetween(a: string, b: string): number {
  return Math.round((Date.parse(`${b}T00:00:00Z`) - Date.parse(`${a}T00:00:00Z`)) / 86_400_000)
}
