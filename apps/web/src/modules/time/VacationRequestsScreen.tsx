'use client'

import { useSearchParams } from 'next/navigation'
import { useEffect, useState } from 'react'
import { formatNumber } from '@/lib/format'
import { useT } from '@/lib/useT'
import { useSession } from '@/modules/session/SessionProvider'
import { DataTable, type TableColumn, type TableFilter } from '@/ui/components/DataTable'
import { Icon } from '@/ui/components/Icon'
import { PageHeader } from '@/ui/components/PageHeader'
import { EmptyState } from '@/ui/components/States'
import { Tabs } from '@/ui/components/Tabs'
import { useToast } from '@/ui/components/Toast'
import { TeamCalendarView } from './TeamCalendarView'
import { VacationDates, VacationRequestDetailPanel, VacationRequestFormPanel } from './VacationRequestPanels'
import { VACATION_REQUEST_STATUSES, VACATION_TONE, type VacationRequest } from './vacationRequests'
import { useQuickCreateRequest } from '@/modules/session/quickCreateRegistry'

/**
 * Tiempo → Vacaciones (VAC-3 · ME-15 y VAC-4). Solicitudes: el colaborador ve y pide las suyas, el jefe
 * las de su equipo y RRHH las de la organización (el alcance lo aplica la API); aprobar o rechazar se hace
 * en el centro de aprobaciones. Calendario del equipo: vacaciones y ausencias por persona y día.
 * ?id= abre el detalle (enlace del calendario y de las notificaciones).
 */
export function VacationRequestsScreen() {
  const { t, locale } = useT()
  const { can } = useSession()
  const params = useSearchParams()
  const { toast, node: toastNode } = useToast()
  const [tab, setTab] = useState('requests')
  const [reloadKey, setReloadKey] = useState(0)
  const [creating, setCreating] = useState(false)
  useQuickCreateRequest('vacation_request', () => setCreating(true), can('time.vacation_requests.create'))
  const [detail, setDetail] = useState<string | null>(null)
  const reload = () => setReloadKey((k) => k + 1)

  useEffect(() => {
    const id = params.get('id')
    if (id) setDetail(id)
  }, [params])

  const columns: Array<TableColumn<VacationRequest>> = [
    { key: 'person', label: t('vreq.employee'), hideable: false, width: 220, render: (r) => <><b>{r.person_name ?? '—'}</b><span className="sub2">{r.employee_code ?? ''}</span></> },
    { key: 'dates', label: t('vreq.col_dates'), sort: 'starts_on', render: (r) => <VacationDates request={r} /> },
    { key: 'days', label: t('vreq.col_days'), render: (r) => <>{t('vreq.days_n', { n: formatNumber(locale, r.days) })}{r.advance && <span className="sub2"> · {t('vreq.advance')}</span>}</> },
    { key: 'status', label: t('vreq.col_status'), sort: 'status', render: (r) => <span className={`st ${VACATION_TONE[r.status]}`}>{t(`vreq.status_${r.status}`)}</span> },
  ]
  const filters: TableFilter[] = [
    { key: 'status', label: t('vreq.filter_status'), options: VACATION_REQUEST_STATUSES.map((s) => ({ value: s, label: t(`vreq.status_${s}`) })) },
  ]

  return (
    <>
      <PageHeader title={t('vreq.title')} sub={t('vreq.sub')} />
      <main className="main" id="contenido" tabIndex={-1}>
        <Tabs
          label={t('vreq.title')}
          value={tab}
          onChange={setTab}
          items={[
            {
              id: 'requests',
              label: t('vreq.tab_requests'),
              content: (
                <DataTable<VacationRequest>
                  resource="vacation-requests"
                  endpoint="/vacation-requests"
                  defaultSort="-starts_on"
                  searchLabel={t('vreq.search')}
                  reloadKey={reloadKey}
                  rowKey={(r) => r.id}
                  columns={columns}
                  filters={filters}
                  toolbar={() => can('time.vacation_requests.create') && <button type="button" className="btn btn-primary btn-sm" onClick={() => setCreating(true)}><Icon name="plus" /> {t('vreq.new')}</button>}
                  empty={<EmptyState title={t('vreq.empty_title')} text={t('vreq.empty_text')} />}
                  rowActions={(r) => <button type="button" className="btn btn-ter btn-sm" onClick={() => setDetail(r.id)}>{t('vreq.detail')}</button>}
                />
              ),
            },
            { id: 'team', label: t('vreq.tab_team'), content: <TeamCalendarView key={reloadKey} /> },
          ]}
        />
      </main>
      {creating && <VacationRequestFormPanel onClose={() => setCreating(false)} onSaved={(id) => { setCreating(false); toast(t('vreq.saved')); reload(); setDetail(id) }} />}
      {detail && <VacationRequestDetailPanel id={detail} onClose={() => setDetail(null)} onChanged={reload} toast={toast} />}
      {toastNode}
    </>
  )
}
