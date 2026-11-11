'use client'

import Link from 'next/link'
import { useSearchParams } from 'next/navigation'
import { useCallback, useEffect, useMemo, useState, type FormEvent } from 'react'
import { api, ApiRequestError } from '@/lib/api'
import { formErrors } from '@/lib/errors'
import { formatDate } from '@/lib/format'
import { useT } from '@/lib/useT'
import { useSession } from '@/modules/session/SessionProvider'
import { Icon } from '@/ui/components/Icon'
import { PageHeader } from '@/ui/components/PageHeader'
import { ErrorState } from '@/ui/components/States'
import { useToast } from '@/ui/components/Toast'

type HolidayRow = { id: string; date: string; name: string; kind: string; locked: boolean; removable: boolean; to_validate: boolean }
type Calendar = { id: string; code: string; name: string; country: string; is_default: boolean; holidays: HolidayRow[] }

const KINDS = ['national', 'regional', 'company', 'non_working_compensable'] as const
const TONE: Record<string, string> = { national: 'pe', regional: 'in', company: 'oc', non_working_compensable: 'gr' }

/** Configuración → Feriados (CFG-8): calendario nacional precargado y calendarios propios. */
export function HolidaysScreen() {
  const { t, locale } = useT()
  const { can } = useSession()
  const { toast, node: toastNode } = useToast()
  // CFG-8: el calendario central enlaza aquí con ?calendario=…&anio=….
  const params = useSearchParams()
  const [calendars, setCalendars] = useState<Calendar[] | null>(null)
  const [selected, setSelected] = useState<string | null>(params.get('calendario'))
  const [year, setYear] = useState(() => {
    const y = Number(params.get('anio'))
    return Number.isInteger(y) && y >= 2000 && y <= 2100 ? y : new Date().getFullYear()
  })
  const [loadError, setLoadError] = useState(false)
  const [adding, setAdding] = useState(false)
  const [creating, setCreating] = useState(false)

  const load = useCallback(async () => {
    try {
      const res = await api.get<{ data: Calendar[] }>('/holiday-calendars')
      setCalendars(res.data)
      setSelected((s) => (s && res.data.some((c) => c.id === s) ? s : res.data[0]?.id ?? null))
      setLoadError(false)
    } catch {
      setLoadError(true)
    }
  }, [])
  useEffect(() => { void load() }, [load])

  const calendar = calendars?.find((c) => c.id === selected) ?? null
  const years = useMemo(() => [...new Set((calendar?.holidays ?? []).map((h) => Number(h.date.slice(0, 4))).concat(year))].sort(), [calendar, year])
  const rows = calendar?.holidays.filter((h) => h.date.startsWith(String(year))) ?? []

  async function patch(body: Record<string, unknown>, msg: string) {
    if (!calendar) return
    try {
      await api.request(`/holiday-calendars/${calendar.id}`, 'PATCH', body)
      toast(t(msg))
      await load()
    } catch (e) {
      toast(e instanceof ApiRequestError ? e.message : t('auth.generic_error'), 'error')
    }
  }

  return (
    <>
      <PageHeader
        title={t('hol.title')}
        sub={t('hol.sub')}
        actions={<>
          <Link className="btn btn-sec" href="/configuracion">{t('cfg.back')}</Link>
          {can('time.holiday_calendars.create') && <button type="button" className="btn btn-sec" onClick={() => setCreating(true)}><Icon name="plus" /> {t('hol.new_calendar')}</button>}
        </>}
      />
      <main className="main" id="contenido" tabIndex={-1}>
        {loadError && <ErrorState text={t('common.load_error')} retryLabel={t('common.retry')} onRetry={() => void load()} />}
        {creating && <NewCalendar onCancel={() => setCreating(false)} onDone={async (id) => { setCreating(false); toast(t('hol.created')); await load(); setSelected(id) }} />}
        {calendars && (
          <div className="barra-filtros">
            <label className="form-label" htmlFor="h-cal" style={{ margin: 'auto 0' }}>{t('hol.calendar')}</label>
            <select id="h-cal" className="form-select" style={{ width: 'auto' }} value={selected ?? ''} onChange={(e) => setSelected(e.target.value)}>
              {calendars.map((c) => <option key={c.id} value={c.id}>{c.name}{c.is_default ? ` · ${t('hol.default')}` : ''}</option>)}
            </select>
            <div className="seg" role="group" aria-label={t('hol.year')}>
              {years.map((y) => <button key={y} type="button" className={y === year ? 'on' : undefined} aria-pressed={y === year} onClick={() => setYear(y)}>{y}</button>)}
            </div>
            {calendar && !calendar.is_default && can('time.holiday_calendars.update') && <button type="button" className="btn btn-ter btn-sm" onClick={() => void patch({ is_default: true }, 'hol.saved')}>{t('hol.make_default')}</button>}
          </div>
        )}
        {rows.some((r) => r.to_validate) && <div className="nota">{t('hol.to_validate_help')}</div>}
        {calendar && (
          <section className="card">
            <div className="card-header">
              <h2>{calendar.name} · {year}</h2>
              {can('time.holiday_calendars.update') && !adding && <button type="button" className="btn btn-primary btn-sm" onClick={() => setAdding(true)}><Icon name="plus" /> {t('hol.add')}</button>}
            </div>
            {adding && <AddHoliday year={year} onCancel={() => setAdding(false)} onSubmit={async (h) => { await patch({ add: [h] }, 'hol.added'); setAdding(false) }} />}
            <div className="tabla-wrap">
              <table className="table">
                <thead><tr><th>{t('hol.col_date')}</th><th>{t('hol.col_name')}</th><th>{t('hol.col_kind')}</th><th /></tr></thead>
                <tbody>
                  {rows.length === 0 && <tr><td colSpan={4} className="sub2">{t('hol.empty', { year })}</td></tr>}
                  {rows.map((h) => (
                    <tr key={h.id} className={h.locked ? 'pasado' : undefined}>
                      <td className="nowrap">{formatDate(locale, h.date)}</td>
                      <td>{h.name}{h.to_validate && <span className="tag wa" style={{ marginLeft: 6 }}>{t('hol.to_validate')}</span>}</td>
                      <td><span className={`tag ${TONE[h.kind] ?? 'gr'}`}>{t(`hol.kind_${h.kind}`)}</span></td>
                      <td className="text-end">
                        {h.locked ? <span className="sub2">{t('hol.past')}</span> : h.removable && can('time.holiday_calendars.update') && (
                          <button type="button" className="btn btn-ter btn-sm" onClick={() => { if (window.confirm(t('hol.confirm_remove', { name: h.name, date: formatDate(locale, h.date) }))) void patch({ remove: [h.id] }, 'hol.removed') }}>{t('hol.remove')}</button>
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </section>
        )}
      </main>
      {toastNode}
    </>
  )
}

function AddHoliday({ year, onCancel, onSubmit }: { year: number; onCancel: () => void; onSubmit: (h: { date: string; name: string; kind: string }) => Promise<void> }) {
  const { t } = useT()
  const [form, setForm] = useState({ date: `${year}-01-01`, name: '', kind: 'company' })
  const submit = (e: FormEvent<HTMLFormElement>) => { e.preventDefault(); void onSubmit(form) }
  return (
    <form className="param-form" style={{ margin: 16 }} onSubmit={submit}>
      <div className="grid-param" style={{ gridTemplateColumns: '170px 1fr 220px' }}>
        <div><label className="form-label" htmlFor="nh-date">{t('hol.col_date')}</label><input id="nh-date" type="date" className="form-control" value={form.date} onChange={(e) => setForm({ ...form, date: e.target.value })} required /></div>
        <div><label className="form-label" htmlFor="nh-name">{t('hol.col_name')}</label><input id="nh-name" className="form-control" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} required maxLength={120} /></div>
        <div><label className="form-label" htmlFor="nh-kind">{t('hol.col_kind')}</label>
          <select id="nh-kind" className="form-select" value={form.kind} onChange={(e) => setForm({ ...form, kind: e.target.value })}>{KINDS.map((k) => <option key={k} value={k}>{t(`hol.kind_${k}`)}</option>)}</select></div>
      </div>
      <div className="param-acciones">
        <button type="button" className="btn btn-sec btn-sm" onClick={onCancel}>{t('hol.cancel')}</button>
        <button type="submit" className="btn btn-primary btn-sm" disabled={form.name.trim() === ''}>{t('hol.save')}</button>
      </div>
    </form>
  )
}

function NewCalendar({ onCancel, onDone }: { onCancel: () => void; onDone: (id: string) => Promise<void> }) {
  const { t } = useT()
  const [name, setName] = useState('')
  const [error, setError] = useState<string | null>(null)
  async function submit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault()
    try {
      const res = await api.post<{ data: { id: string } }>('/holiday-calendars', { name, country: 'PE' })
      await onDone(res.data.id)
    } catch (err) {
      const { fields, general } = formErrors(err, t)
      setError(fields.name ?? general)
    }
  }
  return (
    <form className="param-form" style={{ marginBottom: 16 }} onSubmit={submit}>
      <label className="form-label" htmlFor="nc-name">{t('hol.calendar_name')}</label>
      <input id="nc-name" className="form-control" value={name} onChange={(e) => setName(e.target.value)} required maxLength={120} />
      {error && <div className="invalid-feedback" role="alert">{error}</div>}
      <div className="param-acciones">
        <button type="button" className="btn btn-sec btn-sm" onClick={onCancel}>{t('hol.cancel')}</button>
        <button type="submit" className="btn btn-primary btn-sm" disabled={name.trim() === ''}>{t('hol.save')}</button>
      </div>
    </form>
  )
}
