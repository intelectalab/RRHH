'use client'

import { useState } from 'react'
import { api, ApiRequestError } from '@/lib/api'
import { useT } from '@/lib/useT'

/**
 * SEG-3 · RN-SEG-08: exigir la verificación en dos pasos a quienes tengan el rol.
 * Vale también para roles de sistema; el cambio pide motivo y queda auditado.
 */
export function RoleMfaControl({ roleId, required, canChange, onChanged }: { roleId: string; required: boolean; canChange: boolean; onChanged: (msg: string) => void }) {
  const { t } = useT()
  const [next, setNext] = useState<boolean | null>(null)
  const [reason, setReason] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const value = next ?? required

  async function apply() {
    setBusy(true)
    setError(null)
    try {
      await api.post(`/roles/${roleId}/mfa-requirement`, { required: value, reason })
      setNext(null)
      setReason('')
      onChanged(t('roles.mfa_saved'))
    } catch (e) {
      setError(e instanceof ApiRequestError ? (e.details[0]?.message ?? e.message) : t('auth.generic_error'))
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="mt-4">
      <label className="check">
        <input type="checkbox" disabled={!canChange || busy} checked={value} onChange={(e) => setNext(e.target.checked === required ? null : e.target.checked)} />
        <span>
          {t('roles.mfa_required')}
          <span className="sub2">{t('roles.mfa_help')}</span>
        </span>
      </label>
      {next !== null && (
        <div className="mt-2" style={{ maxWidth: 480 }}>
          <label className="form-label" htmlFor={`mfa-reason-${roleId}`}>{t('roles.mfa_reason')}</label>
          <input id={`mfa-reason-${roleId}`} className={`form-control mb-2${error ? ' is-invalid' : ''}`} value={reason} onChange={(e) => setReason(e.target.value)} aria-invalid={error ? true : undefined} aria-describedby={error ? `mfa-reason-err-${roleId}` : undefined} />
          {error && <div id={`mfa-reason-err-${roleId}`} className="invalid-feedback mb-2" role="alert">{error}</div>}
          <div className="fila-acciones">
            <button type="button" className="btn btn-primary btn-sm" onClick={() => void apply()} disabled={busy || reason.trim().length < 5}>{busy && <span className="spin" aria-hidden="true" />}{t('roles.mfa_apply')}</button>
            <button type="button" className="btn btn-ter btn-sm" onClick={() => { setNext(null); setReason(''); setError(null) }}>{t('common.cancel')}</button>
          </div>
        </div>
      )}
    </div>
  )
}
