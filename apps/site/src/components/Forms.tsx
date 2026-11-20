'use client'

import Link from 'next/link'
import { useEffect, useState, type FormEvent } from 'react'
import type { Content, Locale } from '@/lib/i18n'
import { fill, href } from '@/lib/i18n'
import { EMPLOYEE_RANGES, captchaToken, newKey, origin, publicCall, type ApiError } from '@/lib/publicApi'

/** Zona en la que se muestran los horarios de demo (la misma que usa la agenda de la plataforma). */
const DEMO_TZ = process.env.NEXT_PUBLIC_DEMO_TIMEZONE ?? 'America/Lima'
type Slot = { starts_at: string; ends_at: string }
type Booking = { id: string; status: string; starts_at: string; ends_at: string; manage_token?: string }

function errorText(t: Content, err: ApiError | null): string | null {
  if (!err) return null
  if (err.code === 'upstream_unavailable') return t.forms.unavailable
  return Object.values(err.fields)[0] || t.forms.error
}

function when(lang: Locale, iso: string): string {
  return new Intl.DateTimeFormat(lang, { dateStyle: 'full', timeStyle: 'short', timeZone: DEMO_TZ }).format(new Date(iso))
}

function Honeypot() {
  return <input className="hp" name="website" tabIndex={-1} autoComplete="off" aria-hidden="true" />
}

/**
 * WEB-6 · WEB-7 · Formularios de contacto, partners y lista de espera («Avísame»). Cada uno guarda su página de origen,
 * campaña y el consentimiento con su finalidad (RN-WEB-09).
 */
export function LeadForm({ lang, t, kind, modules }: { lang: Locale; t: Content; kind: 'contact' | 'partner' | 'waitlist'; modules?: { code: string; name: string }[] }) {
  const [busy, setBusy] = useState(false)
  const [sent, setSent] = useState(false)
  const [error, setError] = useState<ApiError | null>(null)
  const [key] = useState(newKey)
  const text = kind === 'contact' ? t.contact : kind === 'partner' ? t.partners : t.waitlist

  async function submit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault()
    const f = new FormData(e.currentTarget)
    const get = (n: string) => { const v = f.get(n); return v === null || v === '' ? null : String(v) }
    setBusy(true)
    setError(null)
    const res = await publicCall('POST', 'leads', {
      kind, full_name: get('full_name'), email: get('email'), phone: get('phone'), company_name: get('company_name'), ruc: get('ruc'),
      employees_range: get('employees_range'), module_code: get('module_code'), message: get('message'), locale: lang,
      consent: f.get('consent') === 'on', consent_purpose: text.purpose, website: get('website'), captcha_token: captchaToken(), ...origin(),
    }, key)
    setBusy(false)
    if (res.ok) setSent(true)
    else setError(res.error)
  }

  if (sent) return <p className="hint-ok" role="status">{text.sent}</p>
  const err = errorText(t, error)
  return (
    <form className="form" onSubmit={submit}>
      {kind === 'waitlist' && modules && (
        <label className="f">{t.waitlist.module}
          <select className="in" name="module_code" required defaultValue={modules.length === 1 ? modules[0]?.code : ''}>
            <option value="" disabled>{t.forms.select}</option>
            {modules.map((m) => <option key={m.code} value={m.code}>{m.name}</option>)}
          </select>
        </label>
      )}
      <div className="row2">
        <label className="f">{t.forms.name}<input className="in" name="full_name" required={kind !== 'waitlist'} maxLength={200} autoComplete="name" /></label>
        <label className="f">{t.forms.email}<input className="in" name="email" type="email" required maxLength={254} autoComplete="email" /></label>
      </div>
      {kind !== 'waitlist' && (
        <>
          <div className="row2">
            <label className="f">{t.forms.company}<input className="in" name="company_name" required maxLength={200} autoComplete="organization" /></label>
            <label className="f">{t.forms.phone} <small>{t.forms.optional}</small><input className="in" name="phone" type="tel" maxLength={30} autoComplete="tel" /></label>
          </div>
          <div className="row2">
            <label className="f">{t.forms.ruc} <small>{t.forms.optional}</small><input className="in" name="ruc" inputMode="numeric" pattern="\d{11}" maxLength={11} /></label>
            <label className="f">{t.forms.employees}
              <select className="in" name="employees_range" defaultValue="">
                <option value="">{t.forms.select}</option>
                {EMPLOYEE_RANGES.map((r) => <option key={r} value={r}>{r}</option>)}
              </select>
            </label>
          </div>
          <label className="f">{t.forms.message}<textarea className="in" name="message" maxLength={2000} required={kind === 'contact'} /></label>
        </>
      )}
      <Honeypot />
      <label className="chk"><input type="checkbox" name="consent" required /> <span>{t.forms.consent}</span></label>
      <button className="btn btn-primary" disabled={busy}>{busy ? t.forms.sending : kind === 'waitlist' ? t.waitlist.cta : t.forms.send}</button>
      {err && <p className="hint-bad" role="alert">{err}</p>}
    </form>
  )
}

