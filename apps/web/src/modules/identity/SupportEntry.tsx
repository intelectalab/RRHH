'use client'

import { useRouter } from 'next/navigation'
import { useEffect, useState } from 'react'
import { api } from '@/lib/api'
import { useT } from '@/lib/useT'
import { Notice } from '@/ui/components/Notice'

/**
 * SAS-8 · Ingreso del soporte de GestiaHR con el enlace de un solo uso que da la consola. El token viaja en el
 * fragmento (#t=…) y se envía a la API, que abre la sesión marcada como de soporte.
 */
export function SupportEntry() {
  const { t } = useT()
  const router = useRouter()
  const [failed, setFailed] = useState(false)

  useEffect(() => {
    const token = new URLSearchParams(window.location.hash.replace(/^#/, '')).get('t') ?? ''
    history.replaceState(null, '', window.location.pathname)
    api.post('/auth/support/enter', { token })
      .then(() => router.replace('/'))
      .catch(() => setFailed(true))
  }, [router])

  return failed
    ? <Notice tone="error" title={t('support.entry_failed_title')}>{t('support.entry_failed')}</Notice>
    : <p aria-busy="true">{t('support.entering')}</p>
}
