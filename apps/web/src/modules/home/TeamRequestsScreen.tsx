'use client'

import Link from 'next/link'
import { useCallback, useEffect, useState } from 'react'
import { api, ApiRequestError } from '@/lib/api'
import { relativeTime } from '@/lib/format'
import { useT } from '@/lib/useT'
import { useSession } from '@/modules/session/SessionProvider'
import { notifyInboxChanged, typeLabel, type Approval } from '@/modules/workflow/shared'
import { Icon } from '@/ui/components/Icon'
import { PageHeader } from '@/ui/components/PageHeader'
import { EmptyState, ErrorState } from '@/ui/components/States'
import { useToast } from '@/ui/components/Toast'
import { teamStartLinks, teamTabs } from './team'

type TeamApproval = Approval & { employment_id?: string | null; employee_name?: string | null }

/**
 * MSS-3 · Solicitudes del equipo: las aprobaciones de las personas que reportan al manager (y las que él inició
 * para su equipo), con aprobar y rechazar en la misma tarjeta, pensado para el móvil. Las reglas y el alcance los
 * aplica la API: aquí solo se muestran.
 */
export function TeamRequestsScreen() {
  const { t, locale } = useT()
  const { can } = useSession()
  const { toast, node: toastNode } = useToast()
  const [status, setStatus] = useState<'pending' | 'all'>('pending')
  const [rows, setRows] = useState<TeamApproval[] | null>(null)
  const [failed, setFailed] = useState(false)
  const [busy, setBusy] = useState<string | null>(null)
  const [rejecting, setRejecting] = useState<string | null>(null)
  const [reason, setReason] = useState('')

  const load = useCallback(async () => {
    const params = new URLSearchParams({ 'filter[team]': '1', per_page: '50' })
    if (status === 'pending') params.set('filter[status]', 'pending')
    try {
      const res = await api.get<{ data: TeamApproval[] }>(`/approval-requests?${params.toString()}`)
      setRows(res.data)
      setFailed(false)
    } catch {
      setFailed(true)
    }
  }, [status])

  useEffect(() => {
    void load()
  }, [load])

  async function decide(row: TeamApproval, action: 'approve' | 'reject') {
    setBusy(row.id)
    try {
      await api.post(`/approval-requests/${row.id}/${action}`, action === 'reject' ? { comment: reason } : {})
      toast(t(action === 'approve' ? 'approvals.approved_toast' : 'approvals.rejected_toast'))
      setRejecting(null)
      setReason('')
      notifyInboxChanged()
      await load()
    } catch (e) {
      toast(e instanceof ApiRequestError ? e.message : t('common.load_error'), 'error')
    } finally {
      setBusy(null)
    }
  }

  const starts = teamStartLinks(can)

  return (
    <>
      <PageHeader
        title={t('my_team.title')}
        sub={t('my_team.requests_sub')}
        tabs={teamTabs(t, can, 'requests')}
        actions={starts.length > 0 ? <>{starts.map((l) => <Link key={l.key} className="btn btn-sec btn-sm" href={l.href}>{t(l.key)}</Link>)}</> : undefined}
      />
      <main className="main" id="contenido" tabIndex={-1}>
        <div className="seg mb-3" role="tablist" aria-label={t('my_team.requests_filter')}>
          {(['pending', 'all'] as const).map((v) => (
            <button key={v} type="button" role="tab" aria-selected={status === v} className={status === v ? 'on' : undefined} onClick={() => setStatus(v)}>{t(`my_team.requests_${v}`)}</button>
          ))}
        </div>
        {failed && <ErrorState text={t('common.load_error')} retryLabel={t('common.retry')} onRetry={() => void load()} />}
        {!failed && rows === null && <div className="card card-body esq-grupo">{[1, 2, 3].map((i) => <span key={i} className="esq" style={{ width: `${40 + i * 15}%` }} />)}</div>}
        {!failed && rows?.length === 0 && <EmptyState title={t('my_team.requests_empty_title')} text={t('my_team.requests_empty_text')} />}
        {!failed && rows && rows.length > 0 && (
          <ul style={{ listStyle: 'none', padding: 0 }} aria-label={t('my_team.tab_requests')}>
            {rows.map((r) => (
              <li key={r.id} className="card card-body mb-2">
                <div style={{ display: 'flex', justifyContent: 'space-between', gap: 8, flexWrap: 'wrap' }}>
                  <div>
                    <b>{typeLabel(t, r.subject_type)}</b>
                    <div className="sub2">
                      {r.employee_name ?? r.requester_name ?? '—'}
                      {r.created_at ? ` · ${relativeTime(locale, r.created_at)}` : ''}
                    </div>
                    {r.status === 'pending' && r.current_step_name && <div className="sub2">{t('my_team.requests_level', { level: r.current_step_name })}</div>}
                  </div>
                  <span className={`st ${r.status === 'approved' ? 'ok' : r.status === 'rejected' ? 'er' : r.status === 'pending' ? 'wa' : 'gr'}`}>{t(`approvals.status_${r.status}`)}</span>
                </div>
                {r.can_decide && rejecting !== r.id && (
                  <div style={{ display: 'flex', gap: 8, marginTop: 12, flexWrap: 'wrap' }}>
                    {can('workflow.approval_requests.approve') && (
                      <button type="button" className="btn btn-primary btn-sm" disabled={busy === r.id} onClick={() => void decide(r, 'approve')}><Icon name="check" /> {t('approvals.approve')}</button>
                    )}
                    {can('workflow.approval_requests.reject') && (
                      <button type="button" className="btn btn-pel-sec btn-sm" disabled={busy === r.id} onClick={() => { setRejecting(r.id); setReason('') }}>{t('approvals.reject')}</button>
                    )}
                    <Link className="btn btn-sec btn-sm" href={`/aprobaciones?id=${r.id}`}>{t('my_team.requests_detail')}</Link>
                  </div>
                )}
                {rejecting === r.id && (
                  <form className="mt-3" onSubmit={(e) => { e.preventDefault(); void decide(r, 'reject') }}>
                    <label className="form-label" htmlFor={`reason-${r.id}`}>{t('my_team.requests_reason')}</label>
                    <textarea id={`reason-${r.id}`} className="form-control" required rows={2} value={reason} onChange={(e) => setReason(e.target.value)} />
                    <div style={{ display: 'flex', gap: 8, marginTop: 8 }}>
                      <button type="submit" className="btn btn-pel-sec btn-sm" disabled={busy === r.id || reason.trim() === ''}>{t('approvals.reject')}</button>
                      <button type="button" className="btn btn-sec btn-sm" onClick={() => setRejecting(null)}>{t('common.cancel')}</button>
                    </div>
                  </form>
                )}
              </li>
            ))}
          </ul>
        )}
      </main>
      {toastNode}
    </>
  )
}
