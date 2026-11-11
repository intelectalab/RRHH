'use client'

import { useRouter } from 'next/navigation'
import { useEffect, useState, type FormEvent } from 'react'
import { api, ApiRequestError } from '@/lib/api'
import { formErrors } from '@/lib/errors'
import { useT } from '@/lib/useT'
import { MfaSetup } from './MfaSetup'

type Status = { enabled: boolean; required: boolean; step: 'ok' | 'verify' | 'enroll' }

/**
 * SEG-3 · Segundo paso del ingreso. «verify»: código de la aplicación o de respaldo.
 * «enroll»: el rol o la organización exige el segundo factor y aún no está configurado.
 */
export function MfaChallenge() {
  const { t } = useT()
  const router = useRouter()
  const [status, setStatus] = useState<Status | null>(null)
  const [recovery, setRecovery] = useState(false)
  const [code, setCode] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  useEffect(() => {
    api.get<{ data: Status }>('/me/mfa')
      .then((r) => (r.data.step === 'ok' ? router.replace('/') : setStatus(r.data)))
      .catch((e) => { if (e instanceof ApiRequestError && e.status === 401) router.replace('/ingresar') })
  }, [router])

  async function verify(e: FormEvent<HTMLFormElement>) {
    e.preventDefault()
    setBusy(true)
    setError(null)
    try {
      await api.post('/auth/mfa/verify', { code })
      router.replace('/')
    } catch (err) {
      if (err instanceof ApiRequestError && err.status === 401) {
        router.replace('/ingresar')
        return
      }
      const r = formErrors(err, t)
      setError(r.fields.code ?? r.general)
      setCode('')
    } finally {
      setBusy(false)
    }
  }

  async function cancel() {
    try { await api.post('/auth/logout', {}) } finally { router.replace('/ingresar') }
  }

  if (!status) return <p className="sub2"><span className="spin" aria-hidden="true" /> {t('common.loading')}</p>

  if (status.step === 'enroll') {
    return (
      <>
        <h1>{t('mfa.enroll_title')}</h1>
        <p className="text-secondary mb-4">{t('mfa.enroll_intro')}</p>
        <MfaSetup onDone={() => router.replace('/')} doneLabel={t('mfa.continue')} />
        <button type="button" className="btn btn-ter btn-sm mt-4" onClick={() => void cancel()}>{t('mfa.cancel_login')}</button>
      </>
    )
  }

  return (
    <>
      <h1>{t('mfa.verify_title')}</h1>
      <p className="text-secondary mb-4">{recovery ? t('mfa.verify_recovery_intro') : t('mfa.verify_intro')}</p>
      <form onSubmit={verify} noValidate>
        <label className="form-label" htmlFor="mfa-verify">{recovery ? t('mfa.recovery_code') : t('mfa.code')}</label>
        <input
          id="mfa-verify"
          className={`form-control mb-3${recovery ? '' : ' codigo-input'}${error ? ' is-invalid' : ''}`}
          inputMode={recovery ? 'text' : 'numeric'}
          autoComplete="one-time-code"
          autoFocus
          maxLength={recovery ? 12 : 7}
          value={code}
          onChange={(e) => setCode(e.target.value)}
          aria-invalid={error ? true : undefined}
          aria-describedby={error ? 'mfa-verify-error' : undefined}
          required
        />
        {error && <div id="mfa-verify-error" className="invalid-feedback mb-2" role="alert">{error}</div>}
        <button type="submit" className="btn btn-primary btn-bloque btn-grande" disabled={busy || code.trim() === ''}>
          {busy && <span className="spin" aria-hidden="true" />}{t('mfa.verify')}
        </button>
      </form>
      <div className="fila-entre mt-4">
        <button type="button" className="btn btn-ter btn-sm" onClick={() => { setRecovery((v) => !v); setCode(''); setError(null) }}>
          {recovery ? t('mfa.use_app') : t('mfa.use_recovery')}
        </button>
        <button type="button" className="btn btn-ter btn-sm" onClick={() => void cancel()}>{t('mfa.cancel_login')}</button>
      </div>
    </>
  )
}
