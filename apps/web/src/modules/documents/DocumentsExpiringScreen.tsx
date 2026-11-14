'use client'

import Link from 'next/link'
import { useMemo } from 'react'
import { formatDate, formatNumber } from '@/lib/format'
import { useT } from '@/lib/useT'
import { DataTable, type TableColumn, type TableFilter } from '@/ui/components/DataTable'
import { PageHeader } from '@/ui/components/PageHeader'
import { EmptyState } from '@/ui/components/States'
import { StatusBadge, Tag } from '@/ui/components/Status'
import { expiryTone, isConfidential, STATUS_TONE, type LegajoDocument } from './documents'

/**
 * DOC-2 · RN-DOC-09 · Documentos por vencer (dentro de la anticipación de cada tipo, o de la ventana elegida) y
 * vencidos, con su titular y su responsable, dentro del alcance del usuario. Los días los calcula el servidor.
 */
export function DocumentsExpiringScreen() {
  const { t, locale } = useT()
  const date = (v: string | null | undefined) => (v ? formatDate(locale, v) : '—')
  const num = (v: number) => formatNumber(locale, String(v))
  const href = (r: LegajoDocument) => (r.employment_id ? `/personas/colaboradores/${r.employment_id}?tab=documents` : null)

  const columns = useMemo<Array<TableColumn<LegajoDocument>>>(() => [
    {
      key: 'person', label: t('dexp.col_person'), hideable: false,
      render: (r) => {
        const link = href(r)
        const name = r.person_name ?? r.employee_code ?? t('dexp.company')
        return link ? <Link href={link}>{name}</Link> : name
      },
    },
    {
      key: 'document', label: t('dexp.col_document'),
      render: (r) => (
        <>
          {r.title}{isConfidential(r.classification) && <> <Tag tone="pe">{t(`doc.class_${r.classification}`)}</Tag></>}
          <span className="small text-secondary d-block">{[r.folder_name, r.category_name].filter(Boolean).join(' · ')}</span>
        </>
      ),
    },
    { key: 'expires_on', label: t('dexp.col_expires'), render: (r) => date(r.expires_on) },
    {
      key: 'days', label: t('dexp.col_days'),
      render: (r) => {
        const days = r.days_to_expiry ?? 0
        return (
          <StatusBadge tone={expiryTone(r) ?? 'in'}>
            {days < 0 ? t('dexp.days_overdue', { days: num(-days) }) : t('dexp.days_left', { days: num(days) })}
          </StatusBadge>
        )
      },
    },
    { key: 'responsible', label: t('dexp.col_responsible'), render: (r) => r.responsible_name ?? '—' },
    { key: 'status', label: t('dexp.col_status'), render: (r) => <StatusBadge tone={STATUS_TONE[r.status]}>{t(`doc.status_${r.status}`)}</StatusBadge> },
  ], [t, locale]) // eslint-disable-line react-hooks/exhaustive-deps

  const filters: TableFilter[] = [
    { key: 'within', label: t('dexp.f_within'), options: ['15', '30', '60', '90', '180'].map((d) => ({ value: d, label: t('dexp.within_days', { days: d }) })) },
    { key: 'include_expired', label: t('dexp.f_expired'), options: [{ value: 'true', label: t('dexp.expired_yes') }, { value: 'false', label: t('dexp.expired_no') }] },
  ]

  return (
    <>
      <PageHeader title={t('dexp.title')} sub={t('dexp.sub')} />
      <main className="main" id="contenido" tabIndex={-1}>
        <DataTable<LegajoDocument>
          resource="documents-expiring"
          endpoint="/documents/expiring"
          defaultSort=""
          searchLabel={t('dexp.search')}
          rowKey={(r) => r.id}
          columns={columns}
          filters={filters}
          empty={<EmptyState title={t('dexp.empty_title')} text={t('dexp.empty_text')} />}
          rowActions={(r) => {
            const link = href(r)
            return link ? <Link className="btn btn-ter btn-sm" href={link}>{t('dexp.open')}</Link> : null
          }}
        />
      </main>
    </>
  )
}
