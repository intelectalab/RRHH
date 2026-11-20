'use client'

import { useCallback, useEffect, useState, type FormEvent } from 'react'
import { api } from '@/lib/api'
import { formatDate, formatDateTime } from '@/lib/format'
import { useT } from '@/lib/useT'
import { useSession } from '@/modules/session/SessionProvider'
import { Notice } from '@/ui/components/Notice'
import { PageHeader } from '@/ui/components/PageHeader'
import { EmptyState } from '@/ui/components/States'
import { StatusBadge } from '@/ui/components/Status'
import { Tabs } from '@/ui/components/Tabs'
import { useToast } from '@/ui/components/Toast'
import { formatValue, isTab, TABS, type FundRate, type LegalParameter, type LegalParameterDetail, type LegalUpdate, type RegimeParameters, type TabId, type WageTable } from './legalParameters'

const REGIMES = ['pe_general', 'pe_micro', 'pe_pequena', 'pe_formativo']

/**
 * Nómina → Parámetros legales (PAR-1 a PAR-7). Valores del país con su norma e historial, valor propio donde la
 * norma lo permite, confirmación de un «por validar», tablas por AFP del periodo, escala de quinta, parámetros de
 * cada régimen (y cuál falta), tablas salariales por convenio con su verificación y novedades normativas con los
 * periodos que tocan. Todo lo decide el servidor; la pantalla consulta y envía.
 */
export function LegalParametersScreen() {
  const { t, locale } = useT()
  const { can } = useSession()
  const { toast, node: toastNode } = useToast()
  const [tab, setTab] = useState<TabId>(() => {
    if (typeof window === 'undefined') return 'parametros'
    const v = new URLSearchParams(window.location.search).get('vista')
    return isTab(v) ? v : 'parametros'
  })
  const [asOf, setAsOf] = useState(() => new Date().toISOString().slice(0, 10))
  const [error, setError] = useState<string | null>(null)

  const fail = (e: unknown) => setError(e instanceof Error ? e.message : t('par.error'))

  const items = TABS.map((id) => ({
    id, label: t(`par.tab_${id}`),
    content: tab !== id ? null : id === 'parametros' ? <ParametersTab asOf={asOf} onError={fail} toast={toast} />
      : id === 'afp' ? <FundsTab asOf={asOf} onError={fail} />
      : id === 'quinta' ? <FifthTab asOf={asOf} onError={fail} />
      : id === 'regimenes' ? <RegimesTab asOf={asOf} onError={fail} />
      : id === 'tablas' ? <WageTablesTab asOf={asOf} onError={fail} toast={toast} canVerify={can('payroll.wage_tables.verify')} />
      : <UpdatesTab onError={fail} />,
  }))

  return (
    <>
      <PageHeader title={t('par.title')} sub={t('par.sub')} />
      <main className="main" id="contenido" tabIndex={-1}>
        {error && <Notice tone="error" onDismiss={() => setError(null)}>{error}</Notice>}
        {tab !== 'novedades' && (
          <div style={{ display: 'flex', gap: 8, alignItems: 'flex-end', marginBottom: 12 }}>
            <div>
              <label className="form-label" htmlFor="par-asof">{t('par.as_of')}</label>
              <input id="par-asof" className="form-control" type="date" value={asOf} onChange={(e) => e.target.value !== '' && setAsOf(e.target.value)} />
            </div>
            <span className="sub2">{t('par.as_of_hint', { date: formatDate(locale, asOf) })}</span>
          </div>
        )}
        <Tabs items={items} value={tab} onChange={(id) => { setError(null); setTab(id as TabId) }} label={t('par.title')} />
      </main>
      {toastNode}
    </>
  )
}

type TabProps = { asOf: string; onError: (e: unknown) => void }

