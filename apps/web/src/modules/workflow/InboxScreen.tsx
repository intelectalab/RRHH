'use client'

import Link from 'next/link'
import { useCallback, useEffect, useState } from 'react'
import { api } from '@/lib/api'
import { formatDate, relativeTime } from '@/lib/format'
import { useT } from '@/lib/useT'
import { Icon } from '@/ui/components/Icon'
import { PageHeader } from '@/ui/components/PageHeader'
import { EmptyState, ErrorState } from '@/ui/components/States'
import { INBOX_CHANGED, typeLabel } from './shared'

export type InboxItem = { kind: 'approval' | 'task' | string; id: string; type: string; title: string; subtitle: string | null; due_at: string | null; overdue: boolean; created_at: string | null; href: string; data?: { level?: number; escalated?: boolean } }
export type Inbox = { total: number; counts: Record<string, number>; items: InboxItem[] }

/** Bandeja «Pendientes» (WFL-9): lo que el usuario debe hacer, de todos los módulos. */
export function InboxScreen() {
  const { t, locale } = useT()
  const [inbox, setInbox] = useState<Inbox | null>(null)
  const [loadError, setLoadError] = useState(false)

  const load = useCallback(async () => {
    try {
      setInbox((await api.get<{ data: Inbox }>('/inbox')).data)
      setLoadError(false)
    } catch {
      setLoadError(true)
    }
  }, [])

  useEffect(() => {
    void load()
    window.addEventListener(INBOX_CHANGED, load)
    return () => window.removeEventListener(INBOX_CHANGED, load)
  }, [load])

  const title = (item: InboxItem) => item.kind === 'approval'
    ? t('inbox.approval_title', { type: typeLabel(t, item.type), name: item.title })
    : item.title

  return (
    <>
      <PageHeader title={t('inbox.title')} sub={inbox ? t('inbox.sub', { n: inbox.total }) : undefined} />
      <main className="main" id="contenido" tabIndex={-1}>
        <section className="card">
          {loadError && <ErrorState text={t('common.load_error')} retryLabel={t('common.retry')} onRetry={() => void load()} />}
          {!loadError && inbox === null && [1, 2, 3].map((i) => <div key={i} className="solicitud"><span /><span className="esq" style={{ width: 90 }} /><span className="esq" style={{ width: `${30 + i * 15}%` }} /></div>)}
          {inbox?.items.length === 0 && <EmptyState title={t('inbox.empty_title')} text={t('inbox.empty_text')} />}
          {inbox?.items.map((item) => (
            <article key={`${item.kind}-${item.id}`} className="solicitud" style={{ gridTemplateColumns: 'auto minmax(0, 1fr) auto' }}>
              <span className={`tag ${item.kind === 'approval' ? 'pe' : 'in'}`} style={{ marginTop: 2 }}>{t(`inbox.kind_${item.kind}`)}</span>
              <div className="min-w-0">
                <Link className="nm" href={item.href}>{title(item)}</Link>
                <div className="sub2">
                  {item.subtitle ? `${item.subtitle} · ` : ''}
                  {item.created_at ? relativeTime(locale, item.created_at) : ''}
                </div>
                <div className="tags">
                  {item.overdue && <span className="tag er">{t('tasks.overdue')}</span>}
                  {!item.overdue && item.due_at && <span className="tag gr">{t('approvals.due', { when: item.kind === 'task' ? formatDate(locale, item.due_at) : relativeTime(locale, item.due_at) })}</span>}
                  {item.data?.escalated && <span className="tag wa">{t('approvals.escalated')}</span>}
                </div>
              </div>
              <Link className="btn btn-sec btn-sm" href={item.href}>{t('inbox.open')} <Icon name="arrowRight" /></Link>
            </article>
          ))}
        </section>
      </main>
    </>
  )
}
