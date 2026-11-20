'use client'

import Link from 'next/link'
import { useRouter } from 'next/navigation'
import { useCallback, useEffect, useMemo, useState } from 'react'
import { api } from '@/lib/api'
import { formErrors } from '@/lib/errors'
import { useT } from '@/lib/useT'
import { useSession } from '@/modules/session/SessionProvider'
import { DataTable, type TableColumn, type TableFilter } from '@/ui/components/DataTable'
import { Icon } from '@/ui/components/Icon'
import { Modal } from '@/ui/components/Modal'
import { Notice } from '@/ui/components/Notice'
import { PageHeader } from '@/ui/components/PageHeader'
import { EmptyState } from '@/ui/components/States'
import { StatusBadge, Tag } from '@/ui/components/Status'
import { Tabs } from '@/ui/components/Tabs'
import { affectedBases, CONCEPT_KINDS, STATUS_TONE, type Account, type Base, type PayrollConcept } from './concepts'

type NewForm = { code: string; name: string; kind: string }
type Matrix = { bases: Base[]; concepts: Array<Pick<PayrollConcept, 'id' | 'code' | 'name' | 'kind' | 'status' | 'has_draft' | 'affectations'>> }

/**
 * CPT-1 · CPT-3 · CPT-4 · Planilla → Conceptos: catálogo (los del paquete del país y los propios), matriz de
 * afectaciones (conceptos × bases) y plan de cuentas de la planilla.
 */
