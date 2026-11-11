'use client'

import { useEffect, useRef, useState, type FormEvent } from 'react'
import { api } from '@/lib/api'
import { formErrors } from '@/lib/errors'
import { useT } from '@/lib/useT'
import { QrCode } from '@/ui/components/QrCode'
import { RecoveryCodes } from './RecoveryCodes'

type Start = { secret: string; otpauth_uri: string }

/**
 * SEG-3 · Configurar la aplicación autenticadora: QR (o clave manual) → código de confirmación →
 * códigos de respaldo, que se muestran una sola vez.
 */
export function MfaSetup({ onDone, doneLabel }: { onDone: () => void; doneLabel: string }) {
  const { t } = useT()
  const [start, setStart] = useState<Start | null>(null)
  const [code, setCode] = useState('')
  const [codes, setCodes] = useState<string[] | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  // Se inicia al abrirse; si falla, el botón permite reintentar.
  const started = useRef(false)
  useEffect(() => {
    if (started.current) return
    started.current = true
    void begin()
  }, []) // eslint-disable-line react-hooks/exhaustive-deps

  async function begin() {
    setBusy(true)
    setError(null)
    try {
      setStart((await api.post<{ data: Start }>('/me/mfa/totp', {})).data)
    } catch (e) {
      setError(formErrors(e, t).general)
    } finally {
      setBusy(false)
    }
  }

  async function confirm(e: FormEvent<HTMLFormElement>) {
    e.preventDefault()
    setBusy(true)
    setError(null)
    try {
      setCodes((await api.post<{ data: { recovery_codes: string[] } }>('/me/mfa/totp/confirm', { code })).data.recovery_codes)
    } catch (err) {
      const r = formErrors(err, t)
      setError(r.fields.code ?? r.general)
    } finally {
      setBusy(false)
    }
  }

  if (codes) return <RecoveryCodes codes={codes} onDone={onDone} doneLabel={doneLabel} />

  if (!start) {
    return (
      <div>
        {error && <div className="invalid-feedback mb-2" role="alert">{error}</div>}
        <button type="button" className="btn btn-primary" onClick={() => void begin()} disabled={busy}>{busy && <span className="spin" aria-hidden="true" />}{busy ? t('common.loading') : t('common.retry')}</button>
      </div>
    )
  }

  return (
    <form onSubmit={confirm} noValidate>
      <ol className="pasos-mfa">
        <li>{t('mfa.step_scan')}</li>
      </ol>
      <div className="qr-caja"><QrCode value={start.otpauth_uri} label={t('mfa.qr_label')} /></div>
      <p className="sub2">{t('mfa.manual_key')} <code className="clave-manual">{start.secret.replace(/(.{4})/g, '$1 ').trim()}</code></p>
      <ol className="pasos-mfa" start={2}>
        <li><label className="form-label" htmlFor="mfa-code">{t('mfa.step_code')}</label></li>
      </ol>
      <input
        id="mfa-code"
        className={`form-control mb-2 codigo-input${error ? ' is-invalid' : ''}`}
        inputMode="numeric"
        autoComplete="one-time-code"
        maxLength={7}
        value={code}
        onChange={(e) => setCode(e.target.value.replace(/[^\d ]/g, ''))}
        aria-invalid={error ? true : undefined}
        aria-describedby={error ? 'mfa-code-error' : undefined}
        required
      />
      {error && <div id="mfa-code-error" className="invalid-feedback mb-2" role="alert">{error}</div>}
      <button type="submit" className="btn btn-primary" disabled={busy || code.replace(/\s/g, '').length !== 6}>{busy && <span className="spin" aria-hidden="true" />}{t('mfa.confirm')}</button>
    </form>
  )
}
