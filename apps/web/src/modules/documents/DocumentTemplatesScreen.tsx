'use client'

import Link from 'next/link'
import { useRouter } from 'next/navigation'
import { useEffect, useMemo, useState } from 'react'
import { api } from '@/lib/api'
import { formErrors } from '@/lib/errors'
import { formatDate } from '@/lib/format'
import { useT } from '@/lib/useT'
import { useSession } from '@/modules/session/SessionProvider'
import { DataTable, type TableColumn, type TableFilter } from '@/ui/components/DataTable'
import { Icon } from '@/ui/components/Icon'
import { Modal } from '@/ui/components/Modal'
import { Notice } from '@/ui/components/Notice'
import { PageHeader } from '@/ui/components/PageHeader'
import { EmptyState } from '@/ui/components/States'
import { StatusBadge, Tag } from '@/ui/components/Status'
import type { DocumentFolder } from './documents'
import { TEMPLATE_KINDS, TEMPLATE_TONE, type DocumentTemplate } from './templates'

type NewForm = { code: string; name: string; kind: string; category_id: string }

/**
 * DOC-4 · Plantillas de documentos por tipo documental y empresa, con su versión publicada; las del sistema
 * (certificado de trabajo, constancias) vienen publicadas. Crear abre el editor con el catálogo de variables.
 */
export function DocumentTemplatesScreen() {
  const { t, locale } = useT()
  const { can } = useSession()
  const router = useRouter()
  const [form, setForm] = useState<NewForm | null>(null)
  const [folders, setFolders] = useState<DocumentFolder[]>([])
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [general, setGeneral] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  useEffect(() => {
    if (!form || folders.length > 0) return
    void api.get<{ data: DocumentFolder[] }>('/document-categories').then((r) => setFolders(r.data)).catch(() => setFolders([]))
  }, [form, folders.length])

  const columns = useMemo<Array<TableColumn<DocumentTemplate>>>(() => [
    {
      key: 'name', label: t('dtpl.col_name'), hideable: false,
      render: (r) => <><Link href={`/documentos/plantillas/${r.id}`}>{r.name}</Link>{r.is_system && <> <Tag tone="in">{t('dtpl.system')}</Tag></>}<span className="small text-secondary d-block">{r.code}</span></>,
    },
    { key: 'kind', label: t('dtpl.col_kind'), render: (r) => t(`dtpl.kind_${r.kind}`) },
    { key: 'category', label: t('dtpl.col_category'), render: (r) => r.category_name ?? '—' },
    { key: 'company', label: t('dtpl.col_company'), render: (r) => r.legal_entity_name ?? t('dtpl.all_companies') },
    { key: 'version', label: t('dtpl.col_version'), render: (r) => (r.current_version ? t('dtpl.version_n', { n: r.current_version }) : '—') },
    { key: 'published_at', label: t('dtpl.col_published'), render: (r) => (r.published_at ? formatDate(locale, r.published_at) : '—') },
    {
      key: 'status', label: t('dtpl.col_status'),
      render: (r) => <><StatusBadge tone={TEMPLATE_TONE[r.status]}>{t(`dtpl.status_${r.status}`)}</StatusBadge>{r.has_draft && r.status !== 'draft' && <> <Tag tone="wa">{t('dtpl.has_draft')}</Tag></>}</>,
    },
  ], [t, locale])

  const filters: TableFilter[] = [
    { key: 'status', label: t('dtpl.f_status'), options: (['draft', 'active', 'inactive'] as const).map((s) => ({ value: s, label: t(`dtpl.status_${s}`) })) },
    { key: 'kind', label: t('dtpl.f_kind'), options: TEMPLATE_KINDS.map((k) => ({ value: k, label: t(`dtpl.kind_${k}`) })) },
  ]
  const contractKind = form?.kind === 'contract' || form?.kind === 'amendment'
  const types = folders.filter((f) => (f.code === 'contracts') === contractKind).flatMap((f) => [f, ...f.types])

  async function create() {
    if (!form) return
    setBusy(true); setErrors({}); setGeneral(null)
    try {
      const r = await api.post<{ data: DocumentTemplate }>('/document-templates', { ...form, category_id: form.category_id || null, body: '' })
      router.push(`/documentos/plantillas/${r.data.id}`)
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
      <PageHeader title={t('dtpl.title')} sub={t('dtpl.sub')} actions={can('documents.templates.create') ? (
        <button type="button" className="btn btn-primary" onClick={() => { setErrors({}); setGeneral(null); setForm({ code: '', name: '', kind: 'certificate', category_id: '' }) }}>
          <Icon name="plus" /> {t('dtpl.new')}
        </button>
      ) : undefined} />
      <main className="main" id="contenido" tabIndex={-1}>
        <DataTable<DocumentTemplate>
          resource="document-templates"
          endpoint="/document-templates"
          defaultSort=""
          searchLabel={t('dtpl.search')}
          rowKey={(r) => r.id}
          columns={columns}
          filters={filters}
          empty={<EmptyState title={t('dtpl.empty_title')} text={t('dtpl.empty_text')} />}
          rowActions={(r) => <Link className="btn btn-ter btn-sm" href={`/documentos/plantillas/${r.id}`}>{t('dtpl.open')}</Link>}
        />
      </main>
      {form && (
        <Modal title={t('dtpl.new')} onClose={() => setForm(null)} footer={<>
          <button type="button" className="btn btn-sec" onClick={() => setForm(null)}>{t('org.cancel')}</button>
          <button type="button" className="btn btn-primary" disabled={busy} aria-busy={busy} onClick={() => void create()}>{t('dtpl.create')}</button>
        </>}>
          {general && <Notice tone="error">{general}</Notice>}
          <label className="form-label" htmlFor="tp-name">{t('dtpl.f_name')}</label>
          <input id="tp-name" className="form-control" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
          {err('name')}
          <label className="form-label mt-3" htmlFor="tp-code">{t('dtpl.f_code')}</label>
          <input id="tp-code" className="form-control" value={form.code} onChange={(e) => setForm({ ...form, code: e.target.value.toLowerCase() })} />
          <p className="small text-secondary mt-1">{t('dtpl.code_hint')}</p>
          {err('code')}
          <label className="form-label mt-3" htmlFor="tp-kind">{t('dtpl.f_kind')}</label>
          <select id="tp-kind" className="form-select" value={form.kind} onChange={(e) => setForm({ ...form, kind: e.target.value, category_id: '' })}>
            {TEMPLATE_KINDS.map((k) => <option key={k} value={k}>{t(`dtpl.kind_${k}`)}</option>)}
          </select>
          <label className="form-label mt-3" htmlFor="tp-cat">{t('dtpl.f_category')}</label>
          <select id="tp-cat" className="form-select" value={form.category_id} onChange={(e) => setForm({ ...form, category_id: e.target.value })}>
            <option value="">{t('dtpl.choose')}</option>
            {types.map((c) => <option key={c.id} value={c.id}>{c.parent_id ? `— ${c.name}` : c.name}</option>)}
          </select>
          {err('category_id')}
        </Modal>
      )}
    </>
  )
}
