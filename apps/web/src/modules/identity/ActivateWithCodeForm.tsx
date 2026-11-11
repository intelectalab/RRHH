'use client'

import Link from 'next/link'
import { useRouter } from 'next/navigation'
import { useState, type FormEvent } from 'react'
import { api, ApiRequestError } from '@/lib/api'
import { useT } from '@/lib/useT'
import { DocumentFields, type DocumentType } from './DocumentFields'
import { authErrorKey } from './errors'

/**
 * SEG-2 · Activar la cuenta sin correo: documento + código de activación (entregado por RRHH o
 * recibido por mensaje) + clave nueva. Activar abre la sesión.
 */
export function ActivateWithCodeForm() {
  const { t } = useT()
  const router = useRouter()
  const [docType, setDocType] = useState<DocumentType>('dni')
  const [docNumber, setDocNumber] = useState('')
  const [code, setCode] = useState('')
  const [password, setPassword] = useState('')
  const [confirmation, setConfirmation] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  async function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (password !== confirmation) {
      setError(t('auth.passwords_mismatch'))
      return
    }
    setBusy(true)
    setError(null)
    try {
      const res = await api.post<{ data: { mfa_step?: string } }>('/auth/activation/code', {
        document_type: docType, document_number: docNumber, code, password, password_confirmation: confirmation,
      })
      router.replace(res.data.mfa_step && res.data.mfa_step !== 'ok' ? '/verificacion' : '/')
    } catch (e) {
      const detail = e instanceof ApiRequestError ? e.details[0]?.message : undefined
      setError(detail ?? t(authErrorKey(e)))
    } finally {
      setBusy(false)
    }
  }

  return (
    <>
      <h1>{t('auth.activate_title')}</h1>
      <p className="text-secondary mb-4">{t('auth.activate_code_intro')}</p>
      <form onSubmit={onSubmit} noValidate>
        <DocumentFields idPrefix="a" type={docType} number={docNumber} onType={setDocType} onNumber={setDocNumber} />
        <label className="form-label" htmlFor="a-code">{t('auth.activation_code')}</label>
        <input id="a-code" className="form-control mb-3 codigo-input" inputMode="numeric" autoComplete="one-time-code" maxLength={8} required value={code} onChange={(e) => setCode(e.target.value.replace(/\D/g, ''))} />
        <label className="form-label" htmlFor="a-password">{t('auth.new_password')}</label>
        <input id="a-password" className="form-control mb-1" type="password" autoComplete="new-password" required value={password} onChange={(e) => setPassword(e.target.value)} aria-describedby="a-pw-help" />
        <p id="a-pw-help" className="small text-secondary mb-3">{t('auth.reset_intro')}</p>
        <label className="form-label" htmlFor="a-confirm">{t('auth.confirm_password')}</label>
        <input id="a-confirm" className={`form-control mb-3${error ? ' is-invalid' : ''}`} type="password" autoComplete="new-password" required value={confirmation} onChange={(e) => setConfirmation(e.target.value)} aria-invalid={error ? true : undefined} aria-describedby={error ? 'a-error' : undefined} />
        {error && <div id="a-error" className="invalid-feedback mb-2" role="alert">{error}</div>}
        <button type="submit" className="btn btn-primary btn-bloque btn-grande" disabled={busy || code.length !== 8 || docNumber.length < 4 || password === ''}>
          {busy && <span className="spin" aria-hidden="true" />}{t('auth.activate_submit')}
        </button>
      </form>
      <p className="small mt-4"><Link href="/ingresar">{t('auth.back_to_login')}</Link></p>
    </>
  )
}
