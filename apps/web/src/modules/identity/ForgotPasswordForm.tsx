'use client'

import Link from 'next/link'
import { useState, type FormEvent } from 'react'
import { api } from '@/lib/api'
import { useT } from '@/lib/useT'
import { authErrorKey } from './errors'

export function ForgotPasswordForm() {
  const { t } = useT()
  const [email, setEmail] = useState('')
  const [busy, setBusy] = useState(false)
  const [sent, setSent] = useState(false)
  const [error, setError] = useState<string | null>(null)

  async function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setBusy(true)
    setError(null)
    try {
      await api.post('/auth/password/forgot', { email })
      setSent(true)
    } catch (e) {
      setError(t(authErrorKey(e)))
    } finally {
      setBusy(false)
    }
  }

  return (
    <>
      <h1>{t('auth.forgot_title')}</h1>
      <p className="text-secondary mb-4">{t('auth.forgot_intro')}</p>
      {sent ? (
        <p className="aviso-ok mb-3" role="status">{t('auth.forgot_sent')}</p>
      ) : (
        <form onSubmit={onSubmit} noValidate>
          <label className="form-label" htmlFor="f-email">{t('auth.email')}</label>
          <input id="f-email" className="form-control mb-3" type="email" autoComplete="username" required value={email} onChange={(e) => setEmail(e.target.value)} />
          {error && <div className="invalid-feedback mb-2" role="alert">{error}</div>}
          <button type="submit" className="btn btn-primary btn-bloque btn-grande" disabled={busy}>
            {busy && <span className="spin" aria-hidden="true" />}
            {t('auth.forgot_submit')}
          </button>
        </form>
      )}
      <p className="small mt-4"><Link href="/ingresar">{t('auth.back_to_login')}</Link></p>
    </>
  )
}
