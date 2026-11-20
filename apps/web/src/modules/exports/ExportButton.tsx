'use client'

import { useState } from 'react'
import { api, ApiRequestError, type Schema } from '@/lib/api'
import { useT } from '@/lib/useT'
import { useSession } from '@/modules/session/SessionProvider'
import { MenuButton } from '@/ui/components/MenuButton'
import { EXPORT_FORMATS, saveBlob, type ExportFormat } from './exports'

type ExportJob = Schema<'ExportJob'>

/**
 * SEG-12 · UI-11 · Exportar el listado visible a CSV, Excel o PDF. Solo aparece con reports.exports.create;
 * la API vuelve a validar el permiso, el alcance y los campos sensibles, y registra la exportación.
 * Una exportación pequeña se descarga al instante; una grande queda en cola (no bloquea la pantalla) y
 * llega como aviso con enlace a «Mis exportaciones».
 */
export function ExportButton({ resource, filters = {}, onError, onQueued }: {
  resource: 'users' | 'legal_entities' | 'employments'
  filters?: Record<string, string>
  onError?: (message: string) => void
  onQueued?: (message: string) => void
}) {
  const { t } = useT()
  const { can } = useSession()
  const [busy, setBusy] = useState(false)

  if (!can('reports.exports.create')) return null

  async function run(format: ExportFormat) {
    setBusy(true)
    try {
      const clean = Object.fromEntries(Object.entries(filters).filter(([, v]) => v !== ''))
      const res = await api.post<{ data: ExportJob }>('/exports', { resource, format, filters: clean })
      if (res.data.status === 'queued' || res.data.status === 'running') {
        onQueued?.(t('exports.queued'))
      } else if (res.data.download_url) {
        const { blob, filename } = await api.download(res.data.download_url)
        saveBlob(blob, filename)
      }
    } catch (e) {
      onError?.(e instanceof ApiRequestError ? e.message : t('exports.error'))
    } finally {
      setBusy(false)
    }
  }

  return (
    <span aria-busy={busy}>
      <MenuButton
        label={t(busy ? 'exports.working' : 'exports.button')}
        icon="download"
        align="right"
        items={EXPORT_FORMATS.map((f) => ({ id: f, label: t(`exports.format_${f}`), onSelect: () => void run(f), disabled: busy }))}
      />
    </span>
  )
}
