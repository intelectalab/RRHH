'use client'

import Link from 'next/link'
import { useCallback, useEffect, useState } from 'react'
import { api } from '@/lib/api'
import { formatDate } from '@/lib/format'
import { useT } from '@/lib/useT'
import { useSession } from '@/modules/session/SessionProvider'
import { PageHeader } from '@/ui/components/PageHeader'
import { EmptyState, ErrorState } from '@/ui/components/States'
import { groupByMonth, KIND_TAG, teamTabs, type TeamKeyDate, type TeamKeyDateKind, type TeamKeyDatesMeta } from './team'

const WINDOWS = [30, 60, 90] as const

/**
 * MSS-4 · Fechas clave del equipo: cumpleaños (solo el día), aniversarios, fin del periodo de prueba y contratos
 * por vencer de quienes reportan al manager. La API decide qué tipos puede ver y si la organización oculta los
 * cumpleaños; los recordatorios llegan por el motor de notificaciones según sus preferencias.
 */
export function TeamDatesScreen() {
  const { t, locale } = useT()
  const { can } = useSession()
  const [days, setDays] = useState<(typeof WINDOWS)[number]>(30)
  const [kind, setKind] = useState<TeamKeyDateKind | ''>('')
  const [rows, setRows] = useState<TeamKeyDate[] | null>(null)
  const [meta, setMeta] = useState<TeamKeyDatesMeta | null>(null)
  const [failed, setFailed] = useState(false)

  const load = useCallback(async () => {
    const from = new Date()
    const to = new Date(from.getTime() + days * 86_400_000)
    const iso = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
    const params = new URLSearchParams({ from: iso(from), to: iso(to) })
    if (kind) params.set('filter[kind]', kind)
    try {
      const res = await api.get<{ data: TeamKeyDate[]; meta: TeamKeyDatesMeta }>(`/my-team/key-dates?${params.toString()}`)
      setRows(res.data)
      setMeta(res.meta)
      setFailed(false)
    } catch {
      setFailed(true)
    }
  }, [days, kind])

  useEffect(() => {
    void load()
  }, [load])

  const monthLabel = (month: string) => new Intl.DateTimeFormat(locale, { month: 'long', year: 'numeric' }).format(new Date(`${month}-15T12:00:00`))
  const dayLabel = (item: TeamKeyDate) => (item.kind === 'birthday'
    ? new Intl.DateTimeFormat(locale, { day: 'numeric', month: 'long' }).format(new Date(`${item.date}T12:00:00`))
    : formatDate(locale, item.date))

  return (
    <>
      <PageHeader title={t('my_team.title')} sub={t('my_team.dates_sub')} tabs={teamTabs(t, can, 'dates')} />
      <main className="main" id="contenido" tabIndex={-1}>
        <section className="card">
          <div className="dt-barra" style={{ display: 'flex', gap: 8, flexWrap: 'wrap', padding: '12px 16px' }}>
            <select className="form-select" style={{ maxWidth: 220 }} aria-label={t('my_team.dates_window')} value={days} onChange={(e) => setDays(Number(e.target.value) as (typeof WINDOWS)[number])}>
              {WINDOWS.map((d) => <option key={d} value={d}>{t('my_team.dates_next_days', { n: d })}</option>)}
            </select>
            <select className="form-select" style={{ maxWidth: 260 }} aria-label={t('my_team.dates_kind')} value={kind} onChange={(e) => setKind(e.target.value as TeamKeyDateKind | '')}>
              <option value="">{t('my_team.dates_kind_all')}</option>
              {(meta?.kinds ?? []).map((k) => <option key={k} value={k}>{t(`my_team.kind_${k}`)}</option>)}
            </select>
            {meta?.birthdays_hidden && <span className="sub2" style={{ alignSelf: 'center' }}>{t('my_team.dates_birthdays_hidden')}</span>}
          </div>
          {failed && <ErrorState text={t('common.load_error')} retryLabel={t('common.retry')} onRetry={() => void load()} />}
          {!failed && rows === null && <div className="card-body esq-grupo">{[1, 2, 3].map((i) => <span key={i} className="esq" style={{ width: `${40 + i * 15}%` }} />)}</div>}
          {!failed && rows?.length === 0 && <EmptyState title={t('my_team.dates_empty_title')} text={t('my_team.dates_empty_text')} />}
          {!failed && rows && rows.length > 0 && (
            <div className="card-body">
              {groupByMonth(rows).map((g) => (
                <div key={g.month} className="mb-3">
                  <h2 className="inicio-titulo mb-2" style={{ textTransform: 'capitalize' }}>{monthLabel(g.month)}</h2>
                  <ul className="inicio-items">
                    {g.items.map((item) => (
                      <li key={`${item.kind}-${item.employment_id}-${item.date}`}>
                        <span className={`tag ${KIND_TAG[item.kind]}`}>{t(`my_team.kind_${item.kind}`)}</span>{' '}
                        <Link href={item.link}>{item.name ?? '—'}</Link>
                        <span className="sub2">
                          {' · '}{dayLabel(item)}
                          {item.kind === 'anniversary' && item.years !== null ? ` · ${t('my_team.dates_years', { n: item.years })}` : ''}
                          {item.is_direct ? '' : ` · ${t('my_team.indirect_tag')}`}
                        </span>
                      </li>
                    ))}
                  </ul>
                </div>
              ))}
            </div>
          )}
        </section>
      </main>
    </>
  )
}
