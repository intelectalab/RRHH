'use client'

import Link from 'next/link'
import { useEffect, useState } from 'react'
import { api } from '@/lib/api'
import { formatDate } from '@/lib/format'
import { useT } from '@/lib/useT'
import { ExportButton } from '@/modules/exports/ExportButton'
import { useSession } from '@/modules/session/SessionProvider'
import { DataTable, type TableColumn, type TableFilter, type TablePreset } from '@/ui/components/DataTable'
import { Icon } from '@/ui/components/Icon'
import { PageHeader } from '@/ui/components/PageHeader'
import { StatusBadge } from '@/ui/components/Status'
import { EmptyState } from '@/ui/components/States'
import { useToast } from '@/ui/components/Toast'
import { EMPLOYMENT_STATUSES, STATUS_TONE, type Employment, type HireOptions } from './employment'
import { peopleTabs } from './peopleTabs'

/**
 * COL-9 · Personas → Colaboradores: listado con búsqueda (nombre, código o documento), filtros, orden,
 * columnas, vistas «Activos», «Por ingresar» y «Cesados», exportación y entrada a la ficha. El alcance y
 * los permisos por campo los aplica la API.
 */
export function EmployeesScreen() {
  const { t, locale } = useT()
  const { can } = useSession()
  const { toast, node: toastNode } = useToast()
  const [total, setTotal] = useState<number | null>(null)
  const [options, setOptions] = useState<HireOptions | null>(null)

  useEffect(() => {
    if (!can('people.employments.create')) return
    api.get<{ data: HireOptions }>('/employment-options').then((r) => setOptions(r.data)).catch(() => setOptions(null))
  }, [can])

  const columns: Array<TableColumn<Employment>> = [
    {
      key: 'person', label: t('emp.col_person'), sort: 'person_name', hideable: false, width: 300,
      render: (e) => (
        <div className="min-w-0">
          <Link href={`/personas/colaboradores/${e.id}`}><b>{e.person_name ?? '—'}</b></Link>
          <span className="sub2">{[e.employee_code, e.document_number_masked ? t('emp.doc_masked', { n: e.document_number_masked }) : null].filter(Boolean).join(' · ')}</span>
        </div>
      ),
    },
    { key: 'job', label: t('emp.col_job'), render: (e) => e.position_title ?? e.job_profile_name ?? '—' },
    { key: 'unit', label: t('emp.col_unit'), render: (e) => e.org_unit_name ?? '—' },
    { key: 'site', label: t('emp.col_site'), render: (e) => e.site_name ?? '—' },
    { key: 'manager', label: t('emp.col_manager'), hiddenByDefault: true, render: (e) => e.manager_name ?? '—' },
    { key: 'company', label: t('emp.col_company'), hiddenByDefault: true, render: (e) => e.legal_entity_name ?? '—' },
    { key: 'hire_date', label: t('emp.col_hire_date'), sort: 'hire_date', render: (e) => formatDate(locale, e.hire_date) },
    { key: 'status', label: t('emp.col_status'), sort: 'status', render: (e) => <StatusBadge tone={STATUS_TONE[e.status]}>{t(`emp.status_${e.status}`)}</StatusBadge> },
  ]

  const filters: TableFilter[] = [
    { key: 'status', label: t('emp.filter_status'), options: EMPLOYMENT_STATUSES.map((s) => ({ value: s, label: t(`emp.status_${s}`) })) },
    ...(options ? [
      { key: 'org_unit_id', label: t('emp.filter_unit'), options: options.org_units.map((u) => ({ value: u.id, label: u.name })) },
      { key: 'site_id', label: t('emp.filter_site'), options: options.sites.map((s) => ({ value: s.id, label: s.name })) },
      { key: 'job_profile_id', label: t('emp.filter_job'), options: options.job_profiles.map((j) => ({ value: j.id, label: j.name })) },
    ] : []),
  ]

  const presets: TablePreset[] = [
    { key: 'active', label: t('emp.view_active'), filters: { status: 'active' } },
    { key: 'pending', label: t('emp.view_pending'), filters: { status: 'pending' } },
    { key: 'terminated', label: t('emp.view_terminated'), filters: { status: 'terminated' } },
  ]

  const newHire = can('people.employments.create') && (
    <Link className="btn btn-primary" href="/personas/colaboradores/nuevo"><Icon name="plus" /> {t('emp.new')}</Link>
  )

  return (
    <>
      <PageHeader
        title={t('people.title')}
        sub={total === null ? undefined : t('emp.sub', { n: total })}
        tabs={peopleTabs(t, 'employees', can)}
        actions={newHire || undefined}
      />
      <main className="main" id="contenido" tabIndex={-1}>
        <DataTable<Employment>
          resource="employments"
          endpoint="/employments"
          urlSearch
          defaultSort="person_name"
          searchLabel={t('emp.search')}
          rowKey={(e) => e.id}
          columns={columns}
          filters={filters}
          presets={presets}
          onTotal={setTotal}
          toolbar={(state) => (
            <ExportButton
              resource="employments"
              filters={{ q: state.q.trim(), ...Object.fromEntries(Object.entries(state.filters).filter(([k]) => ['status', 'org_unit_id', 'site_id', 'job_profile_id'].includes(k))) }}
              onError={(m) => toast(m, 'error')}
            />
          )}
          empty={<EmptyState title={t('emp.empty_title')} text={t('emp.empty_text')} action={newHire} />}
          rowActions={(e) => <Link className="btn btn-ter btn-sm" href={`/personas/colaboradores/${e.id}`}>{t('emp.open')}</Link>}
        />
      </main>
      {toastNode}
    </>
  )
}
