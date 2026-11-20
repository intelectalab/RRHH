/** UI-11 · Formatos que ofrece el servicio de exportación y utilidades sin estado de la pantalla. */
export const EXPORT_FORMATS = ['csv', 'xlsx', 'pdf'] as const
export type ExportFormat = (typeof EXPORT_FORMATS)[number]

export type ExportStatus = 'queued' | 'running' | 'completed' | 'failed' | 'expired'

/** Tono del estado para la etiqueta (ok, aviso, error, gris). */
export function exportTone(status: string, expiresAt: string | null | undefined, now: Date = new Date()): 'ok' | 'wa' | 'er' | 'gr' {
  if (status === 'failed') return 'er'
  if (status === 'queued' || status === 'running') return 'wa'
  if (status === 'expired' || (expiresAt && new Date(expiresAt) < now)) return 'gr'
  return 'ok'
}

/** Se puede descargar solo lo terminado y vigente (la API vuelve a validarlo: 409). */
export function canDownload(status: string, expiresAt: string | null | undefined, now: Date = new Date()): boolean {
  return status === 'completed' && !(expiresAt && new Date(expiresAt) < now)
}

/** Mientras haya alguna en cola, la pantalla vuelve a preguntar (sin bloquear nada). */
export function hasPending(jobs: Array<{ status: string }>): boolean {
  return jobs.some((j) => j.status === 'queued' || j.status === 'running')
}

export function saveBlob(blob: Blob, filename: string): void {
  const href = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = href
  a.download = filename
  document.body.appendChild(a)
  a.click()
  a.remove()
  URL.revokeObjectURL(href)
}
