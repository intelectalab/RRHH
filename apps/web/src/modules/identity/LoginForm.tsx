'use client'

import Link from 'next/link'
import { useRouter } from 'next/navigation'
import { useState, type FormEvent } from 'react'
import { api, ApiRequestError } from '@/lib/api'
import { useT } from '@/lib/useT'
import { authErrorKey } from './errors'

export function LoginForm() {
  const { t } = useT()
  const router = useRouter()
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [visible, setVisible] = useState(false)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  async function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setBusy(true)
    setError(null)
    try {
      const res = await api.post<{ data: { mfa_step?: 'ok' | 'verify' | 'enroll' } }>('/auth/login', { email, password })
      // SEG-3: con el segundo paso pendiente, primero el código (o configurarlo si el rol lo exige).
      router.replace(res.data.mfa_step && res.data.mfa_step !== 'ok' ? '/verificacion' : '/')
    } catch (e) {
      // El servidor ya responde un mensaje que no revela si la cuenta existe.
      setError(e instanceof ApiRequestError && e.status === 422 ? e.message : t(authErrorKey(e)))
    } finally {
      setBusy(false)
    }
  }

  return (
    <>
      <h1>{t('auth.login_title')}</h1>
      <p className="text-secondary mb-4">{t('auth.login_intro')}</p>
      <form onSubmit={onSubmit} noValidate>
        <label className="form-label" htmlFor="l-email">{t('auth.email')}</label>
        <input
          id="l-email"
          className="form-control mb-3"
          type="email"
          autoComplete="username"
          required
          value={email}
          onChange={(e) => setEmail(e.target.value)}
        />
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
        {error && <div id="l-error" className="invalid-feedback mb-2" role="alert">{error}</div>}
        <button type="submit" className="btn btn-primary btn-bloque btn-grande" disabled={busy}>
          {busy && <span className="spin" aria-hidden="true" />}
          {busy ? t('auth.submitting') : t('auth.login')}
        </button>
      </form>
      <p className="small text-secondary mt-4">{t('auth.help')}</p>
    </>
  )
}
