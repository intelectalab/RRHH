'use client'

import { useCallback, useEffect, useState } from 'react'
import { api } from '@/lib/api'
import { useT } from '@/lib/useT'
import { useSession } from '@/modules/session/SessionProvider'
import { AbsenceDates, AbsenceDetailPanel, AbsenceFormPanel, AbsenceQuantity } from '@/modules/time/AbsencePanels'
import { ABSENCE_TONE, type Absence } from '@/modules/time/absences'
import { Icon } from '@/ui/components/Icon'
import { EmptyState } from '@/ui/components/States'
import type { ProfileTabContext } from './types'

/** AUS-2 · Pestaña «Ausencias» de la ficha 360: las ausencias de la relación laboral, de la más reciente a la más antigua. */
export function AbsencesTab({ header, toast }: ProfileTabContext) {
  const { t } = useT()
  const { can } = useSession()
  const [rows, setRows] = useState<Absence[] | null>(null)
  const [failed, setFailed] = useState(false)
  const [creating, setCreating] = useState(false)
  const [detail, setDetail] = useState<string | null>(null)

  const load = useCallback(() => {
    setFailed(false)
    void api.get<{ data: Absence[] }>(`/absences?filter[employment_id]=${header.id}&per_page=100`).then((r) => setRows(r.data)).catch(() => setFailed(true))
  }, [header.id])

  useEffect(load, [load])

  return (
    <section aria-label={t('ficha.tab_absences')}>
      <div className="d-flex justify-content-between align-items-center mb-2">
        <h2 className="h6 mb-0">{t('abs.profile_title')}</h2>
        {can('time.absences.create') && <button type="button" className="btn btn-sec btn-sm" onClick={() => setCreating(true)}><Icon name="plus" /> {t('abs.new')}</button>}
      </div>
      {failed && <p role="alert">{t('abs.load_error')}</p>}
      {!rows && !failed && <span className="esq" style={{ width: 240 }} />}
      {rows && rows.length === 0 && <EmptyState title={t('abs.empty_title')} text={t('abs.profile_empty')} />}
      {rows && rows.length > 0 && (
        <table className="table">
          <thead>
            <tr><th scope="col">{t('abs.type')}</th><th scope="col">{t('abs.col_dates')}</th><th scope="col">{t('abs.col_quantity')}</th><th scope="col">{t('abs.col_status')}</th><th scope="col"><span className="visually-hidden">{t('abs.detail')}</span></th></tr>
          </thead>
          <tbody>
            {rows.map((r) => (
              <tr key={r.id}>
                <td>{r.absence_type_name ?? '—'}</td>
                <td><AbsenceDates absence={r} /></td>
                <td><AbsenceQuantity absence={r} /></td>
                <td><span className={`st ${ABSENCE_TONE[r.status]}`}>{t(`abs.status_${r.status}`)}</span></td>
                <td><button type="button" className="btn btn-ter btn-sm" onClick={() => setDetail(r.id)}>{t('abs.detail')}</button></td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
      {creating && <AbsenceFormPanel employmentId={header.id} onClose={() => setCreating(false)} onSaved={(id) => { setCreating(false); toast(t('abs.saved')); load(); setDetail(id) }} />}
      {detail && <AbsenceDetailPanel id={detail} onClose={() => setDetail(null)} onChanged={load} toast={toast} />}
    </section>
  )
}
