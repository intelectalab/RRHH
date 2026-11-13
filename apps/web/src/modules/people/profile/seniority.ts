import type { Seniority } from './types'

/**
 * COL-3 · Texto de la antigüedad que calculó el servidor (regla 11: el navegador no la calcula, solo la
 * formatea): «2 años, 3 meses y 5 días», omitiendo las partes en cero.
 */
export function seniorityText(t: (k: string, v?: Record<string, string | number>) => string, s: Seniority): string {
  const parts = [
    s.years > 0 ? t(s.years === 1 ? 'ficha.years_one' : 'ficha.years', { n: s.years }) : null,
    s.months > 0 ? t(s.months === 1 ? 'ficha.months_one' : 'ficha.months', { n: s.months }) : null,
    s.days > 0 || (s.years === 0 && s.months === 0) ? t(s.days === 1 ? 'ficha.days_one' : 'ficha.days', { n: s.days }) : null,
  ].filter((p): p is string => p !== null)
  if (parts.length <= 1) return parts[0] ?? ''

  return t('ficha.join_last', { list: parts.slice(0, -1).join(', '), last: parts[parts.length - 1] ?? '' })
}
