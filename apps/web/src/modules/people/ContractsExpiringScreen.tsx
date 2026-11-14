'use client'

import Link from 'next/link'
import { useMemo } from 'react'
import { formatDate, formatNumber } from '@/lib/format'
import { useT } from '@/lib/useT'
import { useSession } from '@/modules/session/SessionProvider'
import { DataTable, type TableColumn, type TableFilter } from '@/ui/components/DataTable'
import { PageHeader } from '@/ui/components/PageHeader'
import { EmptyState } from '@/ui/components/States'
import { StatusBadge, type StatusTone } from '@/ui/components/Status'
import { peopleTabs } from './peopleTabs'
import { daysLeftTone, type ExpiringContract } from './contractsExpiring'

const STATUS_TONE: Record<string, StatusTone> = { signed: 'in', active: 'ok', expired: 'er' }

/**
 * CON-4 · Contratos por vencer: los plazos fijos que terminan dentro de la anticipación de aviso de cada
 * empresa (o de la ventana elegida) y los vencidos sin regularizar, con lo acumulado frente al tope y la
 * acción sugerida. Los días, el tope y la sugerencia los calcula el servidor; aquí solo se muestran.
 */
export function ContractsExpiringScreen() {
  const { t, locale } = useT()
  const { can } = useSession()
  const date = (v: string | null) => (v ? formatDate(locale, v) : '—')
  const num = (v: number) => formatNumber(locale, String(v))

  const columns = useMemo<Array<TableColumn<ExpiringContract>>>(() => [
    {
      key: 'person', label: t('cexp.col_person'), hideable: false,
      render: (r) => (
        <Link href={`/personas/colaboradores/${r.employment_id}?tab=contracts`}>
          {r.person_name ?? r.employee_code ?? '—'}
        </Link>
      ),
    },
    { key: 'entity', label: t('cexp.col_entity'), hiddenByDefault: true, render: (r) => r.legal_entity_name ?? '—' },
    { key: 'type', label: t('cexp.col_type'), render: (r) => <>{r.contract_type_name ?? '—'}{r.number && <span className="small text-secondary">{` · ${r.number}`}</span>}</> },
    { key: 'ends_on', label: t('cexp.col_ends'), render: (r) => date(r.ends_on) },
    {
      key: 'days_left', label: t('cexp.col_days'),
      render: (r) => (
        <StatusBadge tone={daysLeftTone(r.days_left, r.alert_days)}>
          {r.days_left < 0 ? t('cexp.days_overdue', { days: num(-r.days_left) }) : t('cexp.days_left', { days: num(r.days_left) })}
        </StatusBadge>
      ),
    },
    {
      key: 'accumulated', label: t('cexp.col_accumulated'),
      render: (r) => (
        <span title={r.accumulation.explanation}>
          {t('cexp.accumulated', { days: num(r.accumulation.total_days), max: r.accumulation.total_max_days === null ? '—' : num(r.accumulation.total_max_days) })}
        </span>
      ),
    },
    { key: 'status', label: t('cexp.col_status'), render: (r) => <StatusBadge tone={STATUS_TONE[r.status] ?? 'gr'}>{t(`ctr.status_${r.status}`)}</StatusBadge> },
    { key: 'action', label: t('cexp.col_action'), render: (r) => <span className="small">{t(`cexp.action_${r.suggested_action}`)}</span> },
  ], [t, locale]) // eslint-disable-line react-hooks/exhaustive-deps

  const filters: TableFilter[] = [
    { key: 'within', label: t('cexp.f_within'), options: ['15', '30', '60', '90'].map((d) => ({ value: d, label: t('cexp.within_days', { days: d }) })) },
    { key: 'include_expired', label: t('cexp.f_expired'), options: [{ value: 'true', label: t('cexp.expired_yes') }, { value: 'false', label: t('cexp.expired_no') }] },
  ]

  return (
    <>
      <PageHeader title={t('cexp.title')} sub={t('cexp.sub')} tabs={peopleTabs(t, 'contracts', can)} />
      <main className="main" id="contenido" tabIndex={-1}>
        <DataTable<ExpiringContract>
          resource="contracts-expiring"
          endpoint="/contracts/expiring"
          defaultSort=""
          searchLabel={t('cexp.search')}
          rowKey={(r) => r.id}
          columns={columns}
          filters={filters}
          empty={<EmptyState title={t('cexp.empty_title')} text={t('cexp.empty_text')} />}
          rowActions={(r) => (
            <Link className="btn btn-ter btn-sm" href={`/personas/colaboradores/${r.employment_id}?tab=contracts`}>{t('cexp.open')}</Link>
          )}
        />
      </main>
    </>
  )
}
