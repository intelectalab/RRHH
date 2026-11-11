'use client'

import { useRouter } from 'next/navigation'
import { useEffect, useState, type FormEvent } from 'react'
import { ApiRequestError } from '@/lib/api'
import { formErrors } from '@/lib/errors'
import { useT } from '@/lib/useT'
import { QrCode } from '@/ui/components/QrCode'
import { consoleApi, type ConsoleMe, type ConsoleStep } from './consoleApi'

type Step = 'password' | Exclude<ConsoleStep, 'ok'>

/**
 * SAS-1 · Ingreso a la consola: correo y clave, y después el segundo factor, que la consola exige
 * siempre (ADR 0011). El primer ingreso configura la aplicación autenticadora.
 */
export function ConsoleLogin() {
  const { t } = useT()
  const router = useRouter()
  const [step, setStep] = useState<Step | null>(null)
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [code, setCode] = useState('')
  const [enroll, setEnroll] = useState<{ secret: string; otpauth_uri: string } | null>(null)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    consoleApi.get<{ data: ConsoleMe }>('/me')
      .then((r) => (r.data.mfa_step === 'ok' ? router.replace('/plataforma') : setStep(r.data.mfa_step)))
      .catch(() => setStep('password'))
  }, [router])

  useEffect(() => {
    if (step !== 'enroll' || enroll) return
    consoleApi.post<{ data: { secret: string; otpauth_uri: string } }>('/auth/mfa/totp', {})
      .then((r) => setEnroll(r.data))
      .catch((e) => setError(formErrors(e, t).general))
  }, [step, enroll]) // eslint-disable-line react-hooks/exhaustive-deps

  async function signIn(e: FormEvent<HTMLFormElement>) {
    e.preventDefault()
    setBusy(true)
    setError(null)
    try {
      const r = await consoleApi.post<{ data: ConsoleMe }>('/auth/login', { email, password })
      setPassword('')
      if (r.data.mfa_step === 'ok') router.replace('/plataforma')
      else setStep(r.data.mfa_step)
    } catch (err) {
      setError(err instanceof ApiRequestError && err.code === 'invalid_credentials' ? t('console.invalid_credentials') : formErrors(err, t).general)
    } finally {
      setBusy(false)
    }
  }

  async function sendCode(e: FormEvent<HTMLFormElement>) {
    e.preventDefault()
    setBusy(true)
    setError(null)
    try {
      await consoleApi.post(step === 'enroll' ? '/auth/mfa/totp/confirm' : '/auth/mfa/verify', { code })
      router.replace('/plataforma')
    } catch (err) {
      if (err instanceof ApiRequestError && err.status === 401) {
        setStep('password')
      } else {
        const r = formErrors(err, t)
        setError(r.fields.code ?? r.general)
      }
      setCode('')
    } finally {
      setBusy(false)
    }
  }

  async function cancel() {
    try { await consoleApi.post('/auth/logout', {}) } catch { /* la sesión ya no existe */ }
    setStep('password')
    setEnroll(null)
    setCode('')
    setError(null)
  }

  if (step === null) return <p className="sub2"><span className="spin" aria-hidden="true" /> {t('common.loading')}</p>

  if (step === 'password') {
    return (
      <>
        <h1>{t('console.login_title')}</h1>
        <p className="text-secondary mb-4">{t('console.login_intro')}</p>
        <form onSubmit={signIn} noValidate>
          <label className="form-label" htmlFor="c-email">{t('console.email')}</label>
          <input id="c-email" type="email" className="form-control mb-3" autoComplete="username" value={email} onChange={(e) => setEmail(e.target.value)} required />
          <label className="form-label" htmlFor="c-pass">{t('console.password')}</label>
          <input id="c-pass" type="password" className={`form-control mb-3${error ? ' is-invalid' : ''}`} autoComplete="current-password" value={password} onChange={(e) => setPassword(e.target.value)} aria-describedby={error ? 'c-error' : undefined} required />
          {error && <div id="c-error" className="invalid-feedback mb-2" role="alert">{error}</div>}
          <button type="submit" className="btn btn-primary btn-bloque btn-grande" disabled={busy || email.trim() === '' || password === ''}>
            {busy && <span className="spin" aria-hidden="true" />}{t('console.sign_in')}
          </button>
        </form>
      </>
    )
  }

  const enrolling = step === 'enroll'
  return (
    <>
      <h1>{t(enrolling ? 'console.enroll_title' : 'console.verify_title')}</h1>
      <p className="text-secondary mb-4">{t(enrolling ? 'console.enroll_intro' : 'console.verify_intro')}</p>
      {enrolling && enroll && (
        <>
          <ol className="pasos-mfa"><li>{t('mfa.step_scan')}</li></ol>
          <div className="qr-caja"><QrCode value={enroll.otpauth_uri} label={t('mfa.qr_label')} /></div>
          <p className="sub2">{t('mfa.manual_key')} <code className="clave-manual">{enroll.secret.replace(/(.{4})/g, '$1 ').trim()}</code></p>
        </>
      )}
      {(!enrolling || enroll) && (
        <form onSubmit={sendCode} noValidate>
          <label className="form-label" htmlFor="c-code">{enrolling ? t('mfa.step_code') : t('mfa.code')}</label>
          <input
            id="c-code"
            className={`form-control mb-3 codigo-input${error ? ' is-invalid' : ''}`}
            inputMode="numeric"
            autoComplete="one-time-code"
            autoFocus
            maxLength={7}
            value={code}
            onChange={(e) => setCode(e.target.value.replace(/[^\d ]/g, ''))}
            aria-invalid={error ? true : undefined}
            aria-describedby={error ? 'c-code-error' : undefined}
            required
          />
          {error && <div id="c-code-error" className="invalid-feedback mb-2" role="alert">{error}</div>}
          <button type="submit" className="btn btn-primary btn-bloque btn-grande" disabled={busy || code.replace(/\s/g, '').length !== 6}>
            {busy && <span className="spin" aria-hidden="true" />}{enrolling ? t('mfa.confirm') : t('mfa.verify')}
          </button>
        </form>
      )}
      {enrolling && !enroll && error && <div className="invalid-feedback mb-2" role="alert">{error}</div>}
      {enrolling && <p className="sub2 mt-3">{t('console.enroll_lost')}</p>}
      <button type="button" className="btn btn-ter btn-sm mt-4" onClick={() => void cancel()}>{t('console.logout')}</button>
    </>
  )
}
