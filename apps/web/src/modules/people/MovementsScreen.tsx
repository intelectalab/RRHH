'use client'

import Link from 'next/link'
import { useCallback, useEffect, useState } from 'react'
import { api } from '@/lib/api'
import { formErrors } from '@/lib/errors'
import { formatDate } from '@/lib/format'
import { useT } from '@/lib/useT'
import { useSession } from '@/modules/session/SessionProvider'
import { Card } from '@/ui/components/Card'
import { Modal } from '@/ui/components/Modal'
import { Notice } from '@/ui/components/Notice'
import { PageHeader } from '@/ui/components/PageHeader'
import { EmptyState, ErrorState } from '@/ui/components/States'
import { StatusBadge } from '@/ui/components/Status'
import { useToast } from '@/ui/components/Toast'
import { peopleTabs } from './peopleTabs'
import { MOVEMENT_TONE, movementLines } from './profile/movements'
import type { PersonnelAction } from './profile/types'

const VIEWS = {
  open: 'pending_approval,approved,scheduled',
  applied: 'applied,replaced',
  closed: 'rejected,cancelled',
} as const

/**
 * HIS-4 · Movimientos programados: los cambios futuros pendientes de aplicarse y los que esperan aprobación,
 * con su antes y después; se anulan antes de su fecha. También muestra los aplicados y los cerrados.
 */
export function MovementsScreen() {
  const { t, locale } = useT()
  const { can } = useSession()
  const { toast, node: toastNode } = useToast()
  const [view, setView] = useState<keyof typeof VIEWS>('open')
  const [rows, setRows] = useState<PersonnelAction[] | null>(null)
  const [failed, setFailed] = useState(false)
  const [cancelling, setCancelling] = useState<PersonnelAction | null>(null)
  const [reason, setReason] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  const load = useCallback(async () => {
    try {
      setRows((await api.get<{ data: PersonnelAction[] }>(`/personnel-actions?filter[status]=${VIEWS[view]}&per_page=100`)).data)
      setFailed(false)
    } catch {
      setFailed(true)
    }
  }, [view])

  useEffect(() => { void load() }, [load])

  async function cancel() {
    if (!cancelling) return
    setBusy(true)
    setError(null)
    try {
      await api.post(`/personnel-actions/${cancelling.id}/cancel`, { reason: reason.trim() })
      setCancelling(null)
      toast(t('mov.cancelled'))
      await load()
    } catch (e) {
      const f = formErrors(e, t)
      setError(f.fields.reason ?? f.general)
    } finally {
      setBusy(false)
    }
  }

  return (
    <>
      <PageHeader title={t('mov.screen_title')} sub={t('mov.screen_sub')} tabs={peopleTabs(t, 'movements', can)} />
      <main className="main" id="contenido" tabIndex={-1}>
        <div className="seg mb-3" role="group" aria-label={t('mov.filter')}>
          {(Object.keys(VIEWS) as Array<keyof typeof VIEWS>).map((v) => (
            <button key={v} type="button" className={view === v ? 'on' : undefined} aria-pressed={view === v} onClick={() => setView(v)}>{t(`mov.view_${v}`)}</button>
          ))}
        </div>
        {failed && <ErrorState text={t('common.load_error')} retryLabel={t('common.retry')} onRetry={() => void load()} />}
        {!failed && !rows && <span className="esq" style={{ width: 240 }} />}
        {!failed && rows && rows.length === 0 && <EmptyState title={t('mov.empty_title')} text={t('mov.empty_text')} />}
        {!failed && rows && rows.length > 0 && (
          <div className="ficha-solicitudes">
            {rows.map((a) => (
              <Card key={a.id}
                title={<Link href={`/personas/colaboradores/${a.employment_id}`}>{a.person_name ?? a.employee_code ?? '—'}</Link>}
                context={<>{t(`ficha.kind_${a.kind}`)} · {t('mov.effective', { date: formatDate(locale, a.effective_on) })} · <StatusBadge tone={MOVEMENT_TONE[a.status]}>{t(`mov.status_${a.status}`)}</StatusBadge></>}
                actions={a.allowed_actions.includes('cancel') && can('people.assignments.cancel')
                  ? <button type="button" className="btn btn-sec btn-sm" onClick={() => { setReason(''); setError(null); setCancelling(a) }}>{t('mov.cancel')}</button>
                  : undefined}>
                <ul className="mov-cambios small">
                  {movementLines(a, t).map((line) => <li key={line}>{line}</li>)}
                </ul>
                {a.reason && <p className="small text-secondary">{t('mov.reason', { reason: a.reason })}</p>}
                {a.decision_reason && <p className="small text-secondary">{t('mov.decision', { reason: a.decision_reason })}</p>}
              </Card>
            ))}
          </div>
        )}
      </main>
      {cancelling && (
        <Modal
          title={t('mov.cancel_title')}
          onClose={() => setCancelling(null)}
          footer={(
            <>
              <button type="button" className="btn btn-sec" onClick={() => setCancelling(null)}>{t('org.cancel')}</button>
              <button type="button" className="btn btn-danger" disabled={busy} aria-busy={busy} onClick={() => void cancel()}>{t('mov.cancel_confirm')}</button>
            </>
          )}
        >
          <p>{t('mov.cancel_text')}</p>
          {error && <Notice tone="error">{error}</Notice>}
          <label className="form-label" htmlFor="movs-reason">{t('ficha.reason')}</label>
          <textarea id="movs-reason" className="form-control" rows={3} value={reason} onChange={(e) => setReason(e.target.value)} />
        </Modal>
      )}
      {toastNode}
    </>
  )
}
