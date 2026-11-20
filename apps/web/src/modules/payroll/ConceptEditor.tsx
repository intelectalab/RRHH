'use client'

import Link from 'next/link'
import { useRouter } from 'next/navigation'
import { useCallback, useEffect, useRef, useState } from 'react'
import { api } from '@/lib/api'
import { formErrors } from '@/lib/errors'
import { formatDate, formatNumber, localToday } from '@/lib/format'
import { useT } from '@/lib/useT'
import { useSession } from '@/modules/session/SessionProvider'
import { Icon } from '@/ui/components/Icon'
import { Notice } from '@/ui/components/Notice'
import { PageHeader } from '@/ui/components/PageHeader'
import { PersonPicker, type Person } from '@/ui/components/PersonPicker'
import { ErrorState } from '@/ui/components/States'
import { StatusBadge, Tag } from '@/ui/components/Status'
import { Tabs } from '@/ui/components/Tabs'
import {
  PROJECTION_KINDS, ROUNDINGS, STATUS_TONE, WORKER_CATEGORIES, suggestions, toggleBase, wordAt,
  type Account, type AccountingRule, type Affectation, type Base, type PayrollConcept, type Review,
} from './concepts'

type Draft = {
  name: string; category: string; is_remunerative: boolean; print_on_payslip: boolean; statutory_code: string
  formula: string; condition: string; rounding: string; affectations: Affectation[]; valid_from: string; notes: string
}
type Vocabulary = { variables: string[]; functions: string[] }
type TestLine = { concept_code: string; concept_name: string; amount: { amount: string; currency: string } }
type TestResult = { applied: boolean; status: string; errors: Array<{ code: string; message: string }>; lines: TestLine[] }
type RuleRow = { legal_entity_id: string; cost_center_id: string; worker_category: string; debit_account_id: string; credit_account_id: string }

function draftOf(c: PayrollConcept): Draft {
  const d = c.draft
  return {
    name: c.name, category: c.category ?? '', is_remunerative: c.is_remunerative, print_on_payslip: c.print_on_payslip,
    statutory_code: c.statutory_code ?? '',
    formula: (d ? d.formula : c.formula) ?? '', condition: (d ? d.condition : c.condition) ?? '',
    rounding: (d ? d.rounding : c.rounding) ?? 'half_up', affectations: d ? d.affectations : c.affectations,
    valid_from: d?.valid_from ?? localToday(), notes: d?.notes ?? '',
  }
}

