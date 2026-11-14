'use client'

import Link from 'next/link'
import { useEffect, useState } from 'react'
import { api, ApiRequestError } from '@/lib/api'
import { formatDate } from '@/lib/format'
import { useT } from '@/lib/useT'
import { Notice } from '@/ui/components/Notice'
import { EmptyState } from '@/ui/components/States'
import { cellTone, itemOn, type TeamCalendar } from './vacationRequests'

/** Fecha local AAAA-MM-DD desplazada n días (solo para el rango inicial de la vista). */
export function isoDay(base: Date, offset = 0): string {
  const d = new Date(base.getFullYear(), base.getMonth(), base.getDate() + offset)
  const mm = String(d.getMonth() + 1).padStart(2, '0')
  const dd = String(d.getDate()).padStart(2, '0')
  return `${d.getFullYear()}-${mm}-${dd}`
}

/**
 * VAC-4 · Calendario de ausencias del equipo. Personas en filas y días en columnas: vacaciones y ausencias
 * aprobadas y en trámite. El alcance, los ausentes por día y las alertas de cobertura vienen del servidor;
 * con alcance propio se ven los compañeros de la unidad como «Ausente», sin tipo ni motivo.
 */
export function TeamCalendarView() {
  const { t, locale } = useT()
  const [range, setRange] = useState(() => ({ from: isoDay(new Date()), to: isoDay(new Date(), 27) }))
  const [data, setData] = useState<TeamCalendar | null>(null)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    if (!range.from || !range.to) return
    setError(null)
    api.get<{ data: TeamCalendar }>(`/team-calendar?from=${range.from}&to=${range.to}`)
      .then((r) => setData(r.data))
      .catch((e) => { setData(null); setError(e instanceof ApiRequestError ? e.message : t('vreq.load_error')) })
  }, [range, t])

  const short = (date: string) => new Intl.DateTimeFormat(locale, { day: 'numeric' }).format(new Date(`${date}T12:00:00`))
  const weekday = (date: string) => new Intl.DateTimeFormat(locale, { weekday: 'narrow' }).format(new Date(`${date}T12:00:00`))

  return (
    <section aria-label={t('vreq.team_title')}>
      <div className="row g-2 mb-2" style={{ maxWidth: 420 }}>
        <div className="col-6">
          <label className="form-label" htmlFor="tc-from">{t('vreq.from')}</label>
          <input id="tc-from" type="date" className="form-control" value={range.from} onChange={(e) => setRange((r) => ({ ...r, from: e.target.value }))} />
        </div>
        <div className="col-6">
          <label className="form-label" htmlFor="tc-to">{t('vreq.to')}</label>
          <input id="tc-to" type="date" className="form-control" value={range.to} min={range.from} onChange={(e) => setRange((r) => ({ ...r, to: e.target.value }))} />
        </div>
      </div>
      {error && <Notice tone="error">{error}</Notice>}
      {data?.mode === 'peers' && <Notice tone="info">{t('vreq.peers_note')}</Notice>}
      {data?.alerts.map((a) => (
        <Notice key={`${a.org_unit_id ?? ''}${a.from}`} tone="warn">
          {t('vreq.coverage_unit', { unit: a.org_unit_name ?? '', from: formatDate(locale, a.from), to: formatDate(locale, a.to), count: a.count, limit: a.limit })}
        </Notice>
      ))}
      <div className="cal-eq-leyenda" aria-hidden="true">
        <span><i className="t-vac" />{t('vreq.legend_vacation')}</span>
        <span><i className="t-abs" />{t('vreq.legend_absence')}</span>
        <span><i className="t-pend" />{t('vreq.legend_pending')}</span>
        {data?.mode === 'peers' && <span><i className="t-other" />{t('vreq.legend_other')}</span>}
      </div>
      {data && data.people.length === 0 && <EmptyState title={t('vreq.team_empty_title')} text={t('vreq.team_empty_text')} />}
      {data && data.people.length > 0 && (
        <div className="cal-eq-wrap">
          <table className="cal-eq">
            <caption className="visually-hidden">{t('vreq.team_caption', { from: formatDate(locale, data.from), to: formatDate(locale, data.to) })}</caption>
            <thead>
              <tr>
                <th scope="col" className="per">{t('vreq.employee')}</th>
                {data.days.map((d) => (
                  <th key={d.date} scope="col" className={d.coverage_exceeded ? 'over' : undefined} title={formatDate(locale, d.date)}>
                    {weekday(d.date)}<br />{short(d.date)}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {data.people.map((p) => (
                <tr key={p.employment_id}>
                  <th scope="row" className="per">
                    <b>{p.person_name}</b>
                    {p.org_unit_name && <span className="sub2"> · {p.org_unit_name}</span>}
                  </th>
                  {data.days.map((d) => {
                    const item = itemOn(p.items, d.date)
                    const tone = cellTone(item)
                    const text = item ? `${item.label}${item.pending ? ` (${t('vreq.pending')})` : ''} · ${formatDate(locale, d.date)}` : undefined
                    return (
                      <td key={d.date} className={tone ? `dia t-${tone}` : 'dia'} title={text}>
                        {item && (item.link
                          ? <Link href={item.link} aria-label={text}><span /></Link>
                          : <span role="img" aria-label={text} />)}
                      </td>
                    )
                  })}
                </tr>
              ))}
            </tbody>
            <tfoot>
              <tr>
                <td className="per">{t('vreq.absent_per_day')}</td>
                {data.days.map((d) => <td key={d.date} className={d.coverage_exceeded ? 'over' : undefined}>{d.absent > 0 ? d.absent : ''}</td>)}
              </tr>
            </tfoot>
          </table>
        </div>
      )}
    </section>
  )
}
