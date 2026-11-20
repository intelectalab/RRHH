'use client'

import { useCallback, useEffect, useState } from 'react'
import { api } from '@/lib/api'
import { formatNumber } from '@/lib/format'
import { useT } from '@/lib/useT'
import { Notice } from '@/ui/components/Notice'
import { PageHeader } from '@/ui/components/PageHeader'
import { EmptyState } from '@/ui/components/States'
import { useToast } from '@/ui/components/Toast'

type Money = { amount: string; currency: string }
type Issue = { ref: string | null; field?: string; rule?: string; code?: string; structure?: string; concept_name?: string }
type FilingFile = { id: string; file_kind: string; file_name: string | null; record_count: number | null; sha256: string | null; superseded: boolean; created_at: string }
type Filing = {
  id: string; legal_entity_id: string; kind: string; period_code: string; status: string; is_rectification: boolean
  draft_only: boolean; due_on: string | null; overdue: boolean; external_reference: string | null; observation: string | null
  validation_report: { valid: boolean; errors: Issue[]; warnings: Issue[]; draft_reasons: string[] } | null; files: FilingFile[]
}
type Entity = { id: string; legal_name: string }
type Period = { id: string; period_year: number; period_number: number; status: string; legal_entity_id?: string }
type RegistryEvent = { id: string; name: string | null; employee_code: string | null; kind: string; event_date: string; due_on: string | null; due_pending_validation: boolean; status: string }
type Template = { code: string; name: string; bank_code: string | null; origin: string; verified: boolean; status: string }
type Certificate = { id: string; employment_id: string; fiscal_year: number; reason: string; issued_on: string | null; total_withheld?: Money | null; content_hash: string | null }

const ACTIONS: Record<string, string[]> = {
  draft: ['validate'], with_errors: ['validate'], generated: ['validate'], validated: ['validate', 'mark-submitted'],
  submitted: ['accept', 'observe'], observed: ['accept', 'rectify'], accepted: ['rectify'], rejected: ['rectify'],
}

/**
 * Nómina → Declaraciones (DEC-1 a DEC-6). La validación previa, los archivos (PLAME, T-Registro, AFPnet), los plazos,
 * la conciliación y los certificados los calcula el servidor; la pantalla muestra lo que responde y ejecuta las
 * acciones de la declaración. La empresa presenta en la herramienta oficial y aquí registra la constancia (ADR 0018).
 */
