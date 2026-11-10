/** Formato de fechas para mostrar (el cálculo de plazos y vencimientos lo hace la API). */

export function formatDate(locale: string, iso: string | null | undefined): string {
  if (!iso) return ''
  // Fechas laborales (YYYY-MM-DD) son días sin hora: se muestran tal cual, sin zona.
  const d = /^\d{4}-\d{2}-\d{2}$/.test(iso) ? new Date(`${iso}T12:00:00`) : new Date(iso)
  return new Intl.DateTimeFormat(locale, { dateStyle: 'medium' }).format(d)
}

export function formatDateTime(locale: string, iso: string | null | undefined): string {
  if (!iso) return ''
  return new Intl.DateTimeFormat(locale, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(iso))
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
