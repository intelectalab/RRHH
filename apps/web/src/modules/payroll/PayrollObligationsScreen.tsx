'use client'

import { useCallback, useEffect, useState } from 'react'
import { api } from '@/lib/api'
import { formatDate } from '@/lib/format'
import { useT } from '@/lib/useT'
import { searchActiveUsers } from '@/modules/identity/peopleSearch'
import { useSession } from '@/modules/session/SessionProvider'
import { Notice } from '@/ui/components/Notice'
import { PageHeader } from '@/ui/components/PageHeader'
import { PersonPicker, type Person } from '@/ui/components/PersonPicker'
import { EmptyState } from '@/ui/components/States'
import { useToast } from '@/ui/components/Toast'

type Entity = { id: string; legal_name: string }
type Obligation = {
  code: string; name: string; period_key: string; due_on: string | null
  status: 'met' | 'pending' | 'due_soon' | 'overdue' | 'no_data' | 'attention' | 'not_applicable'
  evidence: { type: string; id?: string; status?: string; count?: number } | null
  explanation: string; missing_parameter: string | null
  responsible: { user_id: string; name: string } | null
}
const TONE: Record<Obligation['status'], string> = { met: 'ok', pending: 'in', due_soon: 'wa', overdue: 'er', no_data: 'gr', attention: 'wa', not_applicable: 'gr' }

/**
 * Nómina → Obligaciones (CUM-9). Tablero mínimo de lo que vence en planilla para la empresa elegida: PLAME,
 * AFPnet, T-Registro, CTS, gratificación, contratos por vencer y umbral del comité de SST. El vencimiento, el
 * estado y la explicación los calcula el servidor con los parámetros legales vigentes; si un plazo no está
 * cargado se muestra «sin datos» con el parámetro que falta. Aquí se asigna el responsable, que recibe los avisos.
 */
export function PayrollObligationsScreen() {
  const { t, locale } = useT()
  const { can } = useSession()
  const { toast, node: toastNode } = useToast()
  const [entities, setEntities] = useState<Entity[] | null>(null)
  const [entity, setEntity] = useState('')
  const [rows, setRows] = useState<Obligation[] | null>(null)
  const [editing, setEditing] = useState<string | null>(null)
  const [person, setPerson] = useState<Person | null>(null)
  const [error, setError] = useState<string | null>(null)
  const canAssign = can('payroll.payroll_groups.update')

  useEffect(() => {
    api.get<{ data: Entity[] }>('/legal-entities?per_page=100')
      .then((r) => { setEntities(r.data); if (r.data[0]) setEntity(r.data[0].id) })
      .catch((e: unknown) => { setEntities([]); setError(e instanceof Error ? e.message : t('pobl.error')) })
  }, [t])

  const load = useCallback(async (id: string) => {
    if (!id) return
    try {
      const r = await api.get<{ data: Obligation[] }>(`/payroll-obligations?legal_entity_id=${id}`)
      setRows(r.data)
      setError(null)
    } catch (e) {
      setRows([])
      setError(e instanceof Error ? e.message : t('pobl.error'))
    }
  }, [t])

  useEffect(() => { void load(entity) }, [entity, load])

  const assign = async (code: string) => {
    if (!person) return
    try {
      await api.request(`/payroll-obligations/${code}/responsible`, 'PUT', { legal_entity_id: entity, responsible_user_id: person.id })
      toast(t('pobl.assigned'))
      setEditing(null)
      setPerson(null)
      await load(entity)
    } catch (e) {
      toast(e instanceof Error ? e.message : t('pobl.error'), 'error')
    }
  }

  return (
    <>
      <PageHeader title={t('pobl.title')} sub={t('pobl.sub')} />
      <main className="main" id="contenido" tabIndex={-1}>
        <div className="card" style={{ padding: 16 }}>
          <label className="form-label" htmlFor="pobl-entity">{t('pobl.entity')}</label>
          <select id="pobl-entity" className="form-control" style={{ maxWidth: 420 }} value={entity} onChange={(e) => setEntity(e.target.value)}>
            {(entities ?? []).map((e) => <option key={e.id} value={e.id}>{e.legal_name}</option>)}
          </select>
        </div>
        {error && <Notice tone="error">{error}</Notice>}
        {entities !== null && entities.length === 0 && <EmptyState title={t('pobl.no_entities_title')} text={t('pobl.no_entities_text')} />}
        {rows !== null && rows.length > 0 && (
          <section className="card">
            <table className="table">
              <thead><tr><th>{t('pobl.col_obligation')}</th><th>{t('pobl.col_due')}</th><th>{t('pobl.col_status')}</th><th>{t('pobl.col_detail')}</th><th>{t('pobl.col_responsible')}</th></tr></thead>
              <tbody>
                {rows.map((o) => (
                  <tr key={o.code}>
                    <td>{o.name}</td>
                    <td>{o.due_on ? formatDate(locale, o.due_on) : '—'}</td>
                    <td><span className={`tag ${TONE[o.status]}`}>{t(`pobl.status_${o.status}`)}</span></td>
                    <td>{o.explanation}{o.missing_parameter && <div className="sub2">{t('pobl.missing', { code: o.missing_parameter })}</div>}</td>
                    <td>
                      {editing === o.code
                        ? (
                          <div style={{ display: 'flex', gap: 6, alignItems: 'flex-end', flexWrap: 'wrap' }}>
                            <PersonPicker label={t('pobl.responsible')} value={person} onChange={setPerson} search={searchActiveUsers} />
                            <button type="button" className="btn btn-pri" disabled={!person} onClick={() => void assign(o.code)}>{t('pobl.save')}</button>
                            <button type="button" className="btn btn-ter" onClick={() => { setEditing(null); setPerson(null) }}>{t('pobl.cancel')}</button>
                          </div>
                        )
                        : (
                          <>
                            {o.responsible?.name ?? <span className="sub2">{t('pobl.no_responsible')}</span>}
                            {canAssign && <button type="button" className="btn btn-ter" onClick={() => setEditing(o.code)}>{t('pobl.change')}</button>}
                          </>
                        )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </section>
        )}
        {toastNode}
      </main>
    </>
  )
}
