'use client'

import { useRouter } from 'next/navigation'
import { useCallback, useEffect, useState } from 'react'
import { api } from '@/lib/api'
import { relativeTime } from '@/lib/format'
import { useT } from '@/lib/useT'
import { PageHeader } from '@/ui/components/PageHeader'
import { EmptyState, ErrorState } from '@/ui/components/States'
import { NOTIFICATIONS_CHANGED, notifyNotificationsChanged, type AppNotification, type NotificationPage } from './shared'
import { useDateTime } from '@/modules/session/useDateTime'

const GROUPS = ['approval', 'task', 'account'] as const

/** Centro de notificaciones (NOT-2): todas o sin leer, por tipo, ir al registro. */
export function NotificationsScreen() {
  const { t, locale } = useT()
  const fmtDT = useDateTime()
  const router = useRouter()
  const [unreadOnly, setUnreadOnly] = useState(false)
  const [group, setGroup] = useState<string | null>(null)
  const [rows, setRows] = useState<AppNotification[] | null>(null)
  const [cursor, setCursor] = useState<string | null>(null)
  const [unread, setUnread] = useState(0)
  const [loadError, setLoadError] = useState(false)

  const query = useCallback((c?: string | null) => {
    const q = new URLSearchParams({ per_page: '30' })
    if (unreadOnly) q.set('filter[unread]', '1')
    if (group) q.set('filter[category]', `${group}.`)
    if (c) q.set('cursor', c)
    return `/notifications?${q.toString()}`
  }, [unreadOnly, group])

  const load = useCallback(async () => {
    try {
      const res = await api.get<NotificationPage>(query())
      setRows(res.data)
      setCursor(res.meta.next_cursor)
      setUnread(res.meta.unread)
      setLoadError(false)
    } catch {
      setLoadError(true)
    }
  }, [query])

  useEffect(() => { setRows(null); void load() }, [load])
  useEffect(() => {
    window.addEventListener(NOTIFICATIONS_CHANGED, load)
    return () => window.removeEventListener(NOTIFICATIONS_CHANGED, load)
  }, [load])

  async function more() {
    if (!cursor) return
    const res = await api.get<NotificationPage>(query(cursor))
    setRows((r) => [...(r ?? []), ...res.data])
    setCursor(res.meta.next_cursor)
  }

  async function markAll() {
    await api.post('/notifications/read', { all: true })
    notifyNotificationsChanged()
  }

  async function open(n: AppNotification) {
    if (!n.read) {
      await api.post('/notifications/read', { ids: [n.id] })
      notifyNotificationsChanged()
    }
    if (n.action_url) router.push(n.action_url)
  }

  return (
    <>
      <PageHeader
        title={t('notif.title')}
        sub={t('notif.sub', { n: unread })}
        actions={unread > 0 && <button type="button" className="btn btn-sec" onClick={() => void markAll()}>{t('notif.mark_all')}</button>}
      />
      <main className="main" id="contenido" tabIndex={-1}>
        <div className="barra-filtros">
          <div className="seg" role="group" aria-label={t('notif.title')}>
            <button type="button" className={!unreadOnly ? 'on' : undefined} aria-pressed={!unreadOnly} onClick={() => setUnreadOnly(false)}>{t('notif.all')}</button>
            <button type="button" className={unreadOnly ? 'on' : undefined} aria-pressed={unreadOnly} onClick={() => setUnreadOnly(true)}>{t('notif.unread_only')}</button>
          </div>
          <div className="chips" role="group" aria-label={t('notif.group_all')} style={{ marginBottom: 0 }}>
            <button type="button" aria-pressed={group === null} onClick={() => setGroup(null)}>{t('notif.group_all')}</button>
            {GROUPS.map((g) => <button key={g} type="button" aria-pressed={group === g} onClick={() => setGroup(g)}>{t(`notif.group_${g}`)}</button>)}
          </div>
        </div>
        <section className="card">
          {loadError && <ErrorState text={t('common.load_error')} retryLabel={t('common.retry')} onRetry={() => void load()} />}
          {!loadError && rows === null && [1, 2, 3].map((i) => <div key={i} className="notif-fila"><span className="esq" style={{ width: `${30 + i * 15}%` }} /></div>)}
          {rows?.length === 0 && <EmptyState title={t('notif.empty_title')} text={t(unreadOnly ? 'notif.empty_unread' : 'notif.empty_text')} />}
          {rows?.map((n) => (
            <button key={n.id} type="button" className={`notif-fila${n.read ? '' : ' nueva'}`} onClick={() => void open(n)}>
              <span className="punto" aria-hidden="true" />
              <span className="min-w-0">
                <b>{n.title}</b>
                {!n.read && <span className="tag oc" style={{ marginLeft: 8 }}>{t('notif.new')}</span>}
                {n.body && <span className="sub2">{n.body}</span>}
              </span>
              <time className="sub2" dateTime={n.created_at ?? undefined} title={fmtDT(n.created_at)}>{relativeTime(locale, n.created_at)}</time>
            </button>
          ))}
          {cursor && <div style={{ padding: 12, textAlign: 'center' }}><button type="button" className="btn btn-sec btn-sm" onClick={() => void more()}>{t('notif.load_more')}</button></div>}
        </section>
      </main>
    </>
  )
}
