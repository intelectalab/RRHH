'use client'

import { useT } from '@/lib/useT'

const WIDTHS = ['92%', '78%', '64%', '86%', '70%']

/**
 * UI-3 · Esqueletos de carga: reservan el espacio del contenido para que la pantalla no salte. Las
 * barras son decorativas (aria-hidden); el contenedor anuncia «Cargando…» a los lectores de pantalla.
 */
export function SkeletonText({ lines = 3 }: { lines?: number }) {
  const { t } = useT()
  return (
    <div className="esq-grupo" role="status" aria-busy="true" aria-label={t('ui.loading')}>
      {Array.from({ length: lines }, (_, i) => <span key={i} className="esq" style={{ width: WIDTHS[i % WIDTHS.length] }} aria-hidden="true" />)}
    </div>
  )
}

/** Filas de una lista o tabla en carga: avatar + dos líneas. */
export function SkeletonRows({ rows = 4 }: { rows?: number }) {
  const { t } = useT()
  return (
    <ul className="esq-filas" role="status" aria-busy="true" aria-label={t('ui.loading')}>
      {Array.from({ length: rows }, (_, i) => (
        <li key={i} aria-hidden="true">
          <span className="esq esq-av" />
          <span className="esq-col"><span className="esq" style={{ width: '40%' }} /><span className="esq" style={{ width: '24%' }} /></span>
        </li>
      ))}
    </ul>
  )
}

/** Bloque rectangular (gráfico, tarjeta, imagen). */
export function SkeletonBlock({ height = 120 }: { height?: number }) {
  const { t } = useT()
  return <span className="esq esq-bloque" style={{ height }} role="status" aria-busy="true" aria-label={t('ui.loading')} />
}
