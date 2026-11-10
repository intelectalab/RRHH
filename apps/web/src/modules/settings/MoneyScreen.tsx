'use client'

import Link from 'next/link'
import { useCallback, useEffect, useState, type FormEvent } from 'react'
import { api } from '@/lib/api'
import { formErrors } from '@/lib/errors'
import { formatDate, localToday } from '@/lib/format'
import { useT } from '@/lib/useT'
import { useSession } from '@/modules/session/SessionProvider'
import { Icon } from '@/ui/components/Icon'
import { PageHeader } from '@/ui/components/PageHeader'
import { EmptyState, ErrorState } from '@/ui/components/States'
import { useToast } from '@/ui/components/Toast'

type Rate = { id: string; from_currency: string; to_currency: string; rate: string; valid_on: string; source: string }
type Conversion = { converted: { amount: string; currency: string }; rate: string; rate_date: string; inverse: boolean }
const CURRENCIES = ['PEN', 'USD', 'EUR']

/** Configuración → Monedas y tipos de cambio (CFG-7). */
export function MoneyScreen() {
  const { t, locale } = useT()
  const { can } = useSession()
  const { toast, node: toastNode } = useToast()
  const [rows, setRows] = useState<Rate[] | null>(null)
  const [loadError, setLoadError] = useState(false)
  const [adding, setAdding] = useState(false)

  const load = useCallback(async () => {
    try {
      setRows((await api.get<{ data: Rate[] }>('/exchange-rates?per_page=100')).data)
      setLoadError(false)
    } catch {
      setLoadError(true)
    }
  }, [])
  useEffect(() => { void load() }, [load])

  return (
    <>
      <PageHeader title={t('fx.title')} sub={t('fx.sub')} actions={<>
        <Link className="btn btn-sec" href="/configuracion">{t('cfg.back')}</Link>
        {can('settings.exchange_rates.create') && !adding && <button type="button" className="btn btn-primary" onClick={() => setAdding(true)}><Icon name="plus" /> {t('fx.new')}</button>}
      </>} />
      <main className="main" id="contenido" tabIndex={-1}>
        <div className="nota">{t('fx.help')}</div>
        <div className="grid-2">
          <section className="card">
            {adding && <NewRate onCancel={() => setAdding(false)} onDone={async () => { setAdding(false); toast(t('fx.created')); await load() }} />}
            <div className="tabla-wrap">
              <table className="table">
                <thead><tr><th>{t('fx.from')} → {t('fx.to')}</th><th className="text-end">{t('fx.rate')}</th><th>{t('fx.valid_on')}</th><th>{t('fx.source')}</th></tr></thead>
                <tbody>
                  {loadError && <tr><td colSpan={4}><ErrorState text={t('common.load_error')} retryLabel={t('common.retry')} onRetry={() => void load()} /></td></tr>}
                  {!loadError && rows === null && <tr><td colSpan={4}><span className="esq" style={{ width: '40%' }} /></td></tr>}
                  {rows?.length === 0 && <tr><td colSpan={4}><EmptyState title={t('fx.empty_title')} text={t('fx.empty_text')} /></td></tr>}
                  {rows?.map((r) => (
                    <tr key={r.id}><td><b>{r.from_currency} → {r.to_currency}</b></td><td className="text-end" style={{ fontVariantNumeric: 'tabular-nums' }}>{r.rate}</td><td className="nowrap">{formatDate(locale, r.valid_on)}</td><td>{r.source}</td></tr>
                  ))}
                </tbody>
              </table>
            </div>
          </section>
          <Converter />
        </div>
      </main>
      {toastNode}
    </>
  )
}

