'use client'

import Link from 'next/link'
import { useRouter } from 'next/navigation'
import { useEffect, useState, type FormEvent } from 'react'
import { api, ApiRequestError } from '@/lib/api'
import { useT } from '@/lib/useT'
import { DocumentFields, type DocumentType } from './DocumentFields'
import { authErrorKey, TENANT_NOTICES } from './errors'

type Mode = 'email' | 'document'
type MfaStep = 'ok' | 'verify' | 'enroll'

/** Segundos que la pantalla espera antes de ofrecer otro código (el servidor aplica su propia espera). */
const RESEND_WAIT = 60

/**
 * Ingreso (SEG-1, SEG-2): correo o usuario + clave, o documento + clave o código por mensaje
 * para el personal sin correo (RN-SEG-09).
 */
export function LoginForm() {
  const { t } = useT()
  const router = useRouter()
  const [mode, setMode] = useState<Mode>('email')
  const [login, setLogin] = useState('')
  const [docType, setDocType] = useState<DocumentType>('dni')
  const [docNumber, setDocNumber] = useState('')
  const [password, setPassword] = useState('')
  const [visible, setVisible] = useState(false)
  const [codeSent, setCodeSent] = useState(false)
  const [code, setCode] = useState('')
  const [wait, setWait] = useState(0)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [info, setInfo] = useState<string | null>(null)

  useEffect(() => {
    // SAS-2: si la organización se suspendió o se dio de baja durante la sesión, se explica aquí.
    const notice = new URLSearchParams(window.location.search).get('aviso')
    if (notice && (TENANT_NOTICES as readonly string[]).includes(notice)) setError(t(`auth.${notice}`))
  }, [t])

  useEffect(() => {
    if (wait <= 0) return
    const id = window.setTimeout(() => setWait((w) => w - 1), 1000)
    return () => window.clearTimeout(id)
  }, [wait])

  function done(step?: MfaStep) {
    // SEG-3: con el segundo paso pendiente, primero el código (o configurarlo si el rol lo exige).
    router.replace(step && step !== 'ok' ? '/verificacion' : '/')
  }

  function fail(e: unknown) {
    // El servidor ya responde un mensaje que no revela si la cuenta existe.
    const detail = e instanceof ApiRequestError && e.status === 422 ? (e.details[0]?.message ?? e.message) : null
    setError(detail ?? t(authErrorKey(e)))
  }

  async function signIn(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setBusy(true)
    setError(null)
    try {
      const body = mode === 'document'
        ? { document_type: docType, document_number: docNumber, password }
        : login.includes('@') ? { email: login, password } : { login, password }
      const res = await api.post<{ data: { mfa_step?: MfaStep } }>('/auth/login', body)
      done(res.data.mfa_step)
    } catch (e) {
      fail(e)
    } finally {
      setBusy(false)
    }
  }

  async function requestCode() {
    if (docNumber.trim().length < 4) {
      setError(t('auth.doc_required'))
      return
    }
    setBusy(true)
    setError(null)
    try {
      await api.post('/auth/otp/request', { document_type: docType, document_number: docNumber })
      setCodeSent(true)
      setCode('')
      setWait(RESEND_WAIT)
      setInfo(t('auth.code_sent'))
    } catch (e) {
      fail(e)
    } finally {
      setBusy(false)
    }
  }

  async function verifyCode(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setBusy(true)
    setError(null)
    try {
      const res = await api.post<{ data: { mfa_step?: MfaStep } }>('/auth/otp/verify', { document_type: docType, document_number: docNumber, code })
      done(res.data.mfa_step)
    } catch (e) {
      fail(e)
      setCode('')
    } finally {
      setBusy(false)
    }
  }

  function switchMode(next: Mode) {
    setMode(next)
    setError(null)
    setInfo(null)
    setCodeSent(false)
    setPassword('')
  }

  const passwordField = (
    <>
      <div className="fila-entre">
        <label className="form-label" htmlFor="l-password">{t('auth.password')}</label>
        <Link href="/recuperar-clave" className="small">{t('auth.forgot_password')}</Link>
      </div>
      <div className="input-group mb-3">
        <input
          id="l-password"
          className={`form-control${error ? ' is-invalid' : ''}`}
          type={visible ? 'text' : 'password'}
          autoComplete="current-password"
          required
          value={password}
          onChange={(e) => setPassword(e.target.value)}
          aria-invalid={error ? true : undefined}
          aria-describedby={error ? 'l-error' : undefined}
        />
        <button type="button" className="btn btn-sec" onClick={() => setVisible((v) => !v)} aria-label={t('auth.show_password')} aria-pressed={visible}>
          <svg width="16" height="16" viewBox="0 0 256 256" aria-hidden="true"><path fill="currentColor" d="M247.31 124.76c-.35-.79-8.82-19.58-27.65-38.41C194.57 61.26 162.88 48 128 48S61.43 61.26 36.34 86.35C17.51 105.18 9 124 8.69 124.76a8 8 0 0 0 0 6.5c.35.79 8.82 19.57 27.65 38.4C61.43 194.74 93.12 208 128 208s66.57-13.26 91.66-38.34c18.83-18.83 27.3-37.61 27.65-38.4a8 8 0 0 0 0-6.5ZM128 192c-30.78 0-57.67-11.19-79.93-33.25A133.5 133.5 0 0 1 25 128a133.3 133.3 0 0 1 23.07-30.75C70.33 75.19 97.22 64 128 64s57.67 11.19 79.93 33.25A133.5 133.5 0 0 1 231.05 128c-7.21 13.46-38.62 64-103.05 64Zm0-112a48 48 0 1 0 48 48 48.05 48.05 0 0 0-48-48Zm0 80a32 32 0 1 1 32-32 32 32 0 0 1-32 32Z" /></svg>
        </button>
      </div>
    </>
  )

  const errorBox = error && <div id="l-error" className="invalid-feedback mb-2" role="alert">{error}</div>

  return (
    <>
      <h1>{t('auth.login_title')}</h1>
      <p className="text-secondary mb-4">{t(mode === 'email' ? 'auth.login_intro' : 'auth.login_doc_intro')}</p>
      <div className="segmento mb-4" role="tablist" aria-label={t('auth.login_with')}>
        <button type="button" role="tab" aria-selected={mode === 'email'} className={mode === 'email' ? 'activo' : ''} onClick={() => switchMode('email')}>{t('auth.tab_email')}</button>
        <button type="button" role="tab" aria-selected={mode === 'document'} className={mode === 'document' ? 'activo' : ''} onClick={() => switchMode('document')}>{t('auth.tab_document')}</button>
      </div>

      {mode === 'email' && (
        <form onSubmit={signIn} noValidate>
          <label className="form-label" htmlFor="l-email">{t('auth.email_or_user')}</label>
          <input id="l-email" className="form-control mb-3" type="text" inputMode="email" autoComplete="username" required value={login} onChange={(e) => setLogin(e.target.value)} />
          {passwordField}
          {errorBox}
          <button type="submit" className="btn btn-primary btn-bloque btn-grande" disabled={busy}>
            {busy && <span className="spin" aria-hidden="true" />}
            {busy ? t('auth.submitting') : t('auth.login')}
          </button>
        </form>
      )}

      {mode === 'document' && !codeSent && (
        <form onSubmit={signIn} noValidate>
          <DocumentFields idPrefix="l" type={docType} number={docNumber} onType={setDocType} onNumber={setDocNumber} invalid={!!error} />
          {passwordField}
          {errorBox}
          <button type="submit" className="btn btn-primary btn-bloque btn-grande" disabled={busy || password === ''}>
            {busy && <span className="spin" aria-hidden="true" />}
            {busy ? t('auth.submitting') : t('auth.login')}
          </button>
          <div className="separador-o"><span>{t('auth.or')}</span></div>
          <button type="button" className="btn btn-sec btn-bloque" onClick={() => void requestCode()} disabled={busy}>{t('auth.send_code')}</button>
        </form>
      )}

      {mode === 'document' && codeSent && (
        <form onSubmit={verifyCode} noValidate>
          {info && <div className="nota" role="status">{info}</div>}
          <label className="form-label" htmlFor="l-code">{t('auth.code_label')}</label>
          <input
            id="l-code"
            className={`form-control mb-3 codigo-input${error ? ' is-invalid' : ''}`}
            inputMode="numeric"
            autoComplete="one-time-code"
            autoFocus
            maxLength={6}
            value={code}
            onChange={(e) => setCode(e.target.value.replace(/\D/g, ''))}
            aria-invalid={error ? true : undefined}
            aria-describedby={error ? 'l-error' : undefined}
          />
          {errorBox}
          <button type="submit" className="btn btn-primary btn-bloque btn-grande" disabled={busy || code.length !== 6}>
            {busy && <span className="spin" aria-hidden="true" />}
            {busy ? t('auth.submitting') : t('auth.login')}
          </button>
          <div className="fila-entre mt-4">
            <button type="button" className="btn btn-ter btn-sm" onClick={() => void requestCode()} disabled={busy || wait > 0}>
              {wait > 0 ? t('auth.resend_in', { s: wait }) : t('auth.resend')}
            </button>
            <button type="button" className="btn btn-ter btn-sm" onClick={() => { setCodeSent(false); setError(null); setInfo(null) }}>{t('auth.use_password')}</button>
          </div>
        </form>
      )}

      <p className="small text-secondary mt-4">
        {t('auth.help')} <Link href="/activar-cuenta">{t('auth.have_activation_code')}</Link>
      </p>
    </>
  )
}
