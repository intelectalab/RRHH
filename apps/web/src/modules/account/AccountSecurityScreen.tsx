'use client'

import { useCallback, useEffect, useState, type FormEvent } from 'react'
import { api, ApiRequestError } from '@/lib/api'
import { formErrors } from '@/lib/errors'
import { formatDateTime, relativeTime } from '@/lib/format'
import { useT } from '@/lib/useT'
import { useSession } from '@/modules/session/SessionProvider'
import { PageHeader } from '@/ui/components/PageHeader'
import { ErrorState } from '@/ui/components/States'
import { useToast } from '@/ui/components/Toast'

type SessionRow = { id: string; device: string; ip: string | null; current: boolean; last_seen_at: string | null; created_at: string | null }
type Data = { sessions: SessionRow[]; idle_minutes: number; lifetime_minutes: number; password_min_length: number }

/** Menú de usuario → Seguridad de la cuenta (SEG-4, SEG-5): sesiones abiertas y cambio de clave. */
export function AccountSecurityScreen() {
  const { t, locale } = useT()
  const { session, reload } = useSession()
  const { toast, node: toastNode } = useToast()
  const [data, setData] = useState<Data | null>(null)
  const [loadError, setLoadError] = useState(false)
  const [form, setForm] = useState({ current_password: '', password: '', password_confirmation: '' })
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [busy, setBusy] = useState(false)

  const load = useCallback(async () => {
    try {
      setData((await api.get<{ data: Data }>('/me/sessions')).data)
      setLoadError(false)
    } catch {
      setLoadError(true)
    }
  }, [])
  useEffect(() => { void load() }, [load])

  async function revoke(id: string) {
    try {
      await api.post(`/me/sessions/${id}/revoke`, {})
      toast(t('acct.revoked'))
      await load()
    } catch (e) {
      toast(e instanceof ApiRequestError ? e.message : t('auth.generic_error'), 'error')
    }
  }

  async function submit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault()
    setErrors({})
    if (form.password !== form.password_confirmation) {
      setErrors({ password_confirmation: t('acct.mismatch') })
      return
    }
    setBusy(true)
    try {
      await api.post('/me/password', form)
      setForm({ current_password: '', password: '', password_confirmation: '' })
      toast(t('acct.changed'))
      await Promise.all([load(), reload()])
    } catch (err) {
      const r = formErrors(err, t)
      setErrors(r.general ? { ...r.fields, password: r.general } : r.fields)
    } finally {
      setBusy(false)
    }
  }

  const field = (k: keyof typeof form, label: string, auto: string) => (
    <div className="mb-3">
      <label className="form-label" htmlFor={`pw-${k}`}>{label}</label>
      <input id={`pw-${k}`} type="password" autoComplete={auto} className={`form-control${errors[k] ? ' is-invalid' : ''}`} value={form[k]} onChange={(e) => setForm({ ...form, [k]: e.target.value })} required />
      {errors[k] && <div className="invalid-feedback" role="alert">{errors[k]}</div>}
    </div>
  )

  return (
    <>
      <PageHeader title={t('acct.title')} sub={t('acct.sub')} />
      <main className="main" id="contenido" tabIndex={-1}>
        {session.user.must_change_password && <div className="nota" role="alert">{t('acct.must_change')}</div>}
        {loadError && <ErrorState text={t('common.load_error')} retryLabel={t('common.retry')} onRetry={() => void load()} />}
        <div className="grid-2">
          <section className="card">
            <div className="card-header"><h2>{t('acct.sessions')}</h2>{data && <span className="ctx">{t('acct.sessions_help', { idle: data.idle_minutes, hours: Math.round(data.lifetime_minutes / 60) })}</span>}</div>
            <ul className="sesiones">
              {data?.sessions.map((s) => (
                <li key={s.id} className="sesion">
                  <div className="min-w-0">
                    <b>{s.device}</b>{s.current && <span className="tag ok" style={{ marginLeft: 8 }}>{t('acct.this_device')}</span>}
                    <span className="sub2">{s.ip ?? ''}{s.ip ? ' · ' : ''}{t('acct.last_seen', { when: relativeTime(locale, s.last_seen_at) })} · <span title={formatDateTime(locale, s.created_at)}>{t('acct.since', { when: relativeTime(locale, s.created_at) })}</span></span>
                  </div>
                  {!s.current && <button type="button" className="btn btn-ter btn-sm" onClick={() => void revoke(s.id)}>{t('acct.revoke')}</button>}
                </li>
              ))}
            </ul>
          </section>
          <section className="bloque-plano">
            <div className="cab"><h2>{t('acct.password')}</h2></div>
            {data && <p className="sub2">{t('acct.password_help', { min: data.password_min_length })}</p>}
            <form onSubmit={submit} noValidate>
              {field('current_password', t('acct.current'), 'current-password')}
              {field('password', t('acct.new'), 'new-password')}
              {field('password_confirmation', t('acct.confirm'), 'new-password')}
              <button type="submit" className="btn btn-primary" disabled={busy}>{busy && <span className="spin" aria-hidden="true" />}{t('acct.save')}</button>
            </form>
          </section>
        </div>
      </main>
      {toastNode}
    </>
  )
}
