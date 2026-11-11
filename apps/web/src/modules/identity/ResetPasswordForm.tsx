'use client'

import Link from 'next/link'
import { useRouter, useSearchParams } from 'next/navigation'
import { useState, type FormEvent } from 'react'
import { api, ApiRequestError } from '@/lib/api'
import { useT } from '@/lib/useT'
import { ActivateWithCodeForm } from './ActivateWithCodeForm'
import { authErrorKey } from './errors'

/**
 * Formulario de nueva contraseña con enlace de un solo uso.
 * mode="reset": restablecer clave · mode="activate": activar cuenta invitada (abre sesión).
 */
export function ResetPasswordForm({ mode = 'reset' }: { mode?: 'reset' | 'activate' }) {
  const { t } = useT()
  const router = useRouter()
  const activating = mode === 'activate'
  const title = t(activating ? 'auth.activate_title' : 'auth.reset_title')
  const token = useSearchParams().get('token') ?? ''
  const [password, setPassword] = useState('')
  const [confirmation, setConfirmation] = useState('')
  const [busy, setBusy] = useState(false)
  const [done, setDone] = useState(false)
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
      await api.post(activating ? '/auth/invitation/accept' : '/auth/password/reset', { token, password, password_confirmation: confirmation })
      if (activating) {
        router.replace('/')
        return
      }
      setDone(true)
    } catch (e) {
      // Las reglas de la contraseña las valida el servidor; se muestra su mensaje.
      const detail = e instanceof ApiRequestError ? e.details[0]?.message : undefined
      setError(detail ?? t(authErrorKey(e)))
    } finally {
      setBusy(false)
    }
  }

  // SEG-2: sin enlace, quien no tiene correo activa con su documento y un código.
  if (token === '' && activating) return <ActivateWithCodeForm />

  if (token === '') {
    return (
      <>
        <h1>{title}</h1>
        <p className="invalid-feedback mb-3" role="alert">{t('auth.reset_missing_token')}</p>
        <p className="small"><Link href="/recuperar-clave">{t('auth.forgot_title')}</Link></p>
      </>
    )
  }

  return (
    <>
      <h1>{title}</h1>
      <p className="text-secondary mb-4">{t(activating ? 'auth.activate_intro' : 'auth.reset_intro')}</p>
      {done ? (
        <p className="aviso-ok mb-3" role="status">{t('auth.reset_done')}</p>
      ) : (
        <form onSubmit={onSubmit} noValidate>
          <label className="form-label" htmlFor="r-pass">{t('auth.new_password')}</label>
          <input id="r-pass" className="form-control mb-3" type="password" autoComplete="new-password" required value={password} onChange={(e) => setPassword(e.target.value)} />
          <label className="form-label" htmlFor="r-conf">{t('auth.confirm_password')}</label>
          <input id="r-conf" className="form-control mb-3" type="password" autoComplete="new-password" required value={confirmation} onChange={(e) => setConfirmation(e.target.value)} />
          {error && <div className="invalid-feedback mb-2" role="alert">{error}</div>}
          <button type="submit" className="btn btn-primary btn-bloque btn-grande" disabled={busy}>
            {busy && <span className="spin" aria-hidden="true" />}
            {t(activating ? 'auth.activate_submit' : 'auth.reset_submit')}
          </button>
        </form>
      )}
      <p className="small mt-4"><Link href="/ingresar">{t('auth.back_to_login')}</Link></p>
    </>
  )
}
