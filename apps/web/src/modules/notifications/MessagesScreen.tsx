'use client'

import { useCallback, useEffect, useMemo, useRef, useState, type FormEvent } from 'react'
import { api } from '@/lib/api'
import { formErrors } from '@/lib/errors'
import { formatDateTime } from '@/lib/format'
import { useT } from '@/lib/useT'
import { settingsTabs } from '@/modules/identity/settingsTabs'
import { useSession } from '@/modules/session/SessionProvider'
import { PageHeader } from '@/ui/components/PageHeader'
import { SidePanel } from '@/ui/components/SidePanel'
import { EmptyState, ErrorState } from '@/ui/components/States'
import { useToast } from '@/ui/components/Toast'
import { categoryLabel } from './shared'

type Template = { id: string; key: string; channel: string; locale: string; subject: string | null; body: string; variables: string[]; mandatory: boolean; customized: boolean; default_subject: string; default_body: string }
type Delivery = { id: string; channel: string; recipient: string | null; recipient_name: string | null; category: string | null; title: string | null; status: string; provider: string | null; attempts: number; error: string | null; sent_at: string | null; created_at: string | null }

const TONE: Record<string, string> = { sent: 'ok', delivered: 'ok', queued: 'wa', failed: 'er', bounced: 'er', suppressed: 'gr' }

