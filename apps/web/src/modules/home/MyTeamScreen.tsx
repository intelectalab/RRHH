'use client'

import Link from 'next/link'
import { useCallback, useEffect, useState } from 'react'
import { api } from '@/lib/api'
import { formatDate, formatNumber } from '@/lib/format'
import { useT } from '@/lib/useT'
import { useSession } from '@/modules/session/SessionProvider'
import { PageHeader } from '@/ui/components/PageHeader'
import { EmptyState, ErrorState } from '@/ui/components/States'
import type { Money } from './homeView'

export type TeamMember = {
  id: string
  person_name: string | null
  employee_code: string
  position_title: string | null
  job_profile_name: string | null
  org_unit_name: string | null
  manager_name: string | null
  hire_date: string
  contract_end_date: string | null
  status: string
  base_salary?: Money | null
  level: number
  is_direct: boolean
  today_status: 'present' | 'absent' | 'on_vacation'
  team_actions: string[]
}

type Vacancy = { id: string; code: string; title: string; status: string; org_unit_name: string | null }
type TeamMeta = { total: number; direct: number; includes_indirect: boolean; today: Record<string, number>; vacant_positions: Vacancy[] }

const TODAY_TAG: Record<TeamMember['today_status'], string> = { present: 'ok', absent: 'wa', on_vacation: 'in' }

/** Enlaces de acción sobre un subordinado (la API dice cuáles puede iniciar el usuario). */
export function memberLinks(member: Pick<TeamMember, 'id' | 'team_actions'>): { key: string; href: string }[] {
  const links: { key: string; href: string }[] = []
  if (member.team_actions.includes('propose_movement')) links.push({ key: 'my_team.action_movement', href: `/personas/colaboradores/${member.id}?tab=history&accion=movimiento` })
  if (member.team_actions.includes('request_vacation')) links.push({ key: 'my_team.action_vacation', href: `/personas/colaboradores/${member.id}?tab=vacations` })
  return links
}

