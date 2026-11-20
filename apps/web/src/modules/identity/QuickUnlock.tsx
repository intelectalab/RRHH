'use client'

import { useRouter } from 'next/navigation'
import { useEffect, useState, type FormEvent } from 'react'
import { api, ApiRequestError } from '@/lib/api'
import { useT } from '@/lib/useT'
import { authErrorKey } from './errors'
import { LoginForm } from './LoginForm'

type Status = { available: boolean; display_name: string | null; days: number }

/** ESS-6 · Solo dígitos, como máximo 8 (el servidor valida que no sea trivial). */
export function cleanPin(value: string): string {
  return value.replace(/\D/g, '').slice(0, 8)
}

/**
 * ESS-6 · Entrada al ingreso: si este teléfono tiene reingreso con PIN vigente, se pide el PIN; si no (o
 * si la persona prefiere), el ingreso de siempre con clave o código.
 */
export function SignInEntry() {
  const [status, setStatus] = useState<Status | null>(null)
  const [usePassword, setUsePassword] = useState(false)

  useEffect(() => {
    void api.get<{ data: Status }>('/auth/quick-unlock').then((r) => setStatus(r.data)).catch(() => setStatus({ available: false, display_name: null, days: 0 }))
  }, [])

  if (status === null) return null
  if (!status.available || usePassword) return <LoginForm />

  return <QuickUnlockSignIn name={status.display_name} onOtherAccount={() => setUsePassword(true)} />
}

function QuickUnlockSignIn({ name, onOtherAccount }: { name: string | null; onOtherAccount: () => void }) {
  const { t } = useT()
  const router = useRouter()
  const [pin, setPin] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setBusy(true)
    setError(null)
    try {
      const res = await api.post<{ data: { mfa_step?: 'ok' | 'verify' | 'enroll' } }>('/auth/quick-unlock/sign-in', { pin })
      router.replace(res.data.mfa_step && res.data.mfa_step !== 'ok' ? '/verificacion' : '/')
    } catch (e) {
      setPin('')
      if (e instanceof ApiRequestError && e.status === 401) {
        // El teléfono ya no tiene PIN vigente (venció o se olvidó tras varios errores): ingreso normal.
        onOtherAccount()
        return
      }
      setError(e instanceof ApiRequestError && e.status === 422 ? (e.details[0]?.message ?? e.message) : t(authErrorKey(e)))
    } finally {
      setBusy(false)
    }
  }

  async function forget() {
    await api.post('/auth/quick-unlock/forget').catch(() => undefined)
    onOtherAccount()
  }

  return (
    <form className="pin-form" onSubmit={(e) => void submit(e)} noValidate>
      <h1 className="h4">{name ? t('pin.hello', { name }) : t('pin.title')}</h1>
      <p className="text-secondary">{t('pin.text')}</p>
      <label className="form-label" htmlFor="pin-in">{t('pin.label')}</label>
      <input id="pin-in" className={`form-control pin-input${error ? ' is-invalid' : ''}`} inputMode="numeric" autoComplete="one-time-code" pattern="[0-9]*"
        value={pin} onChange={(e) => setPin(cleanPin(e.target.value))} aria-describedby={error ? 'pin-err' : undefined} autoFocus />
      {error && <div id="pin-err" className="invalid-feedback d-block" role="alert">{error}</div>}
      <button type="submit" className="btn btn-primary btn-lg" disabled={busy || pin.length < 4}>{t('pin.enter')}</button>
      <button type="button" className="btn btn-ter" onClick={onOtherAccount}>{t('pin.other')}</button>
      <button type="button" className="btn btn-ter btn-sm" onClick={() => void forget()}>{t('pin.forget')}</button>
    </form>
  )
}

/**
 * ESS-6 · Activar o cambiar el reingreso con PIN en este teléfono (desde una sesión ya abierta).
 */
export function QuickUnlockSetup({ toast }: { toast: (text: string, kind?: 'ok' | 'error') => void }) {
  const { t } = useT()
  const [status, setStatus] = useState<Status | null>(null)
  const [pin, setPin] = useState('')
  const [confirm, setConfirm] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const load = () => api.get<{ data: Status }>('/auth/quick-unlock').then((r) => setStatus(r.data)).catch(() => setStatus(null))
  useEffect(() => { void load() }, [])

  if (status === null || status.days === 0) return null

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setBusy(true)
    setError(null)
    try {
      await api.post('/me/quick-unlock', { pin, pin_confirmation: confirm })
      setPin('')
      setConfirm('')
      toast(t('pin.saved'))
      await load()
    } catch (e) {
      setError(e instanceof ApiRequestError ? (e.details[0]?.message ?? e.message) : t('auth.generic_error'))
    } finally {
      setBusy(false)
    }
  }

  async function forget() {
    await api.post('/auth/quick-unlock/forget').catch(() => undefined)
    toast(t('pin.forgotten'))
    await load()
  }

  return (
    <section className="card">
      <div className="card-body">
        <h2 className="h6">{t('pin.setup_title')}</h2>
        <p className="sub2">{t(status.available ? 'pin.setup_active' : 'pin.setup_text', { days: status.days })}</p>
        <form className="pin-form" onSubmit={(e) => void submit(e)} noValidate>
          <label className="form-label" htmlFor="pin-new">{t('pin.new')}</label>
          <input id="pin-new" className="form-control" type="password" inputMode="numeric" autoComplete="new-password" value={pin} onChange={(e) => setPin(cleanPin(e.target.value))} />
          <label className="form-label" htmlFor="pin-confirm">{t('pin.confirm')}</label>
          <input id="pin-confirm" className={`form-control${error ? ' is-invalid' : ''}`} type="password" inputMode="numeric" autoComplete="new-password" value={confirm} onChange={(e) => setConfirm(cleanPin(e.target.value))} />
          {error && <div className="invalid-feedback d-block" role="alert">{error}</div>}
          <div className="d-flex gap-2 flex-wrap">
            <button type="submit" className="btn btn-primary" disabled={busy || pin.length < 4}>{t(status.available ? 'pin.change' : 'pin.activate')}</button>
            {status.available && <button type="button" className="btn btn-ter" onClick={() => void forget()}>{t('pin.forget')}</button>}
          </div>
        </form>
      </div>
    </section>
  )
}