/** Configuración → Mensajes (NOT-1): plantillas por tipo, canal e idioma, y registro de envíos. */
export function MessagesScreen() {
  const { t, locale } = useT()
  const { can } = useSession()
  const { toast, node: toastNode } = useToast()
  const [view, setView] = useState<'templates' | 'deliveries'>(can('notifications.templates.view') ? 'templates' : 'deliveries')
  const [channel, setChannel] = useState('')
  const [lang, setLang] = useState<string>(locale)
  const [rows, setRows] = useState<Template[] | null>(null)
  const [deliveries, setDeliveries] = useState<Delivery[] | null>(null)
  const [loadError, setLoadError] = useState(false)
  const [editing, setEditing] = useState<Template | null>(null)

  const load = useCallback(async () => {
    try {
      if (view === 'templates') {
        const q = new URLSearchParams()
        if (channel) q.set('filter[channel]', channel)
        if (lang) q.set('filter[locale]', lang)
        setRows((await api.get<{ data: Template[] }>(`/notification-templates?${q.toString()}`)).data)
      } else {
        setDeliveries((await api.get<{ data: Delivery[] }>('/notification-deliveries?per_page=50')).data)
      }
      setLoadError(false)
    } catch {
      setLoadError(true)
    }
  }, [view, channel, lang])
  useEffect(() => { void load() }, [load])

  const fake = deliveries?.some((d) => d.provider?.startsWith('fake')) ?? false

  return (
    <>
      <PageHeader title={t('msgs.title')} sub={t('msgs.sub')} tabs={settingsTabs(t, can, 'messages')} />
      <main className="main" id="contenido" tabIndex={-1}>
        <div className="barra-filtros">
          {can('notifications.templates.view') && can('notifications.deliveries.view') && (
            <div className="seg" role="group" aria-label={t('msgs.title')}>
              {(['templates', 'deliveries'] as const).map((v) => (
                <button key={v} type="button" className={view === v ? 'on' : undefined} aria-pressed={view === v} onClick={() => setView(v)}>{t(`msgs.view_${v}`)}</button>
              ))}
            </div>
          )}
          {view === 'templates' && (
            <>
              <select className="form-select" style={{ width: 'auto' }} aria-label={t('msgs.col_channel')} value={channel} onChange={(e) => setChannel(e.target.value)}>
                <option value="">{t('msgs.all_channels')}</option>
                {['in_app', 'email'].map((c) => <option key={c} value={c}>{t(`nchannel.${c}`)}</option>)}
              </select>
              <select className="form-select" style={{ width: 'auto' }} aria-label={t('msgs.col_locale')} value={lang} onChange={(e) => setLang(e.target.value)}>
                <option value="">{t('msgs.all_locales')}</option>
                {['es', 'en', 'pt'].map((l) => <option key={l} value={l}>{t(`msgs.locale_${l}`)}</option>)}
              </select>
            </>
          )}
        </div>
        {view === 'deliveries' && fake && <div className="nota">{t('msgs.d_fake')}</div>}
        <section className="card">
          <div className="tabla-wrap">
            {view === 'templates' ? (
              <table className="table">
                <thead><tr><th>{t('msgs.col_type')}</th><th>{t('msgs.col_channel')}</th><th>{t('msgs.col_locale')}</th><th>{t('msgs.col_subject')}</th><th /></tr></thead>
                <tbody>
                  {loadError && <tr><td colSpan={5}><ErrorState text={t('common.load_error')} retryLabel={t('common.retry')} onRetry={() => void load()} /></td></tr>}
                  {!loadError && rows === null && [1, 2, 3].map((i) => <tr key={i}><td colSpan={5}><span className="esq" style={{ width: `${25 + i * 15}%` }} /></td></tr>)}
                  {rows?.map((r) => (
                    <tr key={r.id}>
                      <td><b>{categoryLabel(t, r.key)}</b>{r.mandatory && <span className="tag pe" style={{ marginLeft: 6 }}>{t('msgs.mandatory')}</span>}</td>
                      <td>{t(`nchannel.${r.channel}`)}</td>
                      <td>{t(`msgs.locale_${r.locale}`)}</td>
                      <td>{r.subject}<span className="sub2">{r.customized ? t('msgs.customized') : t('msgs.default')}</span></td>
                      <td className="text-end">{can('notifications.templates.update') && <button type="button" className="btn btn-ter btn-sm" onClick={() => setEditing(r)}>{t('common.edit')}</button>}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            ) : (
              <table className="table">
                <thead><tr><th>{t('msgs.d_col_when')}</th><th>{t('msgs.d_col_to')}</th><th>{t('msgs.d_col_notice')}</th><th>{t('msgs.d_col_status')}</th></tr></thead>
                <tbody>
                  {loadError && <tr><td colSpan={4}><ErrorState text={t('common.load_error')} retryLabel={t('common.retry')} onRetry={() => void load()} /></td></tr>}
                  {!loadError && deliveries === null && [1, 2].map((i) => <tr key={i}><td colSpan={4}><span className="esq" style={{ width: `${30 + i * 15}%` }} /></td></tr>)}
                  {deliveries?.length === 0 && <tr><td colSpan={4}><EmptyState title={t('msgs.d_empty_title')} text={t('msgs.d_empty_text')} /></td></tr>}
                  {deliveries?.map((d) => (
                    <tr key={d.id}>
                      <td className="nowrap">{formatDateTime(locale, d.sent_at ?? d.created_at)}</td>
                      <td>{d.recipient_name ?? '—'}<span className="sub2">{t(`nchannel.${d.channel}`)} · {d.recipient}</span></td>
                      <td>{d.category ? categoryLabel(t, d.category) : '—'}{d.title && <span className="sub2">{d.title}</span>}</td>
                      <td>
                        <span className={`st ${TONE[d.status] ?? 'gr'}`}>{t(`msgs.d_status_${d.status}`)}</span>
                        {d.attempts > 1 && <span className="sub2">{t('msgs.d_attempts', { n: d.attempts })}</span>}
                        {d.error && <span className="sub2" style={{ color: 'var(--er)' }}>{d.error}</span>}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
          </div>
        </section>
      </main>
      {editing && <TemplatePanel template={editing} onClose={() => setEditing(null)} onSaved={async () => { setEditing(null); toast(t('msgs.saved')); await load() }} />}
      {toastNode}
    </>
  )
}

function TemplatePanel({ template, onClose, onSaved }: { template: Template; onClose: () => void; onSaved: () => Promise<void> }) {
  const { t } = useT()
  const [subject, setSubject] = useState(template.subject ?? '')
  const [body, setBody] = useState(template.body)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [busy, setBusy] = useState(false)
  const bodyRef = useRef<HTMLTextAreaElement>(null)

  // Datos de ejemplo solo para la vista previa (el envío real los toma de la API).
  const sample = useMemo<Record<string, string>>(() => ({
    name: 'Carlos Mendoza', organization: 'Comercial Los Andes S.A.C.', link: 'https://demo.gestiahr.com/…', requester: t('msgs.sample_requester'),
    type: t('msgs.sample_type'), level: '1', title: t('msgs.sample_title'), due_on: '2026-11-20', reason: t('msgs.sample_reason'), event: t('msgs.sample_event'),
  }), [t])
  const fill = (s: string) => s.replace(/\{([a-z_]+)\}/g, (_, k: string) => sample[k] ?? `{${k}}`)

  function insert(v: string) {
    const el = bodyRef.current
    const token = `{${v}}`
    if (!el) { setBody((b) => b + token); return }
    const [a, b] = [el.selectionStart, el.selectionEnd]
    setBody((cur) => cur.slice(0, a) + token + cur.slice(b))
    requestAnimationFrame(() => { el.focus(); el.setSelectionRange(a + token.length, a + token.length) })
  }

  async function submit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault()
    setBusy(true)
    setErrors({})
    try {
      await api.request(`/notification-templates/${template.id}`, 'PATCH', { subject, body })
      await onSaved()
    } catch (err) {
      const { fields, general } = formErrors(err, t)
      setErrors(general ? { ...fields, body: fields.body ?? general } : fields)
    } finally {
      setBusy(false)
    }
  }

  return (
    <SidePanel
      title={`${t('msgs.edit_title')} · ${categoryLabel(t, template.key)}`}
      onClose={onClose}
      closeLabel={t('common.close')}
      footer={<>
        <button type="button" className="btn btn-ter" onClick={() => { setSubject(template.default_subject); setBody(template.default_body) }}>{t('msgs.restore')}</button>
        <button type="submit" form="plantilla" className="btn btn-primary" disabled={busy}>{busy && <span className="spin" aria-hidden="true" />}{t('msgs.save')}</button>
      </>}
    >
      <p className="small text-secondary">{t(`nchannel.${template.channel}`)} · {t(`msgs.locale_${template.locale}`)}</p>
      {template.channel !== 'in_app' && <div className="nota">{t('msgs.external_note')}</div>}
      <form id="plantilla" onSubmit={submit} noValidate>
        <div className="mb-3">
          <label className="form-label" htmlFor="p-subject">{t('msgs.subject')}</label>
          <input id="p-subject" className={`form-control${errors.subject ? ' is-invalid' : ''}`} value={subject} onChange={(e) => setSubject(e.target.value)} maxLength={300} />
          {errors.subject && <div className="invalid-feedback" role="alert">{errors.subject}</div>}
        </div>
        <div className="mb-3">
          <label className="form-label" htmlFor="p-body">{t('msgs.body')}</label>
          <textarea id="p-body" ref={bodyRef} className={`form-control${errors.body ? ' is-invalid' : ''}`} rows={template.channel === 'in_app' ? 3 : 9} value={body} onChange={(e) => setBody(e.target.value)} aria-describedby="p-vars-h" />
          {errors.body && <div className="invalid-feedback" role="alert">{errors.body}</div>}
        </div>
        <span className="form-label">{t('msgs.variables')}</span>
        <div className="chips" style={{ marginBottom: 4 }}>
          {template.variables.map((v) => <button key={v} type="button" onClick={() => insert(v)}><code>{`{${v}}`}</code></button>)}
        </div>
        <p id="p-vars-h" className="small text-secondary">{t('msgs.variables_help')}</p>
      </form>
      <h3 className="small" style={{ fontWeight: 700, margin: '16px 0 8px' }}>{t('msgs.preview')}</h3>
      <div className="vista-previa">
        <b>{fill(subject)}</b>
        <p>{fill(body)}</p>
      </div>
    </SidePanel>
  )
}