/** CPT-1 · CPT-2 · CPT-3 · CPT-4 · Ficha de un concepto: borrador, revisión, publicación, probador y contabilización. */
export function ConceptEditor({ id }: { id: string }) {
  const { t, locale } = useT()
  const { can } = useSession()
  const router = useRouter()
  const [concept, setConcept] = useState<PayrollConcept | null>(null)
  const [failed, setFailed] = useState(false)
  const [draft, setDraft] = useState<Draft | null>(null)
  const [bases, setBases] = useState<Base[]>([])
  const [vocab, setVocab] = useState<Vocabulary>({ variables: [], functions: [] })
  const [concepts, setConcepts] = useState<string[]>([])
  const [review, setReview] = useState<Review | null>(null)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [general, setGeneral] = useState<string | null>(null)
  const [ok, setOk] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const [tab, setTab] = useState('definition')
  const [focus, setFocus] = useState<{ field: 'formula' | 'condition'; word: string } | null>(null)
  const canEdit = can('payroll.concepts.update')

  const load = useCallback(() => {
    setFailed(false)
    void api.get<{ data: PayrollConcept }>(`/payroll-concepts/${id}`).then((r) => {
      setConcept(r.data); setDraft(draftOf(r.data)); setReview(r.data.draft?.validation ?? null)
    }).catch(() => setFailed(true))
  }, [id])
  useEffect(load, [load])
  useEffect(() => {
    void api.get<{ data: Base[] }>('/payroll-concept-bases').then((r) => setBases(r.data)).catch(() => setBases([]))
    void api.get<{ data: PayrollConcept[] }>('/payroll-concepts').then((r) => setConcepts(r.data.map((c) => c.code))).catch(() => setConcepts([]))
  }, [])

  // Revisión en vivo de la fórmula (el servidor la analiza; aquí solo se muestra).
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null)
  const formula = draft?.formula ?? ''
  const code = concept?.code
  useEffect(() => {
    if (!code || !can('payroll.concepts.test')) return
    if (timer.current) clearTimeout(timer.current)
    timer.current = setTimeout(() => {
      void api.post<{ data: Review & { variables: Record<string, string> | string[]; functions: string[] } }>('/payroll-formulas/validate', { formula: formula === '' ? '0' : formula, concept_code: code })
        .then((r) => {
          const v = r.data.variables
          setVocab({ variables: Array.isArray(v) ? v : Object.keys(v), functions: r.data.functions })
          if (formula !== '') setReview((prev) => ({ ...(prev ?? { valid: true, errors: [] }), valid: r.data.valid, errors: r.data.errors, depends_on: r.data.depends_on }))
        })
        .catch(() => undefined)
    }, 400)
    return () => { if (timer.current) clearTimeout(timer.current) }
  }, [formula, code, can])

  if (failed) return <main className="main"><ErrorState text={t('pcon.load_error')} retryLabel={t('pcon.retry')} onRetry={load} /></main>
  if (!concept || !draft) return <main className="main"><p className="text-secondary">{t('pcon.loading')}</p></main>

  const set = <K extends keyof Draft>(k: K, v: Draft[K]) => setDraft({ ...draft, [k]: v })
  const err = (k: string) => errors[k] && <div className="invalid-feedback d-block" role="alert">{errors[k]}</div>
  const reset = () => { setErrors({}); setGeneral(null); setOk(null) }
  const fail = (e: unknown) => { const f = formErrors(e, t); setErrors(f.fields); setGeneral(f.general) }

  async function run(action: () => Promise<unknown>, done: string) {
    setBusy(true); reset()
    try { await action(); setOk(done); load() } catch (e) { fail(e) } finally { setBusy(false) }
  }
  const save = () => run(() => api.request(`/payroll-concepts/${id}`, 'PATCH', {
    name: draft.name, category: draft.category || null, is_remunerative: draft.is_remunerative, print_on_payslip: draft.print_on_payslip,
    ...(can('payroll.concepts.map') ? { statutory_code: draft.statutory_code || null } : {}),
    formula: draft.formula || null, condition: draft.condition || null, rounding: draft.rounding,
    affectations: draft.affectations, valid_from: draft.valid_from, notes: draft.notes || null,
  }), t('pcon.saved'))
  const check = () => run(async () => { const r = await api.post<{ data: Review }>(`/payroll-concepts/${id}/validate`, {}); setReview(r.data) }, t('pcon.checked'))
  const publish = () => run(() => api.post(`/payroll-concepts/${id}/publish`, {}), t('pcon.published'))
  const retire = () => run(() => api.post(`/payroll-concepts/${id}/retire`, {}), t('pcon.retired'))
  async function duplicate() {
    setBusy(true); reset()
    try {
      const r = await api.post<{ data: PayrollConcept }>(`/payroll-concepts/${id}/duplicate`, { code: `${concept!.code}_copia`, name: `${concept!.name} (${t('pcon.copy')})` })
      router.push(`/planilla/conceptos/${r.data.id}`)
    } catch (e) { fail(e) } finally { setBusy(false) }
  }

  const sources = { variables: vocab.variables, functions: vocab.functions, concepts: concepts.filter((c) => c !== concept.code), bases: bases.map((b) => b.code) }
  const hints = focus ? suggestions(focus.word, sources) : []
  function insert(field: 'formula' | 'condition', s: string) {
    const text = draft![field]
    const word = focus?.word ?? ''
    const base = word === '' ? text : text.slice(0, text.length - word.length)
    set(field, base + s)
    setFocus(null)
  }
  const onType = (field: 'formula' | 'condition', value: string, caret: number) => {
    set(field, value)
    setFocus({ field, word: wordAt(value, caret) })
  }
  const allowed = (a: string) => concept.allowed_actions.includes(a)

  const definition = (
    <div className="row g-4 mt-1">
      <section className="col-lg-7" aria-labelledby="pc-formula-h">
        <h2 className="h6" id="pc-formula-h">{t('pcon.formula')}</h2>
        {(['formula', 'condition'] as const).map((field) => (
          <div key={field} className="mb-3 position-relative">
            <label className="form-label" htmlFor={`pc-${field}`}>{t(`pcon.f_${field}`)}</label>
            <textarea id={`pc-${field}`} className="form-control font-monospace" rows={field === 'formula' ? 4 : 2} value={draft[field]} disabled={!canEdit}
              onChange={(e) => onType(field, e.target.value, e.target.selectionStart)} onBlur={() => setTimeout(() => setFocus(null), 150)} spellCheck={false} />
            <p className="small text-secondary mt-1">{t(`pcon.${field}_hint`)}</p>
            {focus?.field === field && hints.length > 0 && (
              <ul className="list-unstyled d-flex flex-wrap gap-1" aria-label={t('pcon.suggestions')}>
                {hints.map((h) => <li key={h}><button type="button" className="btn btn-ter btn-sm font-monospace" onMouseDown={(e) => { e.preventDefault(); insert(field, h) }}>{h}</button></li>)}
              </ul>
            )}
            {err(field)}
          </div>
        ))}
        {concept.template?.note && <Notice tone="info" title={t('pcon.template')}>{concept.template.note}</Notice>}
        {review && (
          <div aria-live="polite">
            {review.valid ? <Notice tone="ok">{t('pcon.review_ok')}</Notice> : (
              <Notice tone="error" title={t('pcon.review_errors')}>
                <ul className="mb-0">{review.errors.map((e, i) => <li key={i}>{e.message}</li>)}</ul>
              </Notice>
            )}
            {(review.warnings ?? []).length > 0 && <Notice tone="warn"><ul className="mb-0">{review.warnings!.map((w, i) => <li key={i}>{w.message}</li>)}</ul></Notice>}
            {(review.depends_on ?? []).length > 0 && <p className="small">{t('pcon.depends_on')}: <span className="font-monospace">{review.depends_on!.join(', ')}</span></p>}
          </div>
        )}
        <div className="row g-2">
          <div className="col-sm-6">
            <label className="form-label" htmlFor="pc-rounding">{t('pcon.f_rounding')}</label>
            <select id="pc-rounding" className="form-select" value={draft.rounding} disabled={!canEdit} onChange={(e) => set('rounding', e.target.value)}>
              {ROUNDINGS.map((r) => <option key={r} value={r}>{t(`pcon.rounding_${r}`)}</option>)}
            </select>
          </div>
          <div className="col-sm-6">
            <label className="form-label" htmlFor="pc-from">{t('pcon.f_valid_from')}</label>
            <input id="pc-from" type="date" className="form-control" value={draft.valid_from} disabled={!canEdit} onChange={(e) => set('valid_from', e.target.value)} />
            {err('valid_from')}
          </div>
        </div>
        <label className="form-label mt-3" htmlFor="pc-notes">{t('pcon.f_notes')}</label>
        <textarea id="pc-notes" className="form-control" rows={2} value={draft.notes} disabled={!canEdit} onChange={(e) => set('notes', e.target.value)} />
      </section>
      <section className="col-lg-5" aria-labelledby="pc-data-h">
        <h2 className="h6" id="pc-data-h">{t('pcon.data')}</h2>
        <label className="form-label" htmlFor="pc-name">{t('pcon.f_name')}</label>
        <input id="pc-name" className="form-control" value={draft.name} disabled={!canEdit} onChange={(e) => set('name', e.target.value)} />
        {err('name')}
        <label className="form-label mt-3" htmlFor="pc-cat">{t('pcon.f_category')}</label>
        <input id="pc-cat" className="form-control" value={draft.category} disabled={!canEdit} onChange={(e) => set('category', e.target.value)} />
        <label className="form-label mt-3" htmlFor="pc-stat">{t('pcon.f_statutory')}</label>
        <input id="pc-stat" className="form-control" value={draft.statutory_code} disabled={!canEdit || !can('payroll.concepts.map')} onChange={(e) => set('statutory_code', e.target.value)} />
        {concept.statutory_code && !concept.statutory_code_verified && <p className="small mt-1"><Tag tone="wa">{t('pcon.to_validate')}</Tag></p>}
        {err('statutory_code')}
        <div className="form-check mt-3">
          <input id="pc-rem" type="checkbox" className="form-check-input" checked={draft.is_remunerative} disabled={!canEdit || concept.is_system} onChange={(e) => set('is_remunerative', e.target.checked)} />
          <label className="form-check-label" htmlFor="pc-rem">{t('pcon.f_remunerative')}</label>
        </div>
        <div className="form-check">
          <input id="pc-print" type="checkbox" className="form-check-input" checked={draft.print_on_payslip} disabled={!canEdit} onChange={(e) => set('print_on_payslip', e.target.checked)} />
          <label className="form-check-label" htmlFor="pc-print">{t('pcon.f_print')}</label>
        </div>
        <h2 className="h6 mt-4" id="pc-aff-h">{t('pcon.affectations')}</h2>
        <p className="small text-secondary">{t('pcon.affectations_hint')}</p>
        <fieldset aria-labelledby="pc-aff-h">
          {bases.map((b) => {
            const a = draft.affectations.find((x) => x.base_code === b.code && x.affects)
            const patch = (p: Partial<Affectation>) => set('affectations', draft.affectations.map((x) => x.base_code === b.code ? { ...x, ...p } : x))
            return (
              <div key={b.code} className="mb-2">
                <div className="form-check">
                  <input id={`pc-b-${b.code}`} type="checkbox" className="form-check-input" checked={!!a} disabled={!canEdit || concept.is_system}
                    onChange={() => set('affectations', toggleBase(draft.affectations, b.code))} />
                  <label className="form-check-label" htmlFor={`pc-b-${b.code}`}>{b.name}</label>
                </div>
                {a && b.requires_projection && (
                  <select className="form-select form-select-sm mt-1" aria-label={t('pcon.projection')} value={a.projection_kind ?? ''} disabled={!canEdit || concept.is_system} onChange={(e) => patch({ projection_kind: e.target.value || null })}>
                    <option value="">{t('pcon.projection')}</option>
                    {PROJECTION_KINDS.map((p) => <option key={p} value={p}>{t(`pcon.projection_${p}`)}</option>)}
                  </select>
                )}
                {a && !concept.is_system && (
                  <input className="form-control form-control-sm mt-1" aria-label={t('pcon.justification')} placeholder={t('pcon.justification')} value={a.justification ?? ''} disabled={!canEdit} onChange={(e) => patch({ justification: e.target.value })} />
                )}
              </div>
            )
          })}
        </fieldset>
        {err('affectations')}
      </section>
    </div>
  )

  return (
    <>
      <PageHeader
        title={concept.name}
        sub={<><Link href="/planilla/conceptos">{t('pcon.title')}</Link> · <span className="font-monospace">{concept.code}</span> · <StatusBadge tone={STATUS_TONE[concept.status]}>{t(`pcon.status_${concept.status}`)}</StatusBadge>{concept.is_system && <> <Tag tone="in">{t('pcon.system')}</Tag></>}</>}
        actions={<>
          {canEdit && <button type="button" className="btn btn-sec" disabled={busy} onClick={() => void save()}>{t('pcon.save')}</button>}
          {canEdit && <button type="button" className="btn btn-sec" disabled={busy} onClick={() => void check()}>{t('pcon.check')}</button>}
          {allowed('publish') && can('payroll.concepts.publish') && <button type="button" className="btn btn-primary" disabled={busy} onClick={() => void publish()}><Icon name="check" /> {t('pcon.publish')}</button>}
          {can('payroll.concepts.create') && <button type="button" className="btn btn-ter" disabled={busy} onClick={() => void duplicate()}>{t('pcon.duplicate')}</button>}
          {allowed('retire') && can('payroll.concepts.retire') && <button type="button" className="btn btn-ter" disabled={busy} onClick={() => void retire()}>{t('pcon.retire')}</button>}
        </>}
      />
      <main className="main" id="contenido" tabIndex={-1}>
        {general && <Notice tone="error">{general}</Notice>}
        {ok && <Notice tone="ok" onDismiss={() => setOk(null)}>{ok}</Notice>}
        {concept.has_draft && concept.status !== 'draft' && <Notice tone="warn">{t('pcon.draft_pending')}</Notice>}
        <Tabs label={concept.name} value={tab} onChange={setTab} items={[
          { id: 'definition', label: t('pcon.tab_definition'), content: definition },
          { id: 'test', label: t('pcon.tab_test'), content: tab === 'test' ? <Tester id={id} /> : null, disabled: !can('payroll.concepts.test') },
          {
            id: 'versions', label: t('pcon.tab_versions'), count: concept.versions?.length, content: (
              (concept.versions ?? []).length === 0 ? <p className="text-secondary mt-3">{t('pcon.no_versions')}</p> : (
                <table className="table table-sm mt-3">
                  <thead><tr><th scope="col">{t('pcon.col_valid_from')}</th><th scope="col">{t('pcon.col_valid_to')}</th><th scope="col">{t('pcon.f_formula')}</th><th scope="col">{t('pcon.published_by')}</th></tr></thead>
                  <tbody>{concept.versions!.map((v) => (
                    <tr key={v.id}><td>{formatDate(locale, v.valid_from)}</td><td>{v.valid_to ? formatDate(locale, v.valid_to) : '—'}</td><td className="font-monospace small">{v.formula ?? '—'}{v.condition && <span className="d-block text-secondary">{t('pcon.if')} {v.condition}</span>}</td><td>{v.published_by_name ?? '—'}</td></tr>
                  ))}</tbody>
                </table>
              )
            ),
          },
          { id: 'accounting', label: t('pcon.tab_accounting'), content: tab === 'accounting' ? <AccountingRules id={id} rules={concept.accounting ?? []} onSaved={load} /> : null },
        ]} />
      </main>
    </>
  )
}

