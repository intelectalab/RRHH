'use client'

import { useT } from '@/lib/useT'
import { DOCUMENT_TYPES, type DocumentType } from './DocumentFields'

export type LoginIdentity = { docType: DocumentType; docNumber: string; phone: string }

/**
 * SEG-2 · Documento y celular con los que ingresa quien no tiene correo. Al editar se muestran
 * enmascarados: escribir un valor nuevo lo reemplaza; dejarlo vacío conserva el actual.
 */
export function LoginIdentityFields({ idPrefix, value, onChange, maskedDocument, maskedPhone }: {
  idPrefix: string
  value: LoginIdentity
  onChange: (v: LoginIdentity) => void
  maskedDocument?: string | null
  maskedPhone?: string | null
}) {
  const { t } = useT()
  return (
    <>
      <div className="doc-campos mb-3">
        <div>
          <label className="form-label" htmlFor={`${idPrefix}-doctype`}>{t('users.document')}</label>
          <select id={`${idPrefix}-doctype`} className="form-select" value={value.docType} onChange={(e) => onChange({ ...value, docType: e.target.value as DocumentType })}>
            {DOCUMENT_TYPES.map((d) => <option key={d} value={d}>{t(`auth.doc_${d}`)}</option>)}
          </select>
        </div>
        <div>
          <label className="form-label" htmlFor={`${idPrefix}-docnum`}>{t('users.doc_number')}</label>
          <input id={`${idPrefix}-docnum`} className="form-control" maxLength={20} autoComplete="off" placeholder={maskedDocument ?? ''} value={value.docNumber} onChange={(e) => onChange({ ...value, docNumber: e.target.value.replace(/[^A-Za-z0-9-]/g, '') })} />
        </div>
      </div>
      <label className="form-label" htmlFor={`${idPrefix}-phone`}>{t('users.phone')}</label>
      <input id={`${idPrefix}-phone`} className="form-control mb-1" type="tel" inputMode="tel" autoComplete="off" placeholder={maskedPhone ?? '+51 987 654 321'} value={value.phone} onChange={(e) => onChange({ ...value, phone: e.target.value.replace(/[^0-9+ ()-]/g, '') })} aria-describedby={`${idPrefix}-phone-help`} />
      <p id={`${idPrefix}-phone-help`} className="small text-secondary mb-3">{t('users.phone_help')}</p>
    </>
  )
}

/** Campos a enviar: solo lo que se escribió (al editar, vacío = sin cambios). */
export function identityPayload(v: LoginIdentity): Record<string, string> {
  const out: Record<string, string> = {}
  if (v.docNumber.trim() !== '') {
    out.document_type = v.docType
    out.document_number = v.docNumber.trim()
  }
  if (v.phone.trim() !== '') out.phone = v.phone.trim()
  return out
}
