'use client'

import { useCallback, useEffect, useState } from 'react'
import { api } from '@/lib/api'
import { formatDate } from '@/lib/format'
import { useT } from '@/lib/useT'
import { Card } from '@/ui/components/Card'
import { Notice } from '@/ui/components/Notice'
import { ErrorState } from '@/ui/components/States'
import type { ProfileTabContext, WorkData, WorkFieldKey } from './types'
import { seniorityText } from './seniority'

const JOB: WorkFieldKey[] = ['job_profile', 'position', 'org_unit', 'site', 'manager', 'cost_center', 'work_modality', 'schedule']
const RELATION: WorkFieldKey[] = ['employee_code', 'legal_entity', 'hire_date', 'seniority_date', 'kind', 'worker_category', 'labor_regime', 'contract_type', 'status']

/**
 * COL-5 · Resumen: datos laborales leídos de la situación vigente. Cada dato dice desde cuándo rige y, si
 * tiene historia, enlaza a ella; ninguno se edita aquí (se registra un movimiento en la pestaña Historial).
 */
export function SummaryTab({ header, onHistory }: ProfileTabContext & { onHistory: () => void }) {
  const { t, locale } = useT()
  const [data, setData] = useState<WorkData | null>(null)
  const [failed, setFailed] = useState(false)

  const load = useCallback(async () => {
    try {
      setData((await api.get<{ data: WorkData }>(`/employments/${header.id}/work-data`)).data)
      setFailed(false)
    } catch {
      setFailed(true)
    }
  }, [header.id])

  useEffect(() => { void load() }, [load, header.version])

  if (failed) return <ErrorState text={t('common.load_error')} retryLabel={t('common.retry')} onRetry={() => void load()} />
  if (!data) return <span className="esq" style={{ width: 240 }} />

  const value = (key: WorkFieldKey): string | null => {
    const f = data.fields[key]
    if (!f || f.value === null) return null
    switch (key) {
      case 'hire_date':
      case 'seniority_date':
        return formatDate(locale, f.value)
      case 'work_modality':
        return t(`emp.modality_${f.value}`)
      case 'kind':
        return t(`emp.kind_${f.value}`)
      case 'worker_category':
        return t(`emp.category_${f.value}`)
      case 'status':
        return t(`emp.status_${f.value}`)
      case 'employee_code':
        return f.value
      default:
        return f.label ?? f.value
    }
  }

  const row = (key: WorkFieldKey) => {
    const f = data.fields[key]
    const shown = value(key)
    return (
      <div key={key} className="ficha-dato">
        <dt>{t(`ficha.f_${key}`)}</dt>
        <dd>
          <span>{shown ?? (key === 'schedule' ? t('ficha.no_schedule') : '—')}</span>
          {f?.since && shown && (
            <span className="sub2">
              {t('ficha.since', { date: formatDate(locale, f.since) })}
              {f.history === 'assignments' && (
                <>{' · '}<button type="button" className="ficha-link" onClick={onHistory}>{t('ficha.see_history')}</button></>
              )}
            </span>
          )}
        </dd>
      </div>
    )
  }

  return (
    <>
      {header.status === 'pending' && (
        <Notice tone="info" title={t('emp.pending_title')}>{t('emp.pending_text', { date: formatDate(locale, header.hire_date) })}</Notice>
      )}
      <div className="emp-resumen">
        <Card title={t('emp.section_job')} context={t('ficha.as_of', { date: formatDate(locale, data.as_of) })}>
          <dl className="ficha ficha-vig">{JOB.map(row)}</dl>
        </Card>
        <Card title={t('emp.section_relation')}>
          <dl className="ficha ficha-vig">
            {RELATION.map(row)}
            <div className="ficha-dato">
              <dt>{t('ficha.f_seniority')}</dt>
              <dd><span>{data.seniority ? seniorityText(t, data.seniority) : '—'}</span><span className="sub2">{t('ficha.seniority_calculated')}</span></dd>
            </div>
          </dl>
        </Card>
      </div>
    </>
  )
}
