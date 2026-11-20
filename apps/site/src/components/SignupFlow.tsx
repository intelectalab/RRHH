'use client'

import Link from 'next/link'
import { useState, type FormEvent } from 'react'
import type { Content, Locale } from '@/lib/i18n'
import { fill, href } from '@/lib/i18n'
import { EMPLOYEE_RANGES, LABOR_REGIMES, captchaToken, newKey, origin, publicCall, type ApiError } from '@/lib/publicApi'

type Signup = { id: string; step: string; email: string; email_verified: boolean; token?: string; tenant: { slug: string; url: string } | null }
type Choice = { plan: string | null; planName: string | null; cycle: 'monthly' | 'annual'; addons: { code: string; name: string }[]; waitlist: string[] }
type Stage = 'account' | 'verify' | 'company' | 'plan' | 'done'

/**
 * WEB-5 · Registro de prueba en tres pasos: cuenta (con código al correo), empresa (RUC y dirección comprobados en la
 * API) y plan elegido en /precios. No pide contraseña ni pago: al terminar la plataforma crea la organización en
 * prueba y envía al administrador la invitación para crear su contraseña.
 */
export function SignupFlow({ lang, t, choice }: { lang: Locale; t: Content; choice: Choice }) {
  const s = t.signup
  const [stage, setStage] = useState<Stage>('account')
  const [token, setToken] = useState('')
  const [email, setEmail] = useState('')
  const [tenant, setTenant] = useState<Signup['tenant']>(null)
  const [error, setError] = useState<ApiError | null>(null)
  const [busy, setBusy] = useState(false)
  const [notice, setNotice] = useState('')
  const [ruc, setRuc] = useState<{ value: string; state: 'ok' | 'bad' | 'taken' | null }>({ value: '', state: null })
  const [sub, setSub] = useState<{ value: string; ok: boolean | null }>({ value: '', ok: null })
  const [keys] = useState(() => ({ start: newKey(), complete: newKey() }))

  async function run<T>(fn: () => Promise<{ ok: true; data: T } | { ok: false; error: ApiError }>, then: (data: T) => void) {
    setBusy(true)
    setError(null)
    setNotice('')
    const res = await fn()
    setBusy(false)
    if (res.ok) then(res.data)
    else setError(res.error)
  }

  function message(err: ApiError | null): string | null {
    if (!err) return null
    if (err.code === 'upstream_unavailable') return t.forms.unavailable
    return Object.values(err.fields)[0] || t.forms.error
  }

  function onAccount(e: FormEvent<HTMLFormElement>) {
    e.preventDefault()
    const f = new FormData(e.currentTarget)
    const mail = String(f.get('email') ?? '')
    void run(
      () => publicCall<Signup>('POST', 'signups', {
        full_name: f.get('full_name'), email: mail, consent: f.get('consent') === 'on', locale: lang,
        website: f.get('website') || null, captcha_token: captchaToken(), ...origin(),
      }, keys.start),
      (data) => { setToken(data.token ?? ''); setEmail(data.email || mail); setStage(data.email_verified ? 'company' : 'verify') },
    )
  }

  function onVerify(e: FormEvent<HTMLFormElement>) {
    e.preventDefault()
    const code = String(new FormData(e.currentTarget).get('code') ?? '').trim()
    void run(() => publicCall<Signup>('PATCH', `signups/${token}`, { verification_code: code }), () => setStage('company'))
  }

  function onResend() {
    void run(() => publicCall<Signup>('PATCH', `signups/${token}`, { resend_code: true }), () => setNotice(s.resent))
  }

  async function checkRuc(value: string) {
    setRuc({ value, state: null })
    if (!/^\d{11}$/.test(value)) return
    const res = await publicCall<{ valid: boolean; registered: boolean }>('GET', `ruc/${value}`)
    if (res.ok) setRuc({ value, state: !res.data.valid ? 'bad' : res.data.registered ? 'taken' : 'ok' })
  }

  async function checkSub(value: string) {
    const slug = value.toLowerCase()
    setSub({ value: slug, ok: null })
    if (!/^[a-z0-9-]{3,40}$/.test(slug)) return
    const res = await publicCall<{ available: boolean }>('GET', `subdomains/${slug}`)
    if (res.ok) setSub({ value: slug, ok: res.data.available })
  }

  function onCompany(e: FormEvent<HTMLFormElement>) {
    e.preventDefault()
    const f = new FormData(e.currentTarget)
    void run(
      () => publicCall<Signup>('PATCH', `signups/${token}`, {
        company: {
          ruc: f.get('ruc'), company_name: f.get('company_name'), employees_range: f.get('employees_range'),
          labor_regime: f.get('labor_regime'), subdomain: String(f.get('subdomain') ?? '').toLowerCase(),
        },
      }),
      () => setStage('plan'),
    )
  }

  function onPlan(e: FormEvent<HTMLFormElement>) {
    e.preventDefault()
    if (!choice.plan) return
    void run(
      async () => {
        const saved = await publicCall<Signup>('PATCH', `signups/${token}`, { plan: choice.plan, addons: choice.addons.map((a) => a.code), billing_cycle: choice.cycle })
        if (!saved.ok) return saved
        return publicCall<Signup>('POST', `signups/${token}/complete`, { mode: 'trial' }, keys.complete)
      },
      (data) => {
        setTenant(data.tenant)
        setStage('done')
        // Lo que se marcó «Próximamente» en precios queda en la lista de espera (RN-WEB-08).
        for (const code of choice.waitlist) {
          void publicCall('POST', 'leads', { kind: 'waitlist', email, module_code: code, consent: true, consent_purpose: t.waitlist.purpose, locale: lang, captcha_token: captchaToken(), ...origin() }, `${keys.complete}-${code}`)
        }
      },
    )
  }

  const stepIndex = stage === 'account' || stage === 'verify' ? 0 : stage === 'company' ? 1 : 2
  const err = message(error)

  return (
    <div className="box signup">
      {stage !== 'done' && (
        <ol className="steps" aria-label={s.title}>
          {s.steps.map((label, i) => <li key={label} aria-current={i === stepIndex ? 'step' : undefined} className={i < stepIndex ? 'done' : undefined}>{label}</li>)}
        </ol>
      )}

      {stage === 'account' && (
        <form className="form" onSubmit={onAccount}>
          <label className="f">{s.full_name}<input className="in" name="full_name" required minLength={2} maxLength={200} autoComplete="name" /></label>
          <label className="f">{s.email}<input className="in" name="email" type="email" required maxLength={254} autoComplete="email" /></label>
          <input className="hp" name="website" tabIndex={-1} autoComplete="off" aria-hidden="true" />
          <label className="chk"><input type="checkbox" name="consent" required /> <span>{s.consent}</span></label>
          <button className="btn btn-primary block" disabled={busy}>{busy ? t.forms.sending : s.start}</button>
        </form>
      )}

      {stage === 'verify' && (
        <form className="form" onSubmit={onVerify}>
          <h2 className="h3">{s.code_title}</h2>
          <p>{fill(s.code_text, { email })}</p>
          <label className="f">{s.code}<input className="in" name="code" required inputMode="numeric" pattern="\d{6}" maxLength={6} autoComplete="one-time-code" /></label>
          <button className="btn btn-primary block" disabled={busy}>{s.verify}</button>
          <button type="button" className="btn btn-line" onClick={onResend} disabled={busy}>{s.resend}</button>
        </form>
      )}

      {stage === 'company' && (
        <form className="form" onSubmit={onCompany}>
          <label className="f">{s.ruc}
            <input className="in" name="ruc" required inputMode="numeric" pattern="\d{11}" maxLength={11} onBlur={(e) => void checkRuc(e.target.value)} />
            {ruc.state === 'ok' && <span className="hint-ok">{s.ruc_ok}</span>}
            {ruc.state === 'bad' && <span className="hint-bad">{s.ruc_bad}</span>}
            {ruc.state === 'taken' && <span className="hint-bad">{s.ruc_taken}</span>}
          </label>
          <label className="f">{s.company_name}<input className="in" name="company_name" required minLength={2} maxLength={200} autoComplete="organization" /></label>
          <div className="row2">
            <label className="f">{s.employees}
              <select className="in" name="employees_range" required defaultValue="">
                <option value="" disabled>{t.forms.select}</option>
                {EMPLOYEE_RANGES.map((r) => <option key={r} value={r}>{r}</option>)}
              </select>
            </label>
            <label className="f">{s.regime}
              <select className="in" name="labor_regime" required defaultValue="pe_general">
                {LABOR_REGIMES.map((r) => <option key={r} value={r}>{s.regimes[r]}</option>)}
              </select>
            </label>
          </div>
          <label className="f">{s.subdomain}<small>{s.subdomain_hint}</small>
            <input className="in" name="subdomain" required pattern="[a-z0-9-]{3,40}" maxLength={40} onBlur={(e) => void checkSub(e.target.value)} />
            {sub.ok === true && <span className="hint-ok">{s.subdomain_ok}</span>}
            {sub.ok === false && <span className="hint-bad">{s.subdomain_bad}</span>}
          </label>
          <button className="btn btn-primary block" disabled={busy || ruc.state === 'bad' || ruc.state === 'taken' || sub.ok === false}>{s.next}</button>
        </form>
      )}

      {stage === 'plan' && (
        <form className="form" onSubmit={onPlan}>
          {choice.plan ? (
            <>
              <ul className="pts">
                <li><b>{s.plan}</b><span>{choice.planName ?? choice.plan}</span></li>
                <li><b>{s.cycle}</b><span>{s.cycles[choice.cycle]}</span></li>
                {choice.addons.length > 0 && <li><b>{s.addons}</b><span>{choice.addons.map((a) => a.name).join(' · ')}</span></li>}
              </ul>
              <p className="note">{s.trial_note}</p>
              <button className="btn btn-primary block" disabled={busy}>{s.create}</button>
            </>
          ) : (
            <>
              <p>{s.no_plan}</p>
              <Link className="btn btn-line" href={`${href(lang, '/precios')}#planes`}>{s.choose_plan}</Link>
            </>
          )}
        </form>
      )}

      {stage === 'done' && (
        <div className="form">
          <h2 className="h3">{s.done_title}</h2>
          <p>{fill(s.done_text, { email })}</p>
          {tenant && <a className="btn btn-primary" href={tenant.url}>{s.open}</a>}
        </div>
      )}

      {notice && <p className="hint-ok" role="status">{notice}</p>}
      {err && <p className="hint-bad" role="alert">{err}</p>}
    </div>
  )
}
