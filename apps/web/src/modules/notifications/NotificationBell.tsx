'use client'

import Link from 'next/link'
import { useRouter } from 'next/navigation'
import { useCallback, useEffect, useRef, useState } from 'react'
import { api } from '@/lib/api'
import { relativeTime } from '@/lib/format'
import { useT } from '@/lib/useT'
import { INBOX_CHANGED } from '@/modules/workflow/shared'
import { Icon } from '@/ui/components/Icon'
import { NOTIFICATIONS_CHANGED, notifyNotificationsChanged, type AppNotification, type NotificationPage } from './shared'

/**
 * Campana del centro de notificaciones (NOT-2). El contador se recalcula sin recargar: cada 30 s,
 * al volver a la pestaña, al abrir la campana y cuando otra pantalla resuelve algo.
 */
export function NotificationBell() {
  const { t, locale } = useT()
  const router = useRouter()
  const [open, setOpen] = useState(false)
  const [items, setItems] = useState<AppNotification[] | null>(null)
  const [unread, setUnread] = useState(0)
  const ref = useRef<HTMLDivElement>(null)

  const load = useCallback(async () => {
    try {
      const res = await api.get<NotificationPage>('/notifications?per_page=8')
      setItems(res.data)
      setUnread(res.meta.unread)
    } catch {
      // Sin conexión: se conserva lo último.
    }
  }, [])

  useEffect(() => {
    void load()
    const onVisible = () => { if (document.visibilityState === 'visible') void load() }
    window.addEventListener(NOTIFICATIONS_CHANGED, load)
    window.addEventListener(INBOX_CHANGED, load)
    document.addEventListener('visibilitychange', onVisible)
    const timer = window.setInterval(() => void load(), 30_000)
    return () => {
      window.removeEventListener(NOTIFICATIONS_CHANGED, load)
      window.removeEventListener(INBOX_CHANGED, load)
      document.removeEventListener('visibilitychange', onVisible)
      window.clearInterval(timer)
    }
  }, [load])

  useEffect(() => {
    if (!open) return
    const close = (e: MouseEvent) => { if (ref.current && !ref.current.contains(e.target as Node)) setOpen(false) }
    const esc = (e: KeyboardEvent) => { if (e.key === 'Escape') setOpen(false) }
    document.addEventListener('mousedown', close)
    document.addEventListener('keydown', esc)
    return () => { document.removeEventListener('mousedown', close); document.removeEventListener('keydown', esc) }
  }, [open])

  async function markAll() {
    const res = await api.post<{ data: { unread: number } }>('/notifications/read', { all: true })
    setUnread(res.data.unread)
    setItems((xs) => xs?.map((x) => ({ ...x, read: true })) ?? null)
    notifyNotificationsChanged()
  }

  async function openItem(n: AppNotification) {
    setOpen(false)
    if (!n.read) {
      void api.post<{ data: { unread: number } }>('/notifications/read', { ids: [n.id] }).then((r) => { setUnread(r.data.unread); notifyNotificationsChanged() })
    }
    if (n.action_url) router.push(n.action_url)
  }

  return (
    <div className="menu" ref={ref}>
      <button type="button" className="ibtn pend-btn" aria-expanded={open} aria-haspopup="dialog" aria-label={t('shell.notifications_aria', { n: unread })} title={t('shell.notifications')}
        onClick={() => { setOpen((v) => !v); if (!open) void load() }}>
        <Icon name="bell" size={20} />
        {unread > 0 && <span className="cnt-mod" aria-hidden="true">{unread > 99 ? '99+' : unread}</span>}
      </button>
      {open && (
        <div className="menu-lista notif-lista" role="dialog" aria-label={t('notif.title')}>
          <div className="notif-cab">
            <b>{t('notif.title')}</b>
            {unread > 0 && <button type="button" className="btn btn-ter btn-sm" onClick={() => void markAll()}>{t('notif.mark_all')}</button>}
          </div>
          {items?.length === 0 && <p className="sub2" style={{ padding: '12px 16px' }}>{t('notif.empty_text')}</p>}
          <ul>
            {items?.map((n) => (
              <li key={n.id}>
                <button type="button" className={`notif-item${n.read ? '' : ' nueva'}`} onClick={() => void openItem(n)}>
                  <b>{n.title}</b>
                  {n.body && <span className="sub2">{n.body}</span>}
                  <span className="sub2">{relativeTime(locale, n.created_at)}</span>
                </button>
              </li>
            ))}
          </ul>
          <div className="notif-pie">
            <Link href="/notificaciones" onClick={() => setOpen(false)}>{t('notif.see_all')}</Link>
            <Link href="/cuenta/notificaciones" onClick={() => setOpen(false)}>{t('notif.preferences')}</Link>
          </div>
        </div>
      )}
    </div>
  )
}
