'use client'

import { formatNumber } from '@/lib/format'
import { useT } from '@/lib/useT'
import { StatusBadge, type StatusTone } from '@/ui/components/Status'

/** SAS-6 · Consumo de una métrica (la API ya calcula porcentaje y estado: el navegador solo los muestra). */
export type UsageRow = {
  metric: string
  current: string
  limit: string | null
  unlimited: boolean
  has_limit: boolean
  percent: number | null
  status: 'ok' | 'warning' | 'reached' | 'over'
}

export function usageTone(status: UsageRow['status']): StatusTone {
  if (status === 'over' || status === 'reached') return 'er'
  if (status === 'warning') return 'wa'
  return 'ok'
}

/** Nombre visible de una métrica conocida; las de un límite propio muestran su código. */
export function useMetricLabel(): (metric: string) => string {
  const { t } = useT()
  return (metric) => {
    const key = `usage.metric.${metric}`
    const label = t(key)
    return label === key ? metric : label
  }
}

/**
 * Tabla de consumo frente a límites, para el cliente (Configuración → Consumo del plan) y la consola.
 * La barra acompaña al texto: el estado siempre se lee en palabras, no solo por color.
 */
export function UsageTable({ rows }: { rows: UsageRow[] }) {
  const { t, locale } = useT()
  const label = useMetricLabel()
  const num = (v: string) => formatNumber(locale, v)

  return (
    <div className="tabla-wrap">
      <table className="table uso-tabla">
        <thead>
          <tr>
            <th scope="col">{t('usage.col_metric')}</th>
            <th scope="col">{t('usage.col_used')}</th>
            <th scope="col">{t('usage.col_limit')}</th>
            <th scope="col">{t('usage.col_status')}</th>
          </tr>
        </thead>
        <tbody>
          {rows.map((r) => (
            <tr key={r.metric}>
              <td data-label={t('usage.col_metric')}><b>{label(r.metric)}</b></td>
              <td data-label={t('usage.col_used')}>
                {num(r.current)}
                {r.percent !== null && (
                  <span className="uso-barra" aria-hidden="true">
                    <span className={`uso-relleno ${usageTone(r.status)}`} style={{ width: `${Math.min(100, Math.max(0, r.percent))}%` }} />
                  </span>
                )}
              </td>
              <td data-label={t('usage.col_limit')}>{r.limit !== null ? num(r.limit) : t(r.has_limit ? 'usage.unlimited' : 'usage.no_limit')}</td>
              <td data-label={t('usage.col_status')}>
                {r.limit !== null
                  ? <StatusBadge tone={usageTone(r.status)}>{t(`usage.status.${r.status}`, { percent: r.percent ?? 0 })}</StatusBadge>
                  : <span className="text-secondary">{t('usage.status.free')}</span>}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}
