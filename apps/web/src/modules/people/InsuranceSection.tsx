'use client'

import { useCallback, useEffect, useState } from 'react'
import { api } from '@/lib/api'
import { formErrors } from '@/lib/errors'
import { formatDate, localToday } from '@/lib/format'
import { useT } from '@/lib/useT'
import { useSession } from '@/modules/session/SessionProvider'
import { Card } from '@/ui/components/Card'
import { Modal } from '@/ui/components/Modal'
import { Notice } from '@/ui/components/Notice'
import { StatusBadge } from '@/ui/components/Status'
import { percentTotal, saveFromApi } from './fileDownload'
import type { PeopleSectionProps } from './sectionProps'

type Coverage = { id: string; insurance_policy_id: string; kind: string | null; insurer: string | null; policy_number: string | null; covered_from: string; covered_to: string | null; plan_code: string | null; is_current: boolean }
type Policy = { id: string; kind: string; insurer: string; policy_number: string; starts_on: string; ends_on: string | null; status: string }
type Beneficiary = { full_name: string; document_number_masked?: string | null; relationship: string; percentage: string }
type Declaration = { id: string; status: 'current' | 'superseded'; declared_on: string | null; signed_on: string | null; has_signed_file: boolean; beneficiaries: Beneficiary[] }
type BenForm = { full_name: string; document_number: string; relationship: string; percentage: string }

const BEN_RELATIONSHIPS = ['spouse', 'partner', 'child', 'parent', 'sibling', 'other'] as const
const EMPTY_BEN: BenForm = { full_name: '', document_number: '', relationship: 'spouse', percentage: '' }

/**
 * COL-7 · Coberturas de pólizas (SCTR, Vida Ley, EPS) y declaración de beneficiarios de Vida Ley. Los
 * beneficiarios son datos personales: la sección de declaración solo aparece con permiso de datos personales.
 */
