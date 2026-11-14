'use client'

import { useSearchParams } from 'next/navigation'
import { useEffect, useState } from 'react'
import { useT } from '@/lib/useT'
import { useSession } from '@/modules/session/SessionProvider'
import { DataTable, type TableColumn, type TableFilter } from '@/ui/components/DataTable'
import { Icon } from '@/ui/components/Icon'
import { PageHeader } from '@/ui/components/PageHeader'
import { EmptyState } from '@/ui/components/States'
import { useToast } from '@/ui/components/Toast'
import { AbsenceDates, AbsenceDetailPanel, AbsenceFormPanel, AbsenceQuantity } from './AbsencePanels'
import { ABSENCE_STATUSES, ABSENCE_TONE, type Absence } from './absences'

/**
 * Tiempo → Ausencias y permisos (AUS-2 · ME-16). El colaborador ve y pide las suyas, el jefe las de su
 * equipo y RRHH las de la organización (el alcance lo aplica la API). Aprobar o rechazar se hace en el
 * centro de aprobaciones; ?id= abre el detalle (enlace del calendario y de las notificaciones).
 */
export function AbsencesScreen() {
  const { t } = useT()
  const { can } = useSession()
  const params = useSearchParams()
  const { toast, node: toastNode } = useToast()
  const [reloadKey, setReloadKey] = useState(0)
  const [creating, setCreating] = useState(false)
  const [detail, setDetail] = useState<string | null>(null)
  const reload = () => setReloadKey((k) => k + 1)

  useEffect(() => {
    const id = params.get('id')
    if (id) setDetail(id)
  }, [params])

  const columns: Array<TableColumn<Absence>> = [
    { key: 'person', label: t('abs.employee'), hideable: false, width: 220, render: (r) => <><b>{r.person_name ?? '—'}</b><span className="sub2">{r.employee_code ?? ''}</span></> },
    { key: 'type', label: t('abs.type'), render: (r) => r.absence_type_name ?? '—' },
    { key: 'dates', label: t('abs.col_dates'), sort: 'starts_on', render: (r) => <AbsenceDates absence={r} /> },
    { key: 'quantity', label: t('abs.col_quantity'), render: (r) => <AbsenceQuantity absence={r} /> },
    { key: 'status', label: t('abs.col_status'), sort: 'status', render: (r) => <span className={`st ${ABSENCE_TONE[r.status]}`}>{t(`abs.status_${r.status}`)}</span> },
  ]
  const filters: TableFilter[] = [
    { key: 'status', label: t('abs.filter_status'), options: ABSENCE_STATUSES.map((s) => ({ value: s, label: t(`abs.status_${s}`) })) },
  ]

  return (
    <>
      <PageHeader title={t('abs.title')} sub={t('abs.sub')} />
      <main className="main" id="contenido" tabIndex={-1}>
        <DataTable<Absence>
          resource="absences"
          endpoint="/absences"
          defaultSort="-starts_on"
          searchLabel={t('abs.search')}
          reloadKey={reloadKey}
          rowKey={(r) => r.id}
          columns={columns}
          filters={filters}
          toolbar={() => can('time.absences.create') && <button type="button" className="btn btn-primary btn-sm" onClick={() => setCreating(true)}><Icon name="plus" /> {t('abs.new')}</button>}
          empty={<EmptyState title={t('abs.empty_title')} text={t('abs.empty_text')} />}
          rowActions={(r) => <button type="button" className="btn btn-ter btn-sm" onClick={() => setDetail(r.id)}>{t('abs.detail')}</button>}
        />
      </main>
      {creating && <AbsenceFormPanel onClose={() => setCreating(false)} onSaved={(id) => { setCreating(false); toast(t('abs.saved')); reload(); setDetail(id) }} />}
      {detail && <AbsenceDetailPanel id={detail} onClose={() => setDetail(null)} onChanged={reload} toast={toast} />}
      {toastNode}
    </>
  )
}
