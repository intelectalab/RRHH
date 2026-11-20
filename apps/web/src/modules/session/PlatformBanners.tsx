'use client'

import { useEffect, useState } from 'react'
import { api } from '@/lib/api'
import { formatDateTime } from '@/lib/format'
import { useT } from '@/lib/useT'
import { Notice, type NoticeTone } from '@/ui/components/Notice'
import { useSession } from './SessionProvider'

type SystemNotice = { id: string; title: string; message: string; severity: 'info' | 'warning' | 'critical'; starts_at: string; ends_at: string | null }

const TONE: Record<SystemNotice['severity'], NoticeTone> = { info: 'info', warning: 'warn', critical: 'error' }

const DISMISSED_KEY = 'gestia.dismissed_notices'

function dismissed(): string[] {
  try {
    return JSON.parse(sessionStorage.getItem(DISMISSED_KEY) ?? '[]') as string[]
  } catch {
    return []
  }
}

/**
 * SAS-8 · SAS-13 · Cabecera visible «sesión de soporte» mientras el soporte de GestiaHR opera la cuenta, y avisos
 * de la plataforma (mantenimiento) vigentes para la organización.
 */
export function PlatformBanners() {
  const { t, locale } = useT()
  const { session } = useSession()
  const [notices, setNotices] = useState<SystemNotice[]>([])
  const [hidden, setHidden] = useState<string[]>([])

  useEffect(() => {
    setHidden(dismissed())
    api.get<{ data: SystemNotice[] }>('/system-notices').then((r) => setNotices(r.data)).catch(() => setNotices([]))
  }, [])

  function dismiss(id: string) {
    const next = [...hidden, id]
    setHidden(next)
    try { sessionStorage.setItem(DISMISSED_KEY, JSON.stringify(next)) } catch { /* sin almacenamiento: solo en memoria */ }
  }

  const support = session.support_session
  return (
    <>
      {support && (
        <div className="soporte-aviso" role="status">
          {t('shell.support_session', { agent: support.agent_name, until: formatDateTime(locale, support.expires_at) })}
          {!support.allow_sensitive && <> · {t('shell.support_no_sensitive')}</>}
        </div>
      )}
      {notices.filter((n) => !hidden.includes(n.id)).map((n) => (
        <Notice key={n.id} tone={TONE[n.severity]} title={n.title} onDismiss={n.severity === 'critical' ? undefined : () => dismiss(n.id)}>{n.message}</Notice>
      ))}
    </>
  )
}