export function InsuranceSection({ employmentId, legalEntityId, onToast }: PeopleSectionProps & { legalEntityId?: string }) {
  const { t, locale } = useT()
  const { can } = useSession()
  const base = `/employments/${employmentId}`
  const [coverages, setCoverages] = useState<Coverage[] | null>(null)
  const [declarations, setDeclarations] = useState<Declaration[] | null>(null)
  const [policies, setPolicies] = useState<Policy[]>([])
  const [failed, setFailed] = useState(false)
  const [enroll, setEnroll] = useState<{ insurance_policy_id: string; covered_from: string; plan_code: string } | null>(null)
  const [ending, setEnding] = useState<Coverage | null>(null)
  const [endDate, setEndDate] = useState('')
  const [declare, setDeclare] = useState<{ rows: BenForm[]; signed_on: string; file: File | null } | null>(null)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [general, setGeneral] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const personal = can('people.people.personal_data.view')
  const editable = can('people.employments.update')

  const load = useCallback(async () => {
    try {
      setCoverages((await api.get<{ data: Coverage[] }>(`${base}/insurance-coverages`)).data)
      if (personal) setDeclarations((await api.get<{ data: Declaration[] }>(`${base}/life-insurance-declarations`)).data)
      setFailed(false)
    } catch {
      setFailed(true)
    }
  }, [base, personal])

  useEffect(() => { void load() }, [load])

  const date = (v: string | null) => (v ? formatDate(locale, v) : '—')
  const reset = () => { setErrors({}); setGeneral(null) }

  async function run(action: () => Promise<unknown>, done: () => void) {
    setBusy(true); reset()
    try {
      await action()
      done()
      onToast(t('ssec.saved'))
      await load()
    } catch (e) {
      const f = formErrors(e, t)
      setErrors(f.fields); setGeneral(f.general ?? (Object.keys(f.fields).some((k) => k.startsWith('beneficiaries.')) ? Object.values(f.fields)[0] : null))
    } finally {
      setBusy(false)
    }
  }

  async function openEnroll() {
    reset()
    try {
      // Las pólizas son de la empresa empleadora; si la pestaña no la pasa, se toma de la relación.
      const entity = legalEntityId ?? (await api.get<{ data: { legal_entity_id: string } }>(base)).data.legal_entity_id
      setPolicies((await api.get<{ data: Policy[] }>(`/insurance-policies?legal_entity_id=${encodeURIComponent(entity)}&status=active`)).data)
    } catch {
      setPolicies([])
    }
    setEnroll({ insurance_policy_id: '', covered_from: localToday(), plan_code: '' })
  }

  function submitDeclaration() {
    if (!declare) return
    const form = new FormData()
    form.append('beneficiaries', JSON.stringify(declare.rows.map((r) => ({ ...r, document_number: r.document_number || null, percentage: r.percentage.replace(',', '.') }))))
    form.append('signed_on', declare.signed_on)
    if (declare.file) form.append('file', declare.file, declare.file.name)
    void run(() => api.postForm(`${base}/life-insurance-declarations`, form), () => setDeclare(null))
  }

  const err = (k: string) => errors[k] && <div className="invalid-feedback d-block" role="alert">{errors[k]}</div>
  const total = declare ? percentTotal(declare.rows.map((r) => r.percentage)) : 0
  const currentDecl = declarations?.find((d) => d.status === 'current')

  return (
    <Card
      title={t('ins.title')}
      context={t('ins.context')}
      actions={editable ? <button type="button" className="btn btn-sec btn-sm" onClick={() => void openEnroll()}>{t('ins.enroll')}</button> : undefined}
    >
      {failed && <Notice tone="error">{t('common.load_error')}</Notice>}
      {coverages !== null && coverages.length === 0 && <p className="text-secondary">{t('ins.empty')}</p>}
      {coverages !== null && coverages.length > 0 && (
        <ul className="sec-lista">
          {coverages.map((c) => (
            <li key={c.id}>
              <div className="sec-fila">
                <div>
                  <b>{c.kind ? t(`ins.kind_${c.kind}`) : '—'}</b>
                  <span className="text-secondary small">{[c.insurer, c.policy_number, c.plan_code].filter(Boolean).join(' · ')} · {date(c.covered_from)} → {date(c.covered_to)}</span>
                </div>
                <div className="sec-acciones">
                  <StatusBadge tone={c.is_current ? 'ok' : 'gr'}>{c.is_current ? t('ins.current') : t('ins.ended')}</StatusBadge>
                  {editable && c.covered_to === null && <button type="button" className="btn btn-ter btn-sm" onClick={() => { reset(); setEndDate(localToday()); setEnding(c) }}>{t('ins.end')}</button>}
                </div>
              </div>
            </li>
          ))}
        </ul>
      )}

      {personal && (
        <section className="mt-4" aria-labelledby="ins-life">
          <div className="sec-titulo">
            <h3 id="ins-life">{t('ins.life_title')}</h3>
            {editable && can('people.people.personal_data.edit') && (
              <button type="button" className="btn btn-ter btn-sm" onClick={() => { reset(); setDeclare({ rows: [{ ...EMPTY_BEN, percentage: '100' }], signed_on: localToday(), file: null }) }}>{t('ins.life_new')}</button>
            )}
          </div>
          {declarations !== null && !currentDecl && <p className="text-secondary">{t('ins.life_empty')}</p>}
          {currentDecl && (
            <>
              <p className="small text-secondary">{t('ins.life_signed', { date: date(currentDecl.signed_on) })}</p>
              <ul className="sec-historia">
                {currentDecl.beneficiaries.map((b, i) => (
                  <li key={i}>{b.full_name} · {t(`ins.rel_${b.relationship}`)}{b.document_number_masked ? ` · ${b.document_number_masked}` : ''} · {t('ins.percent', { n: b.percentage })}</li>
                ))}
              </ul>
              {currentDecl.has_signed_file && (
                <button type="button" className="btn btn-ter btn-sm" onClick={() => { void saveFromApi(`${base}/life-insurance-declarations/${currentDecl.id}/file`).catch(() => onToast(t('common.load_error'), 'error')) }}>{t('ins.life_download')}</button>
              )}
            </>
          )}
          {declarations !== null && declarations.length > 1 && <p className="small text-secondary mt-2">{t('ins.life_previous', { n: declarations.length - 1 })}</p>}
        </section>
      )}

      {enroll && (
        <Modal
          title={t('ins.enroll_title')}
          onClose={() => setEnroll(null)}
          footer={(
            <>
              <button type="button" className="btn btn-sec" onClick={() => setEnroll(null)}>{t('org.cancel')}</button>
              <button type="button" className="btn btn-primary" disabled={busy} aria-busy={busy}
                onClick={() => void run(() => api.post(`${base}/insurance-coverages`, { ...enroll, plan_code: enroll.plan_code || null }), () => setEnroll(null))}>{t('ssec.save')}</button>
            </>
          )}
        >
          {general && <Notice tone="error">{general}</Notice>}
          {policies.length === 0 && <Notice tone="info">{t('ins.no_policies')}</Notice>}
          <label className="form-label" htmlFor="ins-policy">{t('ins.f_policy')}</label>
          <select id="ins-policy" className="form-select" value={enroll.insurance_policy_id} onChange={(e) => setEnroll({ ...enroll, insurance_policy_id: e.target.value })}>
            <option value="">{t('ssec.choose')}</option>
            {policies.map((p) => <option key={p.id} value={p.id}>{`${t(`ins.kind_${p.kind}`)} · ${p.insurer} · ${p.policy_number}`}</option>)}
          </select>
          {err('insurance_policy_id')}
          <label className="form-label mt-3" htmlFor="ins-from">{t('ins.f_from')}</label>
          <input id="ins-from" type="date" className="form-control" value={enroll.covered_from} onChange={(e) => setEnroll({ ...enroll, covered_from: e.target.value })} />
          {err('covered_from')}
          <label className="form-label mt-3" htmlFor="ins-plan">{t('ssec.f_plan')}</label>
          <input id="ins-plan" className="form-control" value={enroll.plan_code} onChange={(e) => setEnroll({ ...enroll, plan_code: e.target.value })} />
        </Modal>
      )}

      {ending && (
        <Modal
          title={t('ins.end_title')}
          onClose={() => setEnding(null)}
          footer={(
            <>
              <button type="button" className="btn btn-sec" onClick={() => setEnding(null)}>{t('org.cancel')}</button>
              <button type="button" className="btn btn-danger" disabled={busy} aria-busy={busy}
                onClick={() => void run(() => api.post(`${base}/insurance-coverages/${ending.id}/end`, { covered_to: endDate }), () => setEnding(null))}>{t('ins.end')}</button>
            </>
          )}
        >
          {general && <Notice tone="error">{general}</Notice>}
          <label className="form-label" htmlFor="ins-to">{t('ins.f_to')}</label>
          <input id="ins-to" type="date" className="form-control" value={endDate} onChange={(e) => setEndDate(e.target.value)} />
          {err('covered_to')}
        </Modal>
      )}

      {declare && (
        <Modal
          title={t('ins.life_new')}
          size="l"
          onClose={() => setDeclare(null)}
          footer={(
            <>
              <button type="button" className="btn btn-sec" onClick={() => setDeclare(null)}>{t('org.cancel')}</button>
              <button type="button" className="btn btn-primary" disabled={busy} aria-busy={busy} onClick={submitDeclaration}>{t('ssec.save')}</button>
            </>
          )}
        >
          {general && <Notice tone="error">{general}</Notice>}
          <p className="small text-secondary">{t('ins.life_hint')}</p>
          {declare.rows.map((r, i) => {
            const set = (patch: Partial<BenForm>) => setDeclare({ ...declare, rows: declare.rows.map((x, j) => (j === i ? { ...x, ...patch } : x)) })
            return (
              <fieldset key={i} className="sec-beneficiario">
                <legend className="small">{t('ins.beneficiary', { n: i + 1 })}</legend>
                <label className="form-label" htmlFor={`ben-name-${i}`}>{t('dep.f_name')}</label>
                <input id={`ben-name-${i}`} className="form-control" value={r.full_name} onChange={(e) => set({ full_name: e.target.value })} />
                {err(`beneficiaries.${i}.full_name`)}
                <div className="sec-dos">
                  <div>
                    <label className="form-label mt-2" htmlFor={`ben-doc-${i}`}>{t('dep.f_doc_number')}</label>
                    <input id={`ben-doc-${i}`} className="form-control" value={r.document_number} onChange={(e) => set({ document_number: e.target.value })} />
                  </div>
                  <div>
                    <label className="form-label mt-2" htmlFor={`ben-rel-${i}`}>{t('dep.f_relationship')}</label>
                    <select id={`ben-rel-${i}`} className="form-select" value={r.relationship} onChange={(e) => set({ relationship: e.target.value })}>
                      {BEN_RELATIONSHIPS.map((x) => <option key={x} value={x}>{t(`ins.rel_${x}`)}</option>)}
                    </select>
                  </div>
                </div>
                <label className="form-label mt-2" htmlFor={`ben-pct-${i}`}>{t('ins.f_percentage')}</label>
                <input id={`ben-pct-${i}`} inputMode="decimal" className="form-control" value={r.percentage} onChange={(e) => set({ percentage: e.target.value })} />
                {err(`beneficiaries.${i}.percentage`)}
                {declare.rows.length > 1 && (
                  <button type="button" className="btn btn-ter btn-sm mt-2" onClick={() => setDeclare({ ...declare, rows: declare.rows.filter((_, j) => j !== i) })}>{t('ins.remove')}</button>
                )}
              </fieldset>
            )
          })}
          <button type="button" className="btn btn-ter btn-sm" onClick={() => setDeclare({ ...declare, rows: [...declare.rows, EMPTY_BEN] })}>{t('ins.add_beneficiary')}</button>
          <p className={`small mt-2 ${total === 100 ? 'text-secondary' : 'sec-aviso'}`} aria-live="polite">{t('ins.total', { n: total })}</p>
          {err('beneficiaries')}
          <label className="form-label mt-3" htmlFor="ben-signed">{t('ins.f_signed_on')}</label>
          <input id="ben-signed" type="date" className="form-control" value={declare.signed_on} onChange={(e) => setDeclare({ ...declare, signed_on: e.target.value })} />
          {err('signed_on')}
          <label className="form-label mt-3" htmlFor="ben-file">{t('ins.f_file')}</label>
          <input id="ben-file" type="file" accept="application/pdf,image/jpeg,image/png" className="form-control" onChange={(e) => setDeclare({ ...declare, file: e.target.files?.[0] ?? null })} />
          {err('file')}
        </Modal>
      )}
    </Card>
  )
}
