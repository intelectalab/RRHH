'use client'

import Link from 'next/link'
import { useRouter } from 'next/navigation'
import { useCallback, useEffect, useRef, useState } from 'react'
import { api } from '@/lib/api'
import { formErrors } from '@/lib/errors'
import { formatDate, initials } from '@/lib/format'
import { useT } from '@/lib/useT'
import { useSession } from '@/modules/session/SessionProvider'
import { Icon } from '@/ui/components/Icon'
import { Modal } from '@/ui/components/Modal'
import { PageHeader } from '@/ui/components/PageHeader'
import { StatusBadge } from '@/ui/components/Status'
import { ErrorState } from '@/ui/components/States'
import { Tabs } from '@/ui/components/Tabs'
import { useToast } from '@/ui/components/Toast'
import { STATUS_TONE, type Employment } from '../employment'
import { TerminationActions, TerminationAndRelations } from '../EmploymentTermination'
import { peopleTabs } from '../peopleTabs'
import { PROFILE_TABS, visibleTabs } from './profileTabs'
import { seniorityText } from './seniority'
import type { ProfileHeader } from './types'

/**
 * COL-3 · Ficha 360 del colaborador, abierta sobre una relación laboral: cabecera con foto, nombre,
 * puesto, estado y antigüedad (calculada por el servidor), cambio a otra relación de la misma persona y
 * pestañas registradas por los módulos (profileTabs.tsx). Es la única pantalla donde se editan los datos
 * maestros del colaborador.
 */