/** MSS-2 · Mi equipo: reportes directos e indirectos, estado de hoy, acceso a la ficha y vacantes. */
export function MyTeamScreen() {
  const { t, locale } = useT()
  const { can } = useSession()
  const [level, setLevel] = useState<'' | 'direct' | 'indirect'>('')
  const [q, setQ] = useState('')
  const [rows, setRows] = useState<TeamMember[] | null>(null)
  const [meta, setMeta] = useState<TeamMeta | null>(null)
  const [failed, setFailed] = useState(false)

  const load = useCallback(async () => {
    const params = new URLSearchParams()
    if (level) params.set('filter[level]', level)
    if (q.trim()) params.set('q', q.trim())
    try {
      const res = await api.get<{ data: TeamMember[]; meta: TeamMeta }>(`/my-team?${params.toString()}`)
      setRows(res.data)
      setMeta(res.meta)
      setFailed(false)
    } catch {
      setFailed(true)
    }
  }, [level, q])

  useEffect(() => {
    const timer = window.setTimeout(() => void load(), 250)
    return () => window.clearTimeout(timer)
  }, [load])

  const showSalary = can('people.employments.salary.view')

  return (
    <>
      <PageHeader title={t('my_team.title')} sub={meta ? t('my_team.sub', { n: meta.total, direct: meta.direct }) : undefined} />
      <main className="main" id="contenido" tabIndex={-1}>
        {meta && (
          <div className="hc-kpis mb-3">
            <div className="hc-kpi"><span className="sub2">{t('my_team.kpi_present')}</span><b>{meta.today.present ?? 0}</b></div>
            <div className="hc-kpi"><span className="sub2">{t('my_team.kpi_absent')}</span><b>{meta.today.absent ?? 0}</b></div>
            <div className="hc-kpi"><span className="sub2">{t('my_team.kpi_vacation')}</span><b>{meta.today.on_vacation ?? 0}</b></div>
            <div className="hc-kpi"><span className="sub2">{t('my_team.kpi_vacancies')}</span><b>{meta.vacant_positions.length}</b></div>
          </div>
        )}
        <section className="card">
          <div className="dt-barra" style={{ display: 'flex', gap: 8, flexWrap: 'wrap', padding: '12px 16px' }}>
            <input className="form-control" style={{ maxWidth: 280 }} type="search" aria-label={t('my_team.search')} placeholder={t('my_team.search')} value={q} onChange={(e) => setQ(e.target.value)} />
            <select className="form-select" style={{ maxWidth: 220 }} aria-label={t('my_team.level')} value={level} onChange={(e) => setLevel(e.target.value as typeof level)}>
              <option value="">{t('my_team.level_all')}</option>
              <option value="direct">{t('my_team.level_direct')}</option>
              {meta?.includes_indirect && <option value="indirect">{t('my_team.level_indirect')}</option>}
            </select>
          </div>
          {failed && <ErrorState text={t('common.load_error')} retryLabel={t('common.retry')} onRetry={() => void load()} />}
          {!failed && rows === null && <div className="card-body esq-grupo">{[1, 2, 3].map((i) => <span key={i} className="esq" style={{ width: `${40 + i * 15}%` }} />)}</div>}
          {!failed && rows?.length === 0 && <EmptyState title={t('my_team.empty_title')} text={t('my_team.empty_text')} />}
          {!failed && rows && rows.length > 0 && (
            <div className="table-responsive">
              <table className="table">
                <thead>
                  <tr>
                    <th scope="col">{t('my_team.col_person')}</th>
                    <th scope="col">{t('my_team.col_position')}</th>
                    <th scope="col">{t('my_team.col_level')}</th>
                    <th scope="col">{t('my_team.col_today')}</th>
                    <th scope="col">{t('my_team.col_contract_end')}</th>
                    {showSalary && <th scope="col" className="num">{t('my_team.col_salary')}</th>}
                    <th scope="col"><span className="visually-hidden">{t('my_team.col_actions')}</span></th>
                  </tr>
                </thead>
                <tbody>
                  {rows.map((m) => (
                    <tr key={m.id}>
                      <td>
                        <Link href={`/personas/colaboradores/${m.id}`}>{m.person_name ?? m.employee_code}</Link>
                        <div className="sub2">{m.employee_code}{m.is_direct ? '' : ` · ${t('my_team.reports_to', { name: m.manager_name ?? '—' })}`}</div>
                      </td>
                      <td>{m.position_title ?? m.job_profile_name ?? '—'}<div className="sub2">{m.org_unit_name ?? ''}</div></td>
                      <td><span className={`tag ${m.is_direct ? 'pe' : 'gr'}`}>{m.is_direct ? t('my_team.level_direct_one') : t('my_team.level_n', { n: m.level })}</span></td>
                      <td><span className={`tag ${TODAY_TAG[m.today_status]}`}>{t(`my_team.today_${m.today_status}`)}</span></td>
                      <td>{m.contract_end_date ? formatDate(locale, m.contract_end_date) : '—'}</td>
                      {showSalary && <td className="num">{m.base_salary ? `${m.base_salary.currency} ${formatNumber(locale, m.base_salary.amount)}` : '—'}</td>}
                      <td style={{ whiteSpace: 'nowrap' }}>
                        {memberLinks(m).map((l) => <Link key={l.key} className="btn btn-sec btn-sm" style={{ marginLeft: 4 }} href={l.href}>{t(l.key)}</Link>)}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </section>
        {meta && meta.vacant_positions.length > 0 && (
          <section className="card">
            <div className="card-body">
              <h2 className="inicio-titulo mb-3">{t('my_team.vacancies_title')}</h2>
              <ul className="inicio-items">
                {meta.vacant_positions.map((v) => (
                  <li key={v.id}>
                    <Link href={`/organizacion/posiciones?q=${encodeURIComponent(v.code)}`}>{v.title}</Link>
                    <span className="sub2">{v.org_unit_name ?? ''} · {t(`my_team.vacancy_${v.status}`)}</span>
                  </li>
                ))}
              </ul>
            </div>
          </section>
        )}
      </main>
    </>
  )
}