export function ConceptsScreen() {
  const { t } = useT()
  const { can } = useSession()
  const router = useRouter()
  const [tab, setTab] = useState('concepts')
  const [form, setForm] = useState<NewForm | null>(null)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [general, setGeneral] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const [baseNames, setBaseNames] = useState<Record<string, string>>({})
  useEffect(() => {
    void api.get<{ data: Base[] }>('/payroll-concept-bases').then((r) => setBaseNames(Object.fromEntries(r.data.map((b) => [b.code, b.name])))).catch(() => setBaseNames({}))
  }, [])

  const columns = useMemo<Array<TableColumn<PayrollConcept>>>(() => [
    {
      key: 'name', label: t('pcon.col_name'), hideable: false,
      render: (r) => <><Link href={`/nomina/conceptos/${r.id}`}>{r.name}</Link>{r.is_system && <> <Tag tone="in">{t('pcon.system')}</Tag></>}<span className="small text-secondary d-block">{r.code}</span></>,
    },
    { key: 'kind', label: t('pcon.col_kind'), render: (r) => t(`pcon.kind_${r.kind}`) },
    { key: 'affects', label: t('pcon.col_affects'), render: (r) => affectedBases(r.affectations).map((b) => baseNames[b] ?? b).join(', ') || '—' },
    { key: 'statutory', label: t('pcon.col_statutory'), render: (r) => r.statutory_code ?? '—' },
    { key: 'valid_from', label: t('pcon.col_valid_from'), render: (r) => r.valid_from ?? '—' },
    {
      key: 'status', label: t('pcon.col_status'),
      render: (r) => <><StatusBadge tone={STATUS_TONE[r.status]}>{t(`pcon.status_${r.status}`)}</StatusBadge>{r.has_draft && r.status !== 'draft' && <> <Tag tone="wa">{t('pcon.has_draft')}</Tag></>}</>,
    },
  ], [t, baseNames])

  const filters: TableFilter[] = [
    { key: 'status', label: t('pcon.f_status'), options: (['draft', 'active', 'inactive'] as const).map((s) => ({ value: s, label: t(`pcon.status_${s}`) })) },
    { key: 'kind', label: t('pcon.f_kind'), options: CONCEPT_KINDS.map((k) => ({ value: k, label: t(`pcon.kind_${k}`) })) },
  ]

  async function create() {
    if (!form) return
    setBusy(true); setErrors({}); setGeneral(null)
    try {
      const r = await api.post<{ data: PayrollConcept }>('/payroll-concepts', form)
      router.push(`/nomina/conceptos/${r.data.id}`)
    } catch (e) {
      const f = formErrors(e, t)
      setErrors(f.fields); setGeneral(f.general)
    } finally {
      setBusy(false)
    }
  }
  const err = (k: string) => errors[k] && <div className="invalid-feedback d-block" role="alert">{errors[k]}</div>

  return (
    <>
      <PageHeader title={t('pcon.title')} sub={t('pcon.sub')} actions={can('payroll.concepts.create') ? (
        <button type="button" className="btn btn-primary" onClick={() => { setErrors({}); setGeneral(null); setForm({ code: '', name: '', kind: 'earning' }) }}>
          <Icon name="plus" /> {t('pcon.new')}
        </button>
      ) : undefined} />
      <main className="main" id="contenido" tabIndex={-1}>
        <Tabs label={t('pcon.title')} value={tab} onChange={setTab} items={[
          {
            id: 'concepts', label: t('pcon.tab_concepts'), content: (
              <DataTable<PayrollConcept>
                resource="payroll-concepts"
                endpoint="/payroll-concepts"
                defaultSort=""
                searchLabel={t('pcon.search')}
                rowKey={(r) => r.id}
                columns={columns}
                filters={filters}
                empty={<EmptyState title={t('pcon.empty_title')} text={t('pcon.empty_text')} />}
                rowActions={(r) => <Link className="btn btn-ter btn-sm" href={`/nomina/conceptos/${r.id}`}>{t('pcon.open')}</Link>}
              />
            ),
          },
          { id: 'matrix', label: t('pcon.tab_matrix'), content: tab === 'matrix' ? <AffectationMatrix /> : null },
          { id: 'accounts', label: t('pcon.tab_accounts'), content: tab === 'accounts' ? <AccountsPanel /> : null },
        ]} />
      </main>
      {form && (
        <Modal title={t('pcon.new')} onClose={() => setForm(null)} footer={<>
          <button type="button" className="btn btn-sec" onClick={() => setForm(null)}>{t('org.cancel')}</button>
          <button type="button" className="btn btn-primary" disabled={busy} aria-busy={busy} onClick={() => void create()}>{t('pcon.create')}</button>
        </>}>
          {general && <Notice tone="error">{general}</Notice>}
          <label className="form-label" htmlFor="pc-name">{t('pcon.f_name')}</label>
          <input id="pc-name" className="form-control" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
          {err('name')}
          <label className="form-label mt-3" htmlFor="pc-code">{t('pcon.f_code')}</label>
          <input id="pc-code" className="form-control" value={form.code} onChange={(e) => setForm({ ...form, code: e.target.value.toLowerCase().replace(/[^a-z0-9_]/g, '_') })} />
          <p className="small text-secondary mt-1">{t('pcon.code_hint')}</p>
          {err('code')}
          <label className="form-label mt-3" htmlFor="pc-kind">{t('pcon.f_kind')}</label>
          <select id="pc-kind" className="form-select" value={form.kind} onChange={(e) => setForm({ ...form, kind: e.target.value })}>
            {CONCEPT_KINDS.map((k) => <option key={k} value={k}>{t(`pcon.kind_${k}`)}</option>)}
          </select>
        </Modal>
      )}
    </>
  )
}

