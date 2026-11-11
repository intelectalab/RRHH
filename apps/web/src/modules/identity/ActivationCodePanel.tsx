'use client'

import { useState, type FormEvent } from 'react'
import { api } from '@/lib/api'
import { formErrors } from '@/lib/errors'
import { formatDateTime } from '@/lib/format'
import { useT } from '@/lib/useT'
import { SidePanel } from '@/ui/components/SidePanel'

type Issued = { code: string | null; channel: string; expires_at: string }

/** SEG-2 · RRHH emite el código de activación de quien no tiene correo: se muestra una vez o se envía. */
export function ActivationCodePanel({ user, onClose, onSent }: { user: { id: string; display_name: string; phone_masked?: string | null }; onClose: () => void; onSent: (msg: string) => void }) {
  const { t, locale } = useT()
  const [channel, setChannel] = useState<'in_app' | 'sms' | 'whatsapp'>('in_app')
  const [issued, setIssued] = useState<Issued | null>(null)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  async function submit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault()
    setBusy(true)
    setError(null)
    try {
      const res = (await api.post<{ data: Issued }>(`/users/${user.id}/activation-code`, { channel })).data
      if (res.code === null) {
        onSent(t('users.code_sent'))
        return
      }
      setIssued(res)
    } catch (err) {
      setError(formErrors(err, t).general)
    } finally {
      setBusy(false)
    }
  }

  return (
    <SidePanel
      title={t('users.code_title', { name: user.display_name })}
      onClose={onClose}
      closeLabel={t('common.close')}
      footer={issued
        ? <button type="button" className="btn btn-primary" onClick={onClose}>{t('mfa.done')}</button>
        : <>
          <button type="button" className="btn btn-sec" onClick={onClose}>{t('users.cancel')}</button>
          <button type="submit" form="codigo-activacion" className="btn btn-primary" disabled={busy}>{busy && <span className="spin" aria-hidden="true" />}{t('users.code_issue')}</button>
        </>}
    >
      {issued ? (
        <div>
          <div className="nota" role="status">{t('users.code_shown')}</div>
          <p className="codigo-grande" aria-label={t('auth.activation_code')}>{issued.code?.replace(/(\d{4})(\d{4})/, '$1 $2')}</p>
          <p className="sub2">{t('users.code_expires', { date: formatDateTime(locale, issued.expires_at) })}</p>
        </div>
      ) : (
        <form id="codigo-activacion" onSubmit={submit} noValidate>
          <p className="text-secondary mb-3">{t('users.code_text')}</p>
          <fieldset style={{ border: 0, padding: 0, margin: 0 }}>
            {(['in_app', 'sms', 'whatsapp'] as const).map((c) => (
              <label key={c} className="check mb-2">
                <input type="radio" name="canal" checked={channel === c} disabled={c !== 'in_app' && !user.phone_masked} onChange={() => setChannel(c)} />
                <span>{t(`users.code_${c}`)}{c !== 'in_app' && user.phone_masked ? ` · ${user.phone_masked}` : ''}</span>
              </label>
            ))}
          </fieldset>
          {error && <div className="invalid-feedback" role="alert">{error}</div>}
        </form>
      )}
    </SidePanel>
  )
}