/** CPT-2 · Probador: calcula el borrador para una persona y un rango sin guardar nada. */
function Tester({ id }: { id: string }) {
  const { t, locale } = useT()
  const [person, setPerson] = useState<Person | null>(null)
  const today = localToday()
  const [from, setFrom] = useState(`${today.slice(0, 7)}-01`)
  const [to, setTo] = useState(today)
  const [result, setResult] = useState<TestResult | null>(null)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [general, setGeneral] = useState<string | null>(null)
  const search = useCallback(async (q: string): Promise<Person[]> => {
    const p = new URLSearchParams({ per_page: '10', 'filter[status]': 'active' })
    if (q !== '') p.set('q', q)
    const r = await api.get<{ data: Array<{ id: string; person_name?: string | null; employee_code: string }> }>(`/employments?${p.toString()}`)
    return r.data.map((e) => ({ id: e.id, name: e.person_name ?? e.employee_code, detail: e.employee_code }))
  }, [])
  async function run() {
    if (!person) return
    setErrors({}); setGeneral(null); setResult(null)
    try {
      const r = await api.post<{ data: TestResult }>(`/payroll-concepts/${id}/test`, { employment_id: person.id, from, to })
      setResult(r.data)
    } catch (e) { const f = formErrors(e, t); setErrors(f.fields); setGeneral(f.general) }
  }
  return (
    <div className="mt-3">
      <p className="small text-secondary">{t('pcon.test_hint')}</p>
      <div className="row g-2 align-items-end">
        <div className="col-md-5"><PersonPicker label={t('pcon.test_person')} value={person} onChange={setPerson} search={search} required /></div>
        <div className="col-md-3"><label className="form-label" htmlFor="pt-from">{t('pcon.test_from')}</label><input id="pt-from" type="date" className="form-control" value={from} onChange={(e) => setFrom(e.target.value)} /></div>
        <div className="col-md-3"><label className="form-label" htmlFor="pt-to">{t('pcon.test_to')}</label><input id="pt-to" type="date" className="form-control" value={to} onChange={(e) => setTo(e.target.value)} />{errors.to && <div className="invalid-feedback d-block">{errors.to}</div>}</div>
        <div className="col-md-1"><button type="button" className="btn btn-primary w-100" disabled={!person} onClick={() => void run()}>{t('pcon.test_run')}</button></div>
      </div>
      {general && <div className="mt-2"><Notice tone="error">{general}</Notice></div>}
      {result && (
        <div className="mt-3" aria-live="polite">
          {result.errors.length > 0 && <Notice tone="error"><ul className="mb-0">{result.errors.map((e, i) => <li key={i}>{e.message}</li>)}</ul></Notice>}
          {!result.applied && <Notice tone="info">{t('pcon.test_not_applied')}</Notice>}
          <table className="table table-sm">
            <thead><tr><th scope="col">{t('pcon.col_name')}</th><th scope="col" className="text-end">{t('pcon.amount')}</th></tr></thead>
            <tbody>{result.lines.map((l) => <tr key={l.concept_code}><td>{l.concept_name} <span className="small text-secondary">{l.concept_code}</span></td><td className="text-end">{l.amount.currency} {formatNumber(locale, l.amount.amount)}</td></tr>)}</tbody>
          </table>
        </div>
      )}
    </div>
  )
}

