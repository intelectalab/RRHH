'use client'

import { useT } from '@/lib/useT'

export const DOCUMENT_TYPES = ['dni', 'ce', 'passport', 'ptp', 'cpp', 'other'] as const
export type DocumentType = (typeof DOCUMENT_TYPES)[number]

/** Tipo y número de documento para ingresar o activar la cuenta sin correo (SEG-2). */
export function DocumentFields({ idPrefix, type, number, onType, onNumber, invalid }: {
  idPrefix: string
  type: DocumentType
  number: string
  onType: (v: DocumentType) => void
  onNumber: (v: string) => void
  invalid?: boolean
}) {
  const { t } = useT()
  return (
    <div className="doc-campos mb-3">
      <div>
        <label className="form-label" htmlFor={`${idPrefix}-doctype`}>{t('auth.doc_type')}</label>
        <select id={`${idPrefix}-doctype`} className="form-select" value={type} onChange={(e) => onType(e.target.value as DocumentType)}>
          {DOCUMENT_TYPES.map((d) => <option key={d} value={d}>{t(`auth.doc_${d}`)}</option>)}
        </select>
      </div>
      <div>
        <label className="form-label" htmlFor={`${idPrefix}-docnum`}>{t('auth.doc_number')}</label>
        <input
          id={`${idPrefix}-docnum`}
          className={`form-control${invalid ? ' is-invalid' : ''}`}
          inputMode={type === 'dni' ? 'numeric' : 'text'}
          autoComplete="username"
          maxLength={20}
          required
          value={number}
          onChange={(e) => onNumber(e.target.value.replace(/[^A-Za-z0-9-]/g, ''))}
        />
      </div>
    </div>
  )
}