function useSlots(exceptToken?: string) {
  const [slots, setSlots] = useState<Slot[] | null>(null)
  const [failed, setFailed] = useState(false)
  async function load() {
    const res = await publicCall<Slot[]>('GET', 'demo-slots')
    if (res.ok) setSlots(res.data)
    else setFailed(true)
  }
  useEffect(() => { void load() }, [exceptToken])
  return { slots, failed, reload: load }
}

function SlotPicker({ lang, t, slots, name }: { lang: Locale; t: Content; slots: Slot[]; name: string }) {
  if (slots.length === 0) return <p className="note">{t.demo.no_slots}</p>
  return (
    <fieldset className="slots">
      <legend>{t.demo.slots}</legend>
      {slots.map((slot, i) => (
        <label key={slot.starts_at}><input type="radio" name={name} value={slot.starts_at} required defaultChecked={i === 0} /> {when(lang, slot.starts_at)}</label>
      ))}
    </fieldset>
  )
}

/** WEB-6 · Agenda de demo: horarios que ofrece la plataforma, datos de contacto y confirmación por correo. */
export function DemoBooking({ lang, t, productCode }: { lang: Locale; t: Content; productCode?: string }) {
  const { slots, failed, reload } = useSlots()
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<ApiError | null>(null)
  const [booked, setBooked] = useState<{ email: string; booking: Booking } | null>(null)
  const [key, setKey] = useState(newKey)

  async function submit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault()
    const f = new FormData(e.currentTarget)
    const get = (n: string) => { const v = f.get(n); return v === null || v === '' ? null : String(v) }
    setBusy(true)
    setError(null)
    const res = await publicCall<Booking>('POST', 'demo-bookings', {
      starts_at: get('starts_at'), full_name: get('full_name'), email: get('email'), phone: get('phone'), company_name: get('company_name'),
      employees_range: get('employees_range'), channel: get('channel') ?? 'video', message: get('message'), product_code: productCode ?? null,
      locale: lang, consent: f.get('consent') === 'on', consent_purpose: t.demo.purpose, website: get('website'), captcha_token: captchaToken(), ...origin(),
    }, key)
    setBusy(false)
    if (res.ok) {
      setBooked({ email: String(get('email')), booking: res.data })
    } else {
      setError(res.error)
      if (res.status === 409) { setKey(newKey()); void reload() }
    }
  }

  if (booked) {
    return (
      <div className="box">
        <h2 className="h3">{t.demo.booked_title}</h2>
        <p><b>{when(lang, booked.booking.starts_at)}</b></p>
        <p>{fill(t.demo.booked_text, { email: booked.email })}</p>
      </div>
    )
  }
  const err = error?.code === 'slot_unavailable' || error?.rule === 'slot_unavailable' ? t.demo.taken : errorText(t, error)
  return (
    <form className="form box" onSubmit={submit}>
      {failed && <p className="hint-bad">{t.forms.unavailable}</p>}
      {slots && <SlotPicker lang={lang} t={t} slots={slots} name="starts_at" />}
      <div className="row2">
        <label className="f">{t.forms.name}<input className="in" name="full_name" required minLength={2} maxLength={200} autoComplete="name" /></label>
        <label className="f">{t.forms.email}<input className="in" name="email" type="email" required maxLength={254} autoComplete="email" /></label>
      </div>
      <div className="row2">
        <label className="f">{t.forms.company}<input className="in" name="company_name" maxLength={200} autoComplete="organization" /></label>
        <label className="f">{t.forms.phone} <small>{t.forms.optional}</small><input className="in" name="phone" type="tel" maxLength={30} autoComplete="tel" /></label>
      </div>
      <div className="row2">
        <label className="f">{t.forms.employees}
          <select className="in" name="employees_range" defaultValue="">
            <option value="">{t.forms.select}</option>
            {EMPLOYEE_RANGES.map((r) => <option key={r} value={r}>{r}</option>)}
          </select>
        </label>
        <label className="f">{t.demo.channel}
          <select className="in" name="channel" defaultValue="video">
            <option value="video">{t.demo.channels.video}</option>
            <option value="phone">{t.demo.channels.phone}</option>
          </select>
        </label>
      </div>
      <label className="f">{t.forms.message} <small>{t.forms.optional}</small><textarea className="in" name="message" maxLength={2000} /></label>
      <Honeypot />
      <label className="chk"><input type="checkbox" name="consent" required /> <span>{t.forms.consent}</span></label>
      <button className="btn btn-primary" disabled={busy || !slots || slots.length === 0}>{busy ? t.forms.sending : t.demo.book}</button>
      {err && <p className="hint-bad" role="alert">{err}</p>}
    </form>
  )
}

