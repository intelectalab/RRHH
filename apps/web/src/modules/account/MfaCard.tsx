'use client'

import { useCallback, useEffect, useState, type FormEvent } from 'react'
import { api } from '@/lib/api'
import { formErrors } from '@/lib/errors'
import { formatDate } from '@/lib/format'
import { useT } from '@/lib/useT'
import { MfaSetup } from '@/modules/identity/MfaSetup'
import { RecoveryCodes } from '@/modules/identity/RecoveryCodes'

type Summary = { enabled: boolean; required: boolean; confirmed_at: string | null; recovery_codes_left: number }
type Mode = 'idle' | 'setup' | 'codes' | 'disable'

/** Seguridad de la cuenta → Verificación en dos pasos (SEG-3). */
export function MfaCard({ onToast }: { onToast: (text: string) => void }) {
  const { t, locale } = useT()
  const [data, setData] = useState<Summary | null>(null)
  const [mode, setMode] = useState<Mode>('idle')
  const [password, setPassword] = useState('')
  const [newCodes, setNewCodes] = useState<string[] | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  const load = useCallback(async () => {
    try { setData((await api.get<{ data: Summary }>('/me/mfa')).data) } catch { setData(null) }
  }, [])
  useEffect(() => { void load() }, [load])

  function open(next: Mode) {
    setMode(next)
    setPassword('')
    setError(null)
    setNewCodes(null)
  }

  async function withPassword(e: FormEvent<HTMLFormElement>) {
    e.preventDefault()
    setBusy(true)
    setError(null)
    try {
      if (mode === 'codes') {
        setNewCodes((await api.post<{ data: { recovery_codes: string[] } }>('/me/mfa/recovery-codes', { password })).data.recovery_codes)
      } else {
        await api.post('/me/mfa/disable', { password })
        onToast(t('mfa.disabled'))
        open('idle')
      }
      await load()
    } catch (err) {
      const r = formErrors(err, t)
      setError(r.fields.password ?? r.general)
    } finally {
      setBusy(false)
    }
  }

  if (!data) return null

  return (
    <section className="bloque-plano mt-4">
      <div className="cab"><h2>{t('mfa.title')}</h2></div>
      <div className="mfa-estado">
        <span className={`tag ${data.enabled ? 'ok' : ''}`}>{data.enabled ? t('mfa.on') : t('mfa.off')}</span>
        {data.required && <span className="tag">{t('mfa.required_tag')}</span>}
        {data.enabled && data.confirmed_at && <span className="sub2">{t('mfa.since', { date: formatDate(locale, data.confirmed_at) })} · {t('mfa.codes_left', { n: data.recovery_codes_left })}</span>}
      </div>
      {data.required && !data.enabled && <div className="nota" role="status">{t('mfa.required_note')}</div>}

      {mode === 'setup' && <MfaSetup onDone={() => { open('idle'); onToast(t('mfa.enabled')); void load() }} doneLabel={t('mfa.done')} />}

      {newCodes && <RecoveryCodes codes={newCodes} onDone={() => open('idle')} doneLabel={t('mfa.done')} />}

      {(mode === 'codes' || mode === 'disable') && !newCodes && (
        <form onSubmit={withPassword} noValidate>
          <p className="sub2">{mode === 'codes' ? t('mfa.codes_regen_help') : t('mfa.disable_help')}</p>
          <label className="form-label" htmlFor="mfa-pw">{t('mfa.password')}</label>
          <input id="mfa-pw" type="password" autoComplete="current-password" className={`form-control mb-2${error ? ' is-invalid' : ''}`} value={password} onChange={(e) => setPassword(e.target.value)} required aria-invalid={error ? true : undefined} aria-describedby={error ? 'mfa-pw-error' : undefined} />
          {error && <div id="mfa-pw-error" className="invalid-feedback mb-2" role="alert">{error}</div>}
          <div className="fila-acciones">
            <button type="submit" className={`btn btn-sm ${mode === 'disable' ? 'btn-pel-sec' : 'btn-primary'}`} disabled={busy || password === ''}>{busy && <span className="spin" aria-hidden="true" />}{mode === 'codes' ? t('mfa.codes_regen') : t('mfa.disable')}</button>
            <button type="button" className="btn btn-ter btn-sm" onClick={() => open('idle')}>{t('common.cancel')}</button>
          </div>
        </form>
      )}

      {mode === 'idle' && (
        <>
          {!data.enabled && <p className="sub2">{t('mfa.setup_intro')}</p>}
          <div className="fila-acciones">
            {!data.enabled && <button type="button" className="btn btn-primary btn-sm" onClick={() => open('setup')}>{t('mfa.activate')}</button>}
            {data.enabled && <button type="button" className="btn btn-sec btn-sm" onClick={() => open('codes')}>{t('mfa.codes_regen')}</button>}
            {data.enabled && !data.required && <button type="button" className="btn btn-ter btn-sm" onClick={() => open('disable')}>{t('mfa.disable')}</button>}
          </div>
        </>
      )}
    </section>
  )
}