/** CPT-3 · Conceptos × bases de cálculo a hoy. */
function AffectationMatrix() {
  const { t } = useT()
  const [data, setData] = useState<Matrix | null>(null)
  const [failed, setFailed] = useState(false)
  useEffect(() => {
    void api.get<{ data: Matrix }>('/payroll-affectation-matrix').then((r) => setData(r.data)).catch(() => setFailed(true))
  }, [])
  if (failed) return <Notice tone="error">{t('pcon.load_error')}</Notice>
  if (!data) return <p className="text-secondary">{t('pcon.loading')}</p>
  return (
    <div className="table-responsive mt-3">
      <table className="table table-sm">
        <caption className="small text-secondary">{t('pcon.matrix_caption')}</caption>
        <thead>
          <tr>
            <th scope="col">{t('pcon.col_name')}</th>
            {data.bases.map((b) => <th key={b.code} scope="col" className="text-center">{b.name}</th>)}
          </tr>
        </thead>
        <tbody>
          {data.concepts.map((c) => {
            const on = new Set(affectedBases(c.affectations))
            return (
              <tr key={c.id}>
                <th scope="row"><Link href={`/nomina/conceptos/${c.id}`}>{c.name}</Link> <span className="small text-secondary">{c.code}</span></th>
                {data.bases.map((b) => (
                  <td key={b.code} className="text-center">{on.has(b.code) ? <span aria-label={t('pcon.affects_yes')}><Icon name="check" size={16} /></span> : <span className="text-secondary" aria-label={t('pcon.affects_no')}>·</span>}</td>
                ))}
              </tr>
            )
          })}
        </tbody>
      </table>
    </div>
  )
}

/** CPT-4 · Plan de cuentas de la planilla. */
function AccountsPanel() {
  const { t } = useT()
  const { can } = useSession()
  const [rows, setRows] = useState<Account[] | null>(null)
  const [form, setForm] = useState({ code: '', name: '', kind: 'expense' })
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [general, setGeneral] = useState<string | null>(null)
  const load = useCallback(() => {
    void api.get<{ data: Account[] }>('/accounting-accounts').then((r) => setRows(r.data)).catch(() => setRows([]))
  }, [])
  useEffect(load, [load])

  async function add() {
    setErrors({}); setGeneral(null)
    try {
      await api.post('/accounting-accounts', form)
      setForm({ code: '', name: '', kind: 'expense' })
      load()
    } catch (e) {
      const f = formErrors(e, t)
      setErrors(f.fields); setGeneral(f.general)
    }
  }

  return (
    <div className="mt-3">
      {can('payroll.accounting_accounts.update') && (
        <form className="row g-2 align-items-end mb-3" onSubmit={(e) => { e.preventDefault(); void add() }}>
          {general && <div className="col-12"><Notice tone="error">{general}</Notice></div>}
          <div className="col-sm-3">
            <label className="form-label" htmlFor="acc-code">{t('pcon.acc_code')}</label>
            <input id="acc-code" className="form-control" value={form.code} onChange={(e) => setForm({ ...form, code: e.target.value })} />
            {errors.code && <div className="invalid-feedback d-block">{errors.code}</div>}
          </div>
          <div className="col-sm-5">
            <label className="form-label" htmlFor="acc-name">{t('pcon.acc_name')}</label>
            <input id="acc-name" className="form-control" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
            {errors.name && <div className="invalid-feedback d-block">{errors.name}</div>}
          </div>
          <div className="col-sm-2">
            <label className="form-label" htmlFor="acc-kind">{t('pcon.acc_kind')}</label>
            <select id="acc-kind" className="form-select" value={form.kind} onChange={(e) => setForm({ ...form, kind: e.target.value })}>
              {['expense', 'liability', 'asset', 'other'].map((k) => <option key={k} value={k}>{t(`pcon.acc_kind_${k}`)}</option>)}
            </select>
          </div>
          <div className="col-sm-2"><button type="submit" className="btn btn-primary w-100">{t('pcon.acc_add')}</button></div>
        </form>
      )}
      {rows === null ? <p className="text-secondary">{t('pcon.loading')}</p> : rows.length === 0 ? (
        <EmptyState title={t('pcon.acc_empty_title')} text={t('pcon.acc_empty_text')} />
      ) : (
        <table className="table table-sm">
          <thead><tr><th scope="col">{t('pcon.acc_code')}</th><th scope="col">{t('pcon.acc_name')}</th><th scope="col">{t('pcon.acc_kind')}</th></tr></thead>
          <tbody>{rows.map((a) => <tr key={a.id}><td>{a.code}</td><td>{a.name}</td><td>{t(`pcon.acc_kind_${a.kind}`)}</td></tr>)}</tbody>
        </table>
      )}
    </div>
  )
}