/** WEB-6 · Cambiar o cancelar la demo con el enlace del correo (sin cuenta). */
export function DemoManage({ lang, t, token }: { lang: Locale; t: Content; token: string }) {
  const [booking, setBooking] = useState<Booking | null>(null)
  const [missing, setMissing] = useState(false)
  const [notice, setNotice] = useState('')
  const [error, setError] = useState<ApiError | null>(null)
  const [busy, setBusy] = useState(false)
  const [changing, setChanging] = useState(false)
  const { slots } = useSlots(changing ? token : undefined)

  useEffect(() => {
    if (!token) { setMissing(true); return }
    void publicCall<Booking>('GET', `demo-bookings/${token}`).then((res) => (res.ok ? setBooking(res.data) : setMissing(true)))
  }, [token])

  async function act(body: { action: 'cancel' | 'reschedule'; starts_at?: string }) {
    setBusy(true)
    setError(null)
    const res = await publicCall<Booking>('POST', `demo-bookings/${token}/cancel`, { ...body, locale: lang })
    setBusy(false)
    if (res.ok) {
      setBooking(res.data)
      setChanging(false)
      setNotice(body.action === 'cancel' ? t.demo.cancelled : t.demo.rescheduled)
    } else {
      setError(res.error)
    }
  }

  if (missing) return <p className="hint-bad">{t.demo.not_found}</p>
  if (!booking) return null
  const open = booking.status === 'scheduled' || booking.status === 'rescheduled'
  const statusText = (t.demo.status as Record<string, string>)[booking.status] ?? booking.status
  const err = error?.code === 'booking_closed' || error?.rule === 'booking_closed' ? t.demo.closed : error?.code === 'slot_unavailable' ? t.demo.taken : errorText(t, error)
  return (
    <div className="box form">
      <ul className="pts">
        <li><b>{t.demo.when}</b><span>{when(lang, booking.starts_at)}</span></li>
        <li><b>{t.demo.manage_title}</b><span>{statusText}</span></li>
      </ul>
      {open && !changing && (
        <div className="ctas">
          <button className="btn btn-line" type="button" onClick={() => setChanging(true)} disabled={busy}>{t.demo.reschedule}</button>
          <button className="btn btn-line" type="button" onClick={() => void act({ action: 'cancel' })} disabled={busy}>{t.demo.cancel}</button>
        </div>
      )}
      {open && changing && slots && (
        <form className="form" onSubmit={(e) => { e.preventDefault(); void act({ action: 'reschedule', starts_at: String(new FormData(e.currentTarget).get('starts_at') ?? '') }) }}>
          <SlotPicker lang={lang} t={t} slots={slots} name="starts_at" />
          <button className="btn btn-primary" disabled={busy || slots.length === 0}>{t.demo.reschedule}</button>
        </form>
      )}
      {notice && <p className="hint-ok" role="status">{notice}</p>}
      {err && <p className="hint-bad" role="alert">{err}</p>}
    </div>
  )
}

/** WEB-7 · «Ingresar»: lleva a la pantalla de ingreso de la organización por su dirección. */
export function LoginForm({ lang, t, appUrl }: { lang: Locale; t: Content; appUrl: string }) {
  const [error, setError] = useState(false)
  const [busy, setBusy] = useState(false)

  async function submit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault()
    const slug = String(new FormData(e.currentTarget).get('subdomain') ?? '').trim().toLowerCase()
    setError(false)
    if (!/^[a-z0-9-]{3,40}$/.test(slug)) { setError(true); return }
    setBusy(true)
    const res = await publicCall<{ available: boolean; reason: string | null }>('GET', `subdomains/${slug}`)
    setBusy(false)
    // Una dirección «tomada» es una organización que existe.
    if (res.ok && !res.data.available && res.data.reason === 'taken') window.location.href = `${appUrl.replace('{slug}', slug)}/ingresar`
    else setError(true)
  }

  return (
    <form className="form box" onSubmit={submit}>
      <label className="f">{t.login.subdomain}<input className="in" name="subdomain" required maxLength={40} autoComplete="organization" /></label>
      <button className="btn btn-primary block" disabled={busy}>{t.login.go}</button>
      {error && <p className="hint-bad" role="alert">{t.login.not_found}</p>}
      <p className="note">{t.login.no_account} <Link href={`${href(lang, '/precios')}#planes`}>{t.login.trial}</Link></p>
    </form>
  )
}
