'use client'

import { useT } from '@/lib/useT'
import { AuthShell } from '@/modules/identity/AuthShell'

/** UI-9 · Pantalla que el service worker muestra cuando no hay conexión ni una versión guardada de la página. */
export function OfflineScreen() {
  const { t } = useT()
  return (
    <AuthShell>
      <h1>{t('offline.title')}</h1>
      <p className="text-secondary">{t('offline.body')}</p>
      <button type="button" className="btn btn-primary" onClick={() => window.location.reload()}>{t('offline.retry')}</button>
    </AuthShell>
  )
}