export function DeclarationsScreen() {
  const { t, locale } = useT()
  const { toast, node: toastNode } = useToast()
  const [tab, setTab] = useState<'filings' | 'registry' | 'banks' | 'certificates'>('filings')
  const [entities, setEntities] = useState<Entity[]>([])
  const [periods, setPeriods] = useState<Period[]>([])
  const [entity, setEntity] = useState('')
  const [kind, setKind] = useState('plame')
  const [period, setPeriod] = useState('')
  const [filings, setFilings] = useState<Filing[] | null>(null)
  const [events, setEvents] = useState<RegistryEvent[] | null>(null)
  const [templates, setTemplates] = useState<Template[] | null>(null)
  const [certificates, setCertificates] = useState<Certificate[] | null>(null)
  const [certEmployment, setCertEmployment] = useState('')
  const [certYear, setCertYear] = useState(String(new Date().getFullYear()))
  const [certReason, setCertReason] = useState('on_request')
  const [error, setError] = useState<string | null>(null)

  const fail = useCallback((e: unknown) => toast(e instanceof Error ? e.message : t('dec.error'), 'error'), [t, toast])

  const load = useCallback(async () => {
    setError(null)
    try {
      if (tab === 'filings') setFilings((await api.get<{ data: Filing[] }>('/declaration-files')).data)
      if (tab === 'registry') setEvents((await api.get<{ data: RegistryEvent[] }>(`/registry-events${entity ? `?legal_entity_id=${entity}` : ''}`)).data)
      if (tab === 'banks') setTemplates((await api.get<{ data: Template[] }>('/bank-file-templates')).data)
      if (tab === 'certificates') setCertificates((await api.get<{ data: Certificate[] }>('/withholding-certificates')).data)
    } catch (e) {
      setError(e instanceof Error ? e.message : t('dec.error'))
    }
  }, [tab, entity, t])

  useEffect(() => { void load() }, [load])
  useEffect(() => {
    void api.get<{ data: Entity[] }>('/legal-entities?per_page=100').then((r) => { setEntities(r.data); if (r.data[0]) setEntity((v) => v || r.data[0].id) }).catch(() => setEntities([]))
    void api.get<{ data: Period[] }>('/payroll-periods').then((r) => setPeriods(r.data)).catch(() => setPeriods([]))
  }, [])

  const run = async (fn: () => Promise<unknown>, done: string) => {
    try {
      await fn()
      toast(t(done))
      await load()
    } catch (e) {
      fail(e)
    }
  }

  const create = () => run(() => api.post('/declaration-files', { legal_entity_id: entity, kind, ...(kind === 't_registro' ? {} : { payroll_period_id: period }) }), 'dec.created')

  const act = (f: Filing, action: string) => {
    const body: Record<string, string> = {}
    if (action === 'mark-submitted') {
      const ref = window.prompt(t('dec.reference'))
      if (!ref) return
      body.external_reference = ref
    }
    if (action === 'observe') {
      const obs = window.prompt(t('dec.observation'))
      if (!obs) return
      body.observation = obs
    }
    if (action === 'rectify') {
      const reason = window.prompt(t('dec.rectify_reason'))
      if (!reason) return
      body.reason = reason
    }
    void run(() => api.post(`/declaration-files/${f.id}/${action}`, body), `dec.done_${action.replace('-', '_')}`)
  }

  const save = async (endpoint: string) => {
    try {
      const { blob, filename } = await api.download(endpoint)
      const url = URL.createObjectURL(blob)
      const link = document.createElement('a')
      link.href = url
      link.download = filename
      link.click()
      URL.revokeObjectURL(url)
    } catch (e) {
      fail(e)
    }
  }

  const issueText = (i: Issue) => [i.structure, i.field, i.concept_name].filter(Boolean).join(' · ') + ` — ${t(`dec.rule_${i.rule ?? i.code ?? 'other'}`)}`

  return (
    <>
      <PageHeader title={t('dec.title')} sub={t('dec.sub')} />
      <main className="main" id="contenido" tabIndex={-1}>
        <div className="seg mb-3" role="tablist" aria-label={t('dec.title')}>
          {(['filings', 'registry', 'banks', 'certificates'] as const).map((k) => (
            <button key={k} type="button" role="tab" aria-selected={tab === k} className={tab === k ? 'on' : undefined} onClick={() => setTab(k)}>{t(`dec.tab_${k}`)}</button>
          ))}
        </div>
        <div className="card" style={{ padding: 16, display: 'flex', gap: 12, alignItems: 'flex-end', flexWrap: 'wrap' }}>
          <div>
            <label className="form-label" htmlFor="dec-entity">{t('dec.entity')}</label>
            <select id="dec-entity" className="form-control" value={entity} onChange={(e) => setEntity(e.target.value)}>
              {entities.map((e) => <option key={e.id} value={e.id}>{e.legal_name}</option>)}
            </select>
          </div>
          {tab === 'filings' && (
            <>
              <div>
                <label className="form-label" htmlFor="dec-kind">{t('dec.kind')}</label>
                <select id="dec-kind" className="form-control" value={kind} onChange={(e) => setKind(e.target.value)}>
                  {['plame', 'afpnet', 't_registro'].map((k) => <option key={k} value={k}>{t(`dec.kind_${k}`)}</option>)}
                </select>
              </div>
              {kind !== 't_registro' && (
                <div>
                  <label className="form-label" htmlFor="dec-period">{t('dec.period')}</label>
                  <select id="dec-period" className="form-control" value={period} onChange={(e) => setPeriod(e.target.value)}>
                    <option value="">—</option>
                    {periods.map((p) => <option key={p.id} value={p.id}>{`${p.period_year}-${String(p.period_number).padStart(2, '0')} · ${p.status}`}</option>)}
                  </select>
                </div>
              )}
              <button type="button" className="btn btn-primary" disabled={!entity || (kind !== 't_registro' && !period)} onClick={() => void create()}>{t('dec.create')}</button>
            </>
          )}
          {tab === 'registry' && <button type="button" className="btn btn-primary" disabled={!entity} onClick={() => void run(() => api.post('/registry-events/sync', { legal_entity_id: entity }), 'dec.synced')}>{t('dec.sync')}</button>}
          {tab === 'certificates' && (
            <>
              <div>
                <label className="form-label" htmlFor="dec-cert-emp">{t('dec.employment')}</label>
                <input id="dec-cert-emp" className="form-control" value={certEmployment} onChange={(e) => setCertEmployment(e.target.value.trim())} />
              </div>
              <div>
                <label className="form-label" htmlFor="dec-cert-year">{t('dec.year')}</label>
                <input id="dec-cert-year" className="form-control" inputMode="numeric" value={certYear} onChange={(e) => setCertYear(e.target.value)} />
              </div>
              <div>
                <label className="form-label" htmlFor="dec-cert-reason">{t('dec.reason')}</label>
                <select id="dec-cert-reason" className="form-control" value={certReason} onChange={(e) => setCertReason(e.target.value)}>
                  {['annual', 'termination', 'on_request'].map((r) => <option key={r} value={r}>{t(`dec.reason_${r}`)}</option>)}
                </select>
              </div>
              <button type="button" className="btn btn-primary" disabled={!certEmployment || !/^\d{4}$/.test(certYear)}
                onClick={() => void run(() => api.post('/withholding-certificates', { employment_id: certEmployment, fiscal_year: Number(certYear), reason: certReason }), 'dec.issued')}>{t('dec.issue')}</button>
            </>
          )}
        </div>
        {error && <Notice tone="error">{error}</Notice>}

        {tab === 'filings' && filings !== null && (filings.length === 0 ? <EmptyState title={t('dec.empty_title')} text={t('dec.empty_text')} /> : filings.map((f) => (
          <section key={f.id} className="card">
            <div className="card-header">
              <h2>{`${t(`dec.kind_${f.kind}`)} · ${f.period_code}`}{f.is_rectification ? ` · ${t('dec.rectification')}` : ''}</h2>
              <span className="sub2">{t(`dec.status_${f.status}`)}{f.due_on ? ` · ${t('dec.due', { date: f.due_on })}` : ''}{f.overdue ? ` · ${t('dec.overdue')}` : ''}</span>
            </div>
            <div style={{ padding: '0 16px 16px' }}>
              {f.draft_only && <Notice tone="warn">{t('dec.draft_only')}</Notice>}
              {f.observation && <Notice tone="warn">{f.observation}</Notice>}
              {f.validation_report && f.validation_report.errors.length > 0 && (
                <Notice tone="error">
                  {t('dec.errors', { n: f.validation_report.errors.length })}
                  <ul>{f.validation_report.errors.slice(0, 20).map((i, n) => <li key={n}>{issueText(i)}</li>)}</ul>
                </Notice>
              )}
              {f.validation_report && f.validation_report.warnings.length > 0 && (
                <Notice tone="warn"><ul>{f.validation_report.warnings.map((i, n) => <li key={n}>{issueText(i)}</li>)}</ul></Notice>
              )}
              {f.files.filter((x) => !x.superseded).length > 0 && (
                <table className="table">
                  <thead><tr><th>{t('dec.col_file')}</th><th>{t('dec.col_records')}</th><th>{t('dec.col_hash')}</th><th /></tr></thead>
                  <tbody>
                    {f.files.filter((x) => !x.superseded).map((x) => (
                      <tr key={x.id}>
                        <td>{x.file_name ?? x.file_kind}</td>
                        <td>{x.record_count === null ? '—' : formatNumber(locale, String(x.record_count))}</td>
                        <td><code>{x.sha256?.slice(0, 12)}</code></td>
                        <td><button type="button" className="btn btn-ter" onClick={() => void save(`/declaration-files/${f.id}/files/${x.id}/file`)}>{t('dec.download')}</button></td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              )}
              <div style={{ display: 'flex', gap: 8, marginTop: 8 }}>
                {(ACTIONS[f.status] ?? []).map((a) => <button key={a} type="button" className={a === 'validate' ? 'btn btn-primary btn-sm' : 'btn btn-sec btn-sm'} onClick={() => act(f, a)}>{t(`dec.action_${a.replace('-', '_')}`)}</button>)}
              </div>
            </div>
          </section>
        )))}

        {tab === 'registry' && events !== null && (events.length === 0 ? <EmptyState title={t('dec.registry_empty_title')} text={t('dec.registry_empty_text')} /> : (
          <section className="card">
            <table className="table">
              <thead><tr><th>{t('dec.col_person')}</th><th>{t('dec.col_event')}</th><th>{t('dec.col_date')}</th><th>{t('dec.col_due')}</th><th>{t('dec.col_status')}</th></tr></thead>
              <tbody>
                {events.map((e) => (
                  <tr key={e.id}>
                    <td>{e.name ?? '—'}<div className="sub2">{e.employee_code}</div></td>
                    <td>{t(`dec.event_${e.kind}`)}</td>
                    <td>{e.event_date}</td>
                    <td>{e.due_on ?? (e.due_pending_validation ? t('dec.due_pending') : '—')}</td>
                    <td>{t(`dec.event_status_${e.status}`)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </section>
        ))}

        {tab === 'banks' && templates !== null && (
          <section className="card">
            <Notice tone="info">{t('dec.banks_note')}</Notice>
            <table className="table">
              <thead><tr><th>{t('dec.col_template')}</th><th>{t('dec.col_bank')}</th><th>{t('dec.col_origin')}</th><th>{t('dec.col_status')}</th></tr></thead>
              <tbody>
                {templates.map((x) => (
                  <tr key={`${x.origin}-${x.code}`}>
                    <td>{x.name}<div className="sub2"><code>{x.code}</code></div></td>
                    <td>{x.bank_code ?? '—'}</td>
                    <td>{t(`dec.origin_${x.origin}`)}</td>
                    <td>{x.verified ? t('dec.verified') : t('dec.to_validate')} · {t(`dec.template_${x.status}`)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </section>
        )}

        {tab === 'certificates' && certificates !== null && (certificates.length === 0 ? <EmptyState title={t('dec.cert_empty_title')} text={t('dec.cert_empty_text')} /> : (
          <section className="card">
            <table className="table">
              <thead><tr><th>{t('dec.year')}</th><th>{t('dec.reason')}</th><th>{t('dec.col_date')}</th><th>{t('dec.col_withheld')}</th><th /></tr></thead>
              <tbody>
                {certificates.map((c) => (
                  <tr key={c.id}>
                    <td>{c.fiscal_year}</td>
                    <td>{t(`dec.reason_${c.reason}`)}</td>
                    <td>{c.issued_on ?? '—'}</td>
                    <td>{c.total_withheld ? `${c.total_withheld.currency} ${formatNumber(locale, c.total_withheld.amount)}` : '—'}</td>
                    <td><button type="button" className="btn btn-ter" onClick={() => void save(`/withholding-certificates/${c.id}/certificate`)}>{t('dec.download')}</button></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </section>
        ))}
        {toastNode}
      </main>
    </>
  )
}