function NewRate({ onCancel, onDone }: { onCancel: () => void; onDone: () => Promise<void> }) {
  const { t } = useT()
  const [form, setForm] = useState({ from_currency: 'USD', to_currency: 'PEN', rate: '', valid_on: localToday(), source: '' })
  const [errors, setErrors] = useState<Record<string, string>>({})
  const set = (k: keyof typeof form) => (e: { target: { value: string } }) => setForm((f) => ({ ...f, [k]: e.target.value }))
  async function submit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault()
    try {
      await api.post('/exchange-rates', form)
      await onDone()
    } catch (err) {
      const r = formErrors(err, t)
      setErrors(r.general ? { ...r.fields, rate: r.general } : r.fields)
    }
  }
  const field = (k: keyof typeof form, label: string, input: React.ReactNode) => <div><label className="form-label" htmlFor={`fx-${k}`}>{label}</label>{input}{errors[k] && <div className="invalid-feedback" role="alert">{errors[k]}</div>}</div>
  return (
    <form className="param-form" style={{ margin: 16 }} onSubmit={submit} noValidate>
      <div className="grid-param" style={{ gridTemplateColumns: '1fr 1fr 1fr' }}>
        {field('from_currency', t('fx.from'), <select id="fx-from_currency" className="form-select" value={form.from_currency} onChange={set('from_currency')}>{CURRENCIES.map((c) => <option key={c}>{c}</option>)}</select>)}
        {field('to_currency', t('fx.to'), <select id="fx-to_currency" className="form-select" value={form.to_currency} onChange={set('to_currency')}>{CURRENCIES.map((c) => <option key={c}>{c}</option>)}</select>)}
        {field('rate', t('fx.rate'), <input id="fx-rate" inputMode="decimal" className={`form-control${errors.rate ? ' is-invalid' : ''}`} value={form.rate} onChange={set('rate')} />)}
        {field('valid_on', t('fx.valid_on'), <input id="fx-valid_on" type="date" className="form-control" value={form.valid_on} onChange={set('valid_on')} />)}
        <div style={{ gridColumn: 'span 2' }}>{field('source', t('fx.source'), <input id="fx-source" className={`form-control${errors.source ? ' is-invalid' : ''}`} placeholder={t('fx.source_placeholder')} value={form.source} onChange={set('source')} />)}</div>
      </div>
      <div className="param-acciones">
        <button type="button" className="btn btn-sec btn-sm" onClick={onCancel}>{t('fx.cancel')}</button>
        <button type="submit" className="btn btn-primary btn-sm">{t('fx.save')}</button>
      </div>
    </form>
  )
}

function Converter() {
  const { t, locale } = useT()
  const [form, setForm] = useState({ amount: '1000.00', from: 'USD', to: 'PEN', date: localToday() })
  const [result, setResult] = useState<Conversion | null>(null)
  const [error, setError] = useState<string | null>(null)
  async function submit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault()
    setError(null)
    try {
      setResult((await api.get<{ data: Conversion }>(`/exchange-rates/convert?${new URLSearchParams(form).toString()}`)).data)
    } catch (err) {
      setResult(null)
      setError(formErrors(err, t).general ?? Object.values(formErrors(err, t).fields)[0] ?? null)
    }
  }
  const money = (m: { amount: string; currency: string }) => new Intl.NumberFormat(locale, { style: 'currency', currency: m.currency }).format(Number(m.amount))
  return (
    <section className="bloque-plano">
      <div className="cab"><h2>{t('fx.convert_title')}</h2></div>
      <form onSubmit={submit}>
        <div className="mb-2"><label className="form-label" htmlFor="cv-amount">{t('fx.amount')}</label><input id="cv-amount" inputMode="decimal" className="form-control" value={form.amount} onChange={(e) => setForm({ ...form, amount: e.target.value })} /></div>
        <div className="grid-param" style={{ gridTemplateColumns: '1fr 1fr' }}>
          <div><label className="form-label" htmlFor="cv-from">{t('fx.from')}</label><select id="cv-from" className="form-select" value={form.from} onChange={(e) => setForm({ ...form, from: e.target.value })}>{CURRENCIES.map((c) => <option key={c}>{c}</option>)}</select></div>
          <div><label className="form-label" htmlFor="cv-to">{t('fx.to')}</label><select id="cv-to" className="form-select" value={form.to} onChange={(e) => setForm({ ...form, to: e.target.value })}>{CURRENCIES.map((c) => <option key={c}>{c}</option>)}</select></div>
        </div>
        <div className="mb-2"><label className="form-label" htmlFor="cv-date">{t('fx.date')}</label><input id="cv-date" type="date" className="form-control" value={form.date} onChange={(e) => setForm({ ...form, date: e.target.value })} /></div>
        <button type="submit" className="btn btn-sec btn-sm">{t('fx.convert')}</button>
      </form>
      {result && <p className="mt-2"><b>{money(result.converted)}</b><span className="sub2">{t('fx.result', { amount: money(result.converted), rate: result.rate, date: formatDate(locale, result.rate_date) })} {result.inverse ? t('fx.inverse') : ''}</span></p>}
      {error && <div className="invalid-feedback" role="alert">{error}</div>}
    </section>
  )
}
