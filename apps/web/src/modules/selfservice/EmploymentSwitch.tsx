'use client'

import { useT } from '@/lib/useT'
import type { OwnEmployment } from './types'

/** RN-ESS-04 · Con varias relaciones laborales propias, el colaborador elige con cuál trabaja. */
export function EmploymentSwitch({ employments, value, onChange }: { employments: OwnEmployment[]; value: string; onChange: (id: string) => void }) {
  const { t } = useT()
  return (
    <div className="mb-3 portal-switch">
      <label className="form-label" htmlFor="portal-employment">{t('portal.employment')}</label>
      <select id="portal-employment" className="form-select" value={value} onChange={(e) => onChange(e.target.value)}>
        {employments.map((e) => (
          <option key={e.id} value={e.id}>
            {t('portal.employment_option', { entity: e.legal_entity_name ?? '—', code: e.employee_code, status: t(`emp.status_${e.status}`) })}
          </option>
        ))}
      </select>
    </div>
  )
}
