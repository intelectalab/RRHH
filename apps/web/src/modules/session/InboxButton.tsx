'use client'

import Link from 'next/link'
import { usePathname } from 'next/navigation'
import { useCallback, useEffect, useState } from 'react'
import { api } from '@/lib/api'
import { useT } from '@/lib/useT'
import { INBOX_CHANGED } from '@/modules/workflow/shared'
import { Icon } from '@/ui/components/Icon'

export type InboxCounts = Record<string, number> & { total: number }

/**
 * Contador de pendientes de la barra superior (WFL-9): sale de GET /inbox, el mismo origen que la
 * bandeja, así que coincide con los pendientes reales. Se recalcula al resolver algo, al cambiar de
 * pantalla, al volver a la pestaña y cada minuto.
 */
export function useInboxCount(): InboxCounts | null {
  const [counts, setCounts] = useState<InboxCounts | null>(null)
  const pathname = usePathname()

  const load = useCallback(async () => {
    try {
      const res = await api.get<{ data: { total: number; counts: Record<string, number> } }>('/inbox?limit=1')
      setCounts({ ...res.data.counts, total: res.data.total })
    } catch {
      // Sin conexión: se conserva el último valor.
    }
  }, [])

  useEffect(() => { void load() }, [load, pathname])
  useEffect(() => {
    const onVisible = () => { if (document.visibilityState === 'visible') void load() }
    window.addEventListener(INBOX_CHANGED, load)
    document.addEventListener('visibilitychange', onVisible)
    const timer = window.setInterval(() => void load(), 60_000)
    return () => {
      window.removeEventListener(INBOX_CHANGED, load)
      document.removeEventListener('visibilitychange', onVisible)
      window.clearInterval(timer)
    }
  }, [load])

  return counts
}

export function InboxButton({ counts }: { counts: InboxCounts | null }) {
  const { t } = useT()
  const n = counts?.total ?? 0

  return (
    <Link href="/pendientes" className="ibtn pend-btn" aria-label={t('shell.inbox_aria', { n })} title={t('shell.inbox')}>
      <Icon name="inbox" size={20} />
      {n > 0 && <span className="cnt-mod" aria-hidden="true">{n > 99 ? '99+' : n}</span>}
    </Link>
  )
}