export function EmploymentProfileScreen({ id }: { id: string }) {
  const { t, locale } = useT()
  const { can, session } = useSession()
  const router = useRouter()
  const { toast, node: toastNode } = useToast()
  const [header, setHeader] = useState<ProfileHeader | null>(null)
  // COL-11 · El cese trabaja sobre la relación completa (acciones permitidas, cese registrado, otras relaciones).
  const [employment, setEmployment] = useState<Employment | null>(null)
  const [failed, setFailed] = useState(false)
  const [tab, setTab] = useState('summary')
  const [busy, setBusy] = useState(false)
  const [cancelling, setCancelling] = useState(false)
  const [reason, setReason] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [photoStamp, setPhotoStamp] = useState(0)
  const fileRef = useRef<HTMLInputElement>(null)

  const load = useCallback(async () => {
    try {
      const [profile, rel] = await Promise.all([
        api.get<{ data: ProfileHeader }>(`/employments/${id}/profile`),
        api.get<{ data: Employment }>(`/employments/${id}`),
      ])
      setHeader(profile.data)
      setEmployment(rel.data)
      setFailed(false)
    } catch {
      setFailed(true)
    }
  }, [id])

  useEffect(() => { void load() }, [load])
  // ?tab=contracts (p. ej., desde Contratos por vencer o el calendario) abre esa pestaña si el usuario la tiene.
  useEffect(() => {
    const wanted = new URLSearchParams(window.location.search).get('tab')
    if (wanted) setTab(wanted)
  }, [])

  async function activate() {
    setBusy(true)
    try {
      await api.post(`/employments/${id}/activate`, {})
      toast(t('emp.activated'))
      await load()
    } catch (e) {
      toast(formErrors(e, t).general ?? Object.values(formErrors(e, t).fields)[0] ?? t('auth.generic_error'), 'error')
    } finally {
      setBusy(false)
    }
  }

  async function cancel() {
    setBusy(true)
    setError(null)
    try {
      await api.post(`/employments/${id}/cancel`, { reason })
      setCancelling(false)
      toast(t('emp.cancelled'))
      await load()
    } catch (e) {
      const f = formErrors(e, t)
      setError(f.fields.reason ?? f.general)
    } finally {
      setBusy(false)
    }
  }

  async function uploadPhoto(file: File | undefined) {
    if (!file || !header) return
    try {
      await api.upload(`/people/${header.person_id}/photo`, file, file.name)
      setPhotoStamp(Date.now())
      toast(t('ficha.photo_saved'))
      await load()
    } catch (e) {
      const f = formErrors(e, t)
      toast(f.fields.file ?? f.general ?? t('auth.generic_error'), 'error')
    } finally {
      if (fileRef.current) fileRef.current.value = ''
    }
  }

  async function removePhoto() {
    if (!header) return
    try {
      await api.request(`/people/${header.person_id}/photo`, 'DELETE')
      toast(t('ficha.photo_removed'))
      await load()
    } catch (e) {
      toast(formErrors(e, t).general ?? t('auth.generic_error'), 'error')
    }
  }

  if (failed) return <main className="main" id="contenido"><ErrorState text={t('common.load_error')} retryLabel={t('common.retry')} onRetry={() => void load()} /></main>
  if (!header) return <main className="main" id="contenido" aria-busy="true"><span className="esq" style={{ width: 240 }} /></main>

  const actions = header.allowed_actions ?? []
  const tabs = visibleTabs(PROFILE_TABS, session.modules, can)
  const active = tabs.some((x) => x.id === tab) ? tab : (tabs[0]?.id ?? 'summary')
  const ctx = { header, reload: load, toast, goTo: setTab }
  const canPhoto = can('people.people.update')
  const job = [header.position_title ?? header.job_profile_name, header.org_unit_name].filter(Boolean).join(' · ')

  return (
    <>
      <PageHeader
        title={header.person_name ?? t('emp.title')}
        sub={<>{header.employee_code} · {header.legal_entity_name} · <StatusBadge tone={STATUS_TONE[header.status]}>{t(`emp.status_${header.status}`)}</StatusBadge></>}
        tabs={peopleTabs(t, 'employees', can)}
        actions={(
          <>
            <Link className="btn btn-sec" href="/personas/colaboradores"><Icon name="chevronLeft" /> {t('emp.back')}</Link>
            {actions.includes('activate') && can('people.employments.activate') && (
              <button type="button" className="btn btn-primary" disabled={busy} onClick={() => void activate()}>{t('emp.activate')}</button>
            )}
            {actions.includes('cancel') && can('people.employments.update') && (
              <button type="button" className="btn btn-sec" disabled={busy} onClick={() => { setReason(''); setError(null); setCancelling(true) }}>{t('emp.cancel')}</button>
            )}
            {employment && <TerminationActions employment={employment} onChanged={() => void load()} onToast={toast} />}
          </>
        )}
      />
      <main className="main" id="contenido" tabIndex={-1}>
        <section className="ficha-cab" aria-label={t('ficha.header_label')}>
          <div className="ficha-foto">
            {header.has_photo
              // La foto sale de la API con la sesión del usuario: el optimizador de next/image no la alcanza.
              // eslint-disable-next-line @next/next/no-img-element
              ? <img src={`/api/v1/people/${header.person_id}/photo${photoStamp ? `?v=${photoStamp}` : ''}`} alt={t('ficha.photo_alt', { name: header.person_name ?? '' })} />
              : <span className="ficha-ini" aria-hidden="true">{initials(header.person_name)}</span>}
            {canPhoto && (
              <div className="ficha-foto-acc">
                <input ref={fileRef} type="file" accept="image/png,image/jpeg,image/webp" className="visually-hidden" id="ficha-foto-input"
                  onChange={(e) => void uploadPhoto(e.target.files?.[0])} />
                <label htmlFor="ficha-foto-input" className="btn btn-ter btn-sm">{header.has_photo ? t('ficha.photo_change') : t('ficha.photo_add')}</label>
                {header.has_photo && <button type="button" className="btn btn-ter btn-sm" onClick={() => void removePhoto()}>{t('ficha.photo_remove')}</button>}
              </div>
            )}
          </div>
          <dl className="ficha-cab-datos">
            <div><dt>{t('ficha.f_job_now')}</dt><dd>{job || '—'}</dd></div>
            <div><dt>{t('ficha.f_manager')}</dt><dd>{header.manager_name ?? '—'}</dd></div>
            <div><dt>{t('emp.f_hire_date')}</dt><dd>{formatDate(locale, header.hire_date)}</dd></div>
            <div><dt>{t('ficha.f_seniority')}</dt><dd>{header.seniority ? seniorityText(t, header.seniority) : '—'}</dd></div>
          </dl>
          {header.relations.length > 1 && (
            <div className="ficha-rel">
              <label className="form-label" htmlFor="ficha-relacion">{t('ficha.relation')}</label>
              <select id="ficha-relacion" className="form-select" value={header.id} onChange={(e) => router.push(`/personas/colaboradores/${e.target.value}`)}>
                {header.relations.map((r) => (
                  <option key={r.id} value={r.id}>{`${r.legal_entity_name} · ${r.employee_code} · ${t(`emp.status_${r.status}`)}`}</option>
                ))}
              </select>
            </div>
          )}
        </section>
        {employment && <TerminationAndRelations employment={employment} />}
        <Tabs
          label={t('ficha.tabs_label')}
          value={active}
          onChange={setTab}
          items={tabs.map((x) => ({ id: x.id, label: t(x.label), content: x.render(ctx) }))}
        />
      </main>
      {cancelling && (
        <Modal
          title={t('emp.cancel_title')}
          onClose={() => setCancelling(false)}
          footer={(
            <>
              <button type="button" className="btn btn-sec" onClick={() => setCancelling(false)}>{t('org.cancel')}</button>
              <button type="button" className="btn btn-danger" disabled={busy} aria-busy={busy} onClick={() => void cancel()}>{t('emp.cancel_confirm')}</button>
            </>
          )}
        >
          <p>{t('emp.cancel_text')}</p>
          <label className="form-label" htmlFor="emp-cancel-reason">{t('emp.cancel_reason')}</label>
          <textarea id="emp-cancel-reason" className={`form-control${error ? ' is-invalid' : ''}`} rows={3} value={reason} onChange={(e) => setReason(e.target.value)} />
          {error && <div className="invalid-feedback" role="alert">{error}</div>}
        </Modal>
      )}
      {toastNode}
    </>
  )
}