function ParametersTab({ asOf, onError, toast }: TabProps & { toast: (m: string) => void }) {
  const { t, locale } = useT()
  const { can } = useSession()
  const [rows, setRows] = useState<LegalParameter[] | null>(null)
  const [q, setQ] = useState('')
  const [pending, setPending] = useState(false)
  const [detail, setDetail] = useState<LegalParameterDetail | null>(null)
  const [own, setOwn] = useState({ value: '', valid_from: '', reason: '' })

  const load = useCallback(() => {
    const qs = new URLSearchParams({ as_of: asOf })
    if (q.trim() !== '') qs.set('q', q.trim())
    if (pending) qs.set('filter[pending]', '1')
    api.get<{ data: LegalParameter[] }>(`/legal-parameters?${qs.toString()}`).then((r) => setRows(r.data)).catch(onError)
  }, [asOf, q, pending, onError])

  useEffect(() => { load() }, [load])

  const open = (id: string) => api.get<{ data: LegalParameterDetail }>(`/legal-parameters/${id}`).then((r) => setDetail(r.data)).catch(onError)

  const confirm = async (id: string) => {
    try {
      const r = await api.post<{ data: LegalParameterDetail }>(`/legal-parameters/${id}/confirm`, {})
      setDetail(r.data)
      toast(t('par.confirmed'))
      load()
    } catch (e) { onError(e) }
  }

  const addOwn = async (e: FormEvent<HTMLFormElement>) => {
    e.preventDefault()
    if (!detail) return
    try {
      await api.post(`/legal-parameters/${detail.id}/add-value`, own)
      toast(t('par.own_saved'))
      setOwn({ value: '', valid_from: '', reason: '' })
      void open(detail.id)
    } catch (err) { onError(err) }
  }

  return (
    <section className="card">
      <div className="card-header" style={{ gap: 8, flexWrap: 'wrap' }}>
        <input className="form-control" style={{ maxWidth: 260 }} placeholder={t('par.search')} aria-label={t('par.search')} value={q} onChange={(e) => setQ(e.target.value)} />
        <label style={{ display: 'flex', gap: 6, alignItems: 'center' }}><input type="checkbox" checked={pending} onChange={(e) => setPending(e.target.checked)} />{t('par.only_pending')}</label>
      </div>
      {rows !== null && rows.length === 0 ? <EmptyState title={t('par.empty')} text={t('par.empty_text')} /> : (
        <div className="tabla-wrap">
          <table className="table">
            <thead><tr><th>{t('par.col_parameter')}</th><th>{t('par.col_regime')}</th><th>{t('par.col_value')}</th><th>{t('par.col_validity')}</th><th>{t('par.col_source')}</th><th>{t('par.col_status')}</th></tr></thead>
            <tbody>
              {(rows ?? []).map((p) => (
                <tr key={p.id}>
                  <td><button type="button" className="link-btn" onClick={() => void open(p.id)}>{p.name}</button><div className="sub2">{p.code}</div></td>
                  <td>{p.regime ?? t('par.all_regimes')}</td>
                  <td>{formatValue(p.value, p.unit, locale)}</td>
                  <td>{formatDate(locale, p.valid_from)}{p.valid_to ? ` – ${formatDate(locale, p.valid_to)}` : ''}</td>
                  <td>{p.legal_source ?? '—'}</td>
                  <td>{p.pending_validation ? <StatusBadge tone="wa">{p.confirmed ? t('par.confirmed_by_org') : t('par.pending')}</StatusBadge> : <StatusBadge tone="ok">{t('par.verified')}</StatusBadge>}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      {detail && (
        <div className="card" style={{ margin: 16, padding: 16 }} role="region" aria-label={detail.name}>
          <div style={{ display: 'flex', justifyContent: 'space-between', gap: 8 }}>
            <h3>{detail.name}</h3>
            <button type="button" className="btn btn-sec btn-sm" onClick={() => setDetail(null)}>{t('par.close')}</button>
          </div>
          <h4>{t('par.history')}</h4>
          <ul>
            {detail.history.map((h) => (
              <li key={h.id}>{formatDate(locale, h.valid_from)}{h.valid_to ? ` – ${formatDate(locale, h.valid_to)}` : ''}: <strong>{formatValue(h.value, detail.unit, locale)}</strong> · {h.legal_source ?? '—'}{h.pending_validation ? ` · ${t('par.pending')}` : ''}</li>
            ))}
          </ul>
          {detail.pending_validation && detail.value !== null && !detail.confirmed && can('payroll.legal_parameters.confirm') && (
            <Notice tone="warn" action={<button type="button" className="btn btn-primary btn-sm" onClick={() => void confirm(detail.id)}>{t('par.confirm')}</button>}>{t('par.confirm_hint')}</Notice>
          )}
          {detail.confirmations.map((c) => <p key={c.id} className="sub2">{t('par.confirmed_line', { who: c.confirmed_by, when: formatDateTime(locale, c.confirmed_at) })}</p>)}
          {detail.own_values.length > 0 && (
            <>
              <h4>{t('par.own_values')}</h4>
              <ul>{detail.own_values.map((o) => <li key={o.id}>{formatDate(locale, o.valid_from)}: <strong>{formatValue(o.value, detail.unit, locale)}</strong> · {o.reason}</li>)}</ul>
            </>
          )}
          {detail.adjustable !== null && can('payroll.legal_parameters.add_value') && (
            <form onSubmit={(e) => void addOwn(e)} style={{ display: 'flex', gap: 8, flexWrap: 'wrap', alignItems: 'flex-end' }}>
              <p className="sub2" style={{ width: '100%' }}>{t(detail.adjustable === 'mayor' ? 'par.adjust_higher' : 'par.adjust_lower')}</p>
              <div><label className="form-label" htmlFor="par-own-value">{t('par.own_value')}</label><input id="par-own-value" className="form-control" inputMode="decimal" value={own.value} onChange={(e) => setOwn((o) => ({ ...o, value: e.target.value }))} required /></div>
              <div><label className="form-label" htmlFor="par-own-from">{t('par.valid_from')}</label><input id="par-own-from" className="form-control" type="date" value={own.valid_from} onChange={(e) => setOwn((o) => ({ ...o, valid_from: e.target.value }))} required /></div>
              <div style={{ flex: 1, minWidth: 200 }}><label className="form-label" htmlFor="par-own-reason">{t('par.reason')}</label><input id="par-own-reason" className="form-control" value={own.reason} onChange={(e) => setOwn((o) => ({ ...o, reason: e.target.value }))} required /></div>
              <button type="submit" className="btn btn-primary">{t('par.save_own')}</button>
            </form>
          )}
        </div>
      )}
    </section>
  )
}

function FundsTab({ asOf, onError }: TabProps) {
  const { t, locale } = useT()
  const [data, setData] = useState<{ funds: FundRate[]; missing: string[] } | null>(null)
  useEffect(() => { api.get<{ data: { funds: FundRate[]; missing: string[] } }>(`/pension-fund-rates?as_of=${asOf}`).then((r) => setData(r.data)).catch(onError) }, [asOf, onError])
  if (!data) return null
  return (
    <section className="card">
      {data.missing.length > 0 && <Notice tone="warn">{t('par.funds_missing', { funds: data.missing.join(', ') })}</Notice>}
      <div className="tabla-wrap">
        <table className="table">
          <thead><tr><th>{t('par.fund')}</th><th>{t('par.contribution')}</th><th>{t('par.flow')}</th><th>{t('par.mixed')}</th><th>{t('par.premium')}</th><th>{t('par.max_insurable')}</th><th>{t('par.col_source')}</th></tr></thead>
          <tbody>
            {data.funds.map((f) => (
              <tr key={f.fund_code}>
                <td>{f.fund_name}</td>
                {f.missing ? <td colSpan={6} className="sub2">{t('par.no_table')}</td> : (
                  <>
                    <td>{formatValue(f.contribution_rate, 'percent', locale)}</td><td>{formatValue(f.commission_flow_rate, 'percent', locale)}</td>
                    <td>{formatValue(f.commission_mixed_rate, 'percent', locale)}</td><td>{formatValue(f.insurance_premium_rate, 'percent', locale)}</td>
                    <td>{formatValue(f.max_insurable_remuneration, 'amount', locale)}</td><td>{f.source ?? '—'}</td>
                  </>
                )}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </section>
  )
}

type FifthData = Record<string, { code: string; value: unknown; unit: string; valid_from: string; legal_source: string | null; pending_validation: boolean } | null> & { missing?: string[] }

function FifthTab({ asOf, onError }: TabProps) {
  const { t, locale } = useT()
  const [data, setData] = useState<FifthData | null>(null)
  useEffect(() => { api.get<{ data: FifthData }>(`/fifth-category-scale?as_of=${asOf}`).then((r) => setData(r.data)).catch(onError) }, [asOf, onError])
  if (!data) return null
  const scale = data.scale?.value
  const tranches = Array.isArray(scale) ? (scale as Array<{ hasta_uit: number | null; tasa: number }>) : []
  return (
    <section className="card">
      {(data.missing ?? []).length > 0 && <Notice tone="warn">{t('par.fifth_missing', { codes: (data.missing ?? []).join(', ') })}</Notice>}
      <div className="tabla-wrap">
        <table className="table">
          <thead><tr><th>{t('par.tranche')}</th><th>{t('par.rate')}</th></tr></thead>
          <tbody>
            {tranches.map((tr, i) => (
              <tr key={i}><td>{tr.hasta_uit === null ? t('par.over_uit', { n: String(tranches[i - 1]?.hasta_uit ?? 0) }) : t('par.up_to_uit', { n: String(tr.hasta_uit) })}</td><td>{formatValue(tr.tasa, 'percent', locale)}</td></tr>
            ))}
          </tbody>
        </table>
      </div>
      <p className="sub2" style={{ padding: 16 }}>{t('par.deduction', { n: formatValue(data.deduction_uit?.value, 'multiple', locale) })} · {data.scale?.legal_source ?? ''}</p>
    </section>
  )
}

function RegimesTab({ asOf, onError }: TabProps) {
  const { t, locale } = useT()
  const [regime, setRegime] = useState(REGIMES[0] ?? 'pe_general')
  const [data, setData] = useState<RegimeParameters | null>(null)
  useEffect(() => { api.get<{ data: RegimeParameters }>(`/payroll-regimes/${regime}/parameters?as_of=${asOf}`).then((r) => setData(r.data)).catch(onError) }, [regime, asOf, onError])
  return (
    <section className="card">
      <div className="card-header">
        <label className="form-label" htmlFor="par-regime">{t('par.col_regime')}</label>
        <select id="par-regime" className="form-select" style={{ maxWidth: 240 }} value={regime} onChange={(e) => setRegime(e.target.value)}>
          {REGIMES.map((r) => <option key={r} value={r}>{t(`par.regime_${r}`)}</option>)}
        </select>
      </div>
      {data && (data.can_calculate ? <Notice tone="ok">{t('par.regime_ok')}</Notice> : <Notice tone="error">{t('par.regime_missing', { codes: data.missing.join(', ') })}</Notice>)}
      <div className="tabla-wrap">
        <table className="table">
          <thead><tr><th>{t('par.col_parameter')}</th><th>{t('par.col_value')}</th><th>{t('par.origin')}</th><th>{t('par.col_status')}</th></tr></thead>
          <tbody>
            {(data?.parameters ?? []).map((p) => (
              <tr key={p.code}>
                <td>{p.code}{p.required ? ` · ${t('par.required')}` : ''}</td><td>{formatValue(p.value, p.unit, locale)}</td><td>{p.origin ?? '—'}</td>
                <td>{p.missing ? <StatusBadge tone="er">{t('par.missing')}</StatusBadge> : p.pending_validation ? <StatusBadge tone="wa">{t('par.pending')}</StatusBadge> : <StatusBadge tone="ok">{t('par.verified')}</StatusBadge>}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </section>
  )
}

function WageTablesTab({ asOf, onError, toast, canVerify }: TabProps & { toast: (m: string) => void; canVerify: boolean }) {
  const { t, locale } = useT()
  const [rows, setRows] = useState<WageTable[] | null>(null)
  const [acta, setActa] = useState('')
  const load = useCallback(() => { api.get<{ data: WageTable[] }>(`/wage-tables?as_of=${asOf}`).then((r) => setRows(r.data)).catch(onError) }, [asOf, onError])
  useEffect(() => { load() }, [load])
  const verify = async (id: string) => {
    try {
      await api.post(`/wage-tables/${id}/verify`, { acta_reference: acta })
      toast(t('par.wage_verified'))
      setActa('')
      load()
    } catch (e) { onError(e) }
  }
  if (rows !== null && rows.length === 0) return <EmptyState title={t('par.no_wage_tables')} text={t('par.no_wage_tables_text')} />
  return (
    <>
      {(rows ?? []).map((w) => (
        <section key={w.id} className="card" style={{ marginBottom: 12 }}>
          <div className="card-header"><h2>{w.name}</h2><span className="sub2">{formatDate(locale, w.valid_from)}{w.valid_to ? ` – ${formatDate(locale, w.valid_to)}` : ''} · {w.legal_source ?? ''}</span></div>
          {w.expired && <Notice tone="warn">{t('par.wage_expired')}</Notice>}
          <div className="tabla-wrap">
            <table className="table">
              <tbody>{w.values.map((v) => <tr key={v.code}><td>{v.name}</td><td>{formatValue(v.value, v.unit, locale)}</td></tr>)}</tbody>
            </table>
          </div>
          {w.verified && w.verification
            ? <p className="sub2" style={{ padding: 16 }}>{t('par.wage_verified_line', { acta: w.verification.acta_reference, who: w.verification.verified_by })}</p>
            : canVerify && (
              <div style={{ display: 'flex', gap: 8, padding: 16, alignItems: 'flex-end' }}>
                <div style={{ flex: 1 }}><label className="form-label" htmlFor={`acta-${w.id}`}>{t('par.acta')}</label><input id={`acta-${w.id}`} className="form-control" value={acta} onChange={(e) => setActa(e.target.value)} /></div>
                <button type="button" className="btn btn-primary" disabled={acta.trim() === ''} onClick={() => void verify(w.id)}>{t('par.verify')}</button>
              </div>
            )}
        </section>
      ))}
    </>
  )
}

function UpdatesTab({ onError }: { onError: (e: unknown) => void }) {
  const { t, locale } = useT()
  const [rows, setRows] = useState<LegalUpdate[] | null>(null)
  const [regime, setRegime] = useState('')
  useEffect(() => {
    const qs = regime === '' ? '' : `?filter[regime]=${regime}`
    api.get<{ data: LegalUpdate[] }>(`/legal-updates${qs}`).then((r) => setRows(r.data)).catch(onError)
  }, [regime, onError])
  return (
    <section className="card">
      <div className="card-header">
        <label className="form-label" htmlFor="par-upd-regime">{t('par.col_regime')}</label>
        <select id="par-upd-regime" className="form-select" style={{ maxWidth: 240 }} value={regime} onChange={(e) => setRegime(e.target.value)}>
          <option value="">{t('par.all_regimes')}</option>
          {REGIMES.map((r) => <option key={r} value={r}>{t(`par.regime_${r}`)}</option>)}
        </select>
      </div>
      {rows !== null && rows.length === 0 ? <EmptyState title={t('par.no_updates')} text={t('par.no_updates_text')} /> : (
        <ul style={{ listStyle: 'none', padding: 16, margin: 0, display: 'grid', gap: 12 }}>
          {(rows ?? []).map((u) => (
            <li key={u.id} className="card" style={{ padding: 12 }}>
              <strong>{u.name}</strong> <span className="sub2">({u.code}{u.regime ? ` · ${u.regime}` : ''})</span>
              <div>{t('par.update_line', { before: formatValue(u.previous_value, u.unit, locale), after: formatValue(u.new_value, u.unit, locale), from: formatDate(locale, u.valid_from) })}</div>
              <div className="sub2">{u.legal_source ?? '—'} · {formatDateTime(locale, u.published_at)}</div>
              {u.suggests_simulation && <Notice tone="warn">{t('par.update_open', { n: String(u.open_periods.length) })}</Notice>}
              {u.suggests_complementary && <Notice tone="info">{t('par.update_closed', { n: String(u.closed_periods.length) })}</Notice>}
            </li>
          ))}
        </ul>
      )}
    </section>
  )
}
