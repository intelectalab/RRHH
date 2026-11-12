/** Formato de fechas para mostrar (el cálculo de plazos y vencimientos lo hace la API). */

export function formatDate(locale: string, iso: string | null | undefined): string {
  if (!iso) return ''
  // Fechas laborales (YYYY-MM-DD) son días sin hora: se muestran tal cual, sin zona.
  const d = /^\d{4}-\d{2}-\d{2}$/.test(iso) ? new Date(`${iso}T12:00:00`) : new Date(iso)
  return new Intl.DateTimeFormat(locale, { dateStyle: 'medium' }).format(d)
}

/**
 * Fecha y hora (guardadas en UTC) en la zona indicada, que en la aplicación es la de la empresa
 * (CFG-2). Una zona inválida no rompe la pantalla: se usa la del navegador.
 */
export function formatDateTime(locale: string, iso: string | null | undefined, timeZone?: string | null, seconds = false): string {
  if (!iso) return ''
  const options: Intl.DateTimeFormatOptions = { dateStyle: 'medium', timeStyle: seconds ? 'medium' : 'short' }
  try {
    return new Intl.DateTimeFormat(locale, { ...options, ...(timeZone ? { timeZone } : {}) }).format(new Date(iso))
  } catch {
    return new Intl.DateTimeFormat(locale, options).format(new Date(iso))
  }
}

/** «hace 3 días», «en 2 horas». */
export function relativeTime(locale: string, iso: string | null | undefined, now: Date = new Date()): string {
  if (!iso) return ''
  const d = /^\d{4}-\d{2}-\d{2}$/.test(iso) ? new Date(`${iso}T12:00:00`) : new Date(iso)
  const diff = (d.getTime() - now.getTime()) / 1000
  const rtf = new Intl.RelativeTimeFormat(locale, { numeric: 'auto' })
  const abs = Math.abs(diff)
  if (abs < 60) return rtf.format(Math.round(diff), 'second')
  if (abs < 3600) return rtf.format(Math.round(diff / 60), 'minute')
  if (abs < 86400) return rtf.format(Math.round(diff / 3600), 'hour')
  if (abs < 86400 * 30) return rtf.format(Math.round(diff / 86400), 'day')
  if (abs < 86400 * 365) return rtf.format(Math.round(diff / (86400 * 30)), 'month')
  return rtf.format(Math.round(diff / (86400 * 365)), 'year')
}

export function initials(name: string | null | undefined): string {
  return (name ?? '').split(/\s+/).filter(Boolean).slice(0, 2).map((p) => p[0]?.toUpperCase() ?? '').join('')
}

/**
 * Cantidad decimal que llega como texto («1250.5») con separadores del idioma, sin pasar por números de
 * coma flotante (los montos y consumos se calculan en la API).
 */
export function formatNumber(locale: string, value: string): string {
  const m = /^(-?)(\d+)(?:\.(\d+))?$/.exec(value.trim())
  if (!m) return value
  const fmt = new Intl.NumberFormat(locale)
  const int = fmt.format(BigInt(m[2] ?? '0'))
  if (!m[3]) return `${m[1]}${int}`
  const sep = fmt.formatToParts(1.5).find((p) => p.type === 'decimal')?.value ?? '.'
  return `${m[1]}${int}${sep}${m[3]}`
}

/** Fecha de hoy (AAAA-MM-DD) en la hora local del navegador, no en UTC. */
export function localToday(now: Date = new Date()): string {
  const p = (n: number) => String(n).padStart(2, '0')
  return `${now.getFullYear()}-${p(now.getMonth() + 1)}-${p(now.getDate())}`
}
