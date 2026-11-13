'use client'

import { useState } from 'react'
import { api, ApiRequestError, type Schema } from '@/lib/api'
import { useT } from '@/lib/useT'
import { useSession } from '@/modules/session/SessionProvider'
import { Icon } from '@/ui/components/Icon'

type ExportJob = Schema<'ExportJob'>

/**
 * SEG-12 · Exportar el listado visible a CSV. Solo aparece con reports.exports.create; la API
 * vuelve a validar el permiso, el alcance y los campos sensibles, y registra la exportación.
 */
export function ExportButton({ resource, filters = {}, onError }: {
  resource: 'users' | 'legal_entities' | 'employments'
  filters?: Record<string, string>
  onError?: (message: string) => void
}) {
  const { t } = useT()
  const { can } = useSession()
  const [busy, setBusy] = useState(false)

  if (!can('reports.exports.create')) return null

  async function run() {
    setBusy(true)
    try {
      const clean = Object.fromEntries(Object.entries(filters).filter(([, v]) => v !== ''))
      const res = await api.post<{ data: ExportJob }>('/exports', { resource, format: 'csv', filters: clean })
      if (res.data.download_url) {
        const { blob, filename } = await api.download(res.data.download_url)
        const href = URL.createObjectURL(blob)
        const a = document.createElement('a')
        a.href = href
        a.download = filename
        document.body.appendChild(a)
        a.click()
        a.remove()
        URL.revokeObjectURL(href)
      }
    } catch (e) {
      onError?.(e instanceof ApiRequestError ? e.message : t('exports.error'))
    } finally {
      setBusy(false)
    }
  }

  return (
    <button type="button" className="btn btn-sec" onClick={() => void run()} disabled={busy} aria-busy={busy}>
      {busy ? <span className="spin" aria-hidden="true" /> : <Icon name="download" />} {t(busy ? 'exports.working' : 'exports.csv')}
    </button>
  )
}