/** CPT-4 · Reglas contables del concepto (regla general + excepciones por empresa, centro de costo o categoría). */
function AccountingRules({ id, rules, onSaved }: { id: string; rules: AccountingRule[]; onSaved: () => void }) {
  const { t } = useT()
  const { can } = useSession()
  const canMap = can('payroll.concepts.map')
  const [accounts, setAccounts] = useState<Account[]>([])
  const [rows, setRows] = useState<RuleRow[]>(() => rules.filter((r) => r.valid_to === null).map((r) => ({
    legal_entity_id: r.legal_entity_id ?? '', cost_center_id: r.cost_center_id ?? '', worker_category: r.worker_category ?? '',
    debit_account_id: r.debit_account_id ?? '', credit_account_id: r.credit_account_id ?? '',
  })))
  const [from, setFrom] = useState(localToday())
  const [general, setGeneral] = useState<string | null>(null)
  const [ok, setOk] = useState(false)
  useEffect(() => { void api.get<{ data: Account[] }>('/accounting-accounts').then((r) => setAccounts(r.data)).catch(() => setAccounts([])) }, [])
  const blank: RuleRow = { legal_entity_id: '', cost_center_id: '', worker_category: '', debit_account_id: '', credit_account_id: '' }
  const patch = (i: number, p: Partial<RuleRow>) => setRows(rows.map((r, j) => (j === i ? { ...r, ...p } : r)))
  async function save() {
    setGeneral(null); setOk(false)
    try {
      await api.request(`/payroll-concepts/${id}/accounting`, 'PUT', {
        valid_from: from,
        rules: rows.map((r) => ({ legal_entity_id: r.legal_entity_id || null, cost_center_id: r.cost_center_id || null, worker_category: r.worker_category || null, debit_account_id: r.debit_account_id, credit_account_id: r.credit_account_id })),
      })
      setOk(true); onSaved()
    } catch (e) { setGeneral(formErrors(e, t).general ?? t('pcon.load_error')) }
  }
  const accountSelect = (i: number, k: 'debit_account_id' | 'credit_account_id', label: string) => (
    <select className="form-select form-select-sm" aria-label={label} value={rows[i][k]} disabled={!canMap} onChange={(e) => patch(i, { [k]: e.target.value })}>
      <option value="">—</option>
      {accounts.map((a) => <option key={a.id} value={a.id}>{a.code} · {a.name}</option>)}
    </select>
  )
  return (
    <div className="mt-3">
      <p className="small text-secondary">{t('pcon.acc_hint')}</p>
      {general && <Notice tone="error">{general}</Notice>}
      {ok && <Notice tone="ok">{t('pcon.saved')}</Notice>}
      {accounts.length === 0 && <Notice tone="info">{t('pcon.acc_empty_text')}</Notice>}
      <table className="table table-sm">
        <thead><tr><th scope="col">{t('pcon.acc_category')}</th><th scope="col">{t('pcon.acc_debit')}</th><th scope="col">{t('pcon.acc_credit')}</th><th scope="col"><span className="visually-hidden">{t('pcon.remove')}</span></th></tr></thead>
        <tbody>{rows.map((r, i) => (
          <tr key={i}>
            <td>
              <select className="form-select form-select-sm" aria-label={t('pcon.acc_category')} value={r.worker_category} disabled={!canMap} onChange={(e) => patch(i, { worker_category: e.target.value })}>
                <option value="">{t('pcon.acc_general')}</option>
                {WORKER_CATEGORIES.map((c) => <option key={c} value={c}>{t(`pcon.cat_${c}`)}</option>)}
              </select>
            </td>
            <td>{accountSelect(i, 'debit_account_id', t('pcon.acc_debit'))}</td>
            <td>{accountSelect(i, 'credit_account_id', t('pcon.acc_credit'))}</td>
            <td>{canMap && <button type="button" className="btn btn-ter btn-sm" aria-label={t('pcon.remove')} onClick={() => setRows(rows.filter((_, j) => j !== i))}><Icon name="trash" size={16} /></button>}</td>
          </tr>
        ))}</tbody>
      </table>
      {canMap && (
        <div className="d-flex flex-wrap gap-2 align-items-end">
          <button type="button" className="btn btn-ter" onClick={() => setRows([...rows, blank])}><Icon name="plus" /> {t('pcon.acc_add_rule')}</button>
          <div><label className="form-label" htmlFor="ar-from">{t('pcon.f_valid_from')}</label><input id="ar-from" type="date" className="form-control" value={from} onChange={(e) => setFrom(e.target.value)} /></div>
          <button type="button" className="btn btn-primary" onClick={() => void save()}>{t('pcon.save')}</button>
        </div>
      )}
    </div>
  )
}
