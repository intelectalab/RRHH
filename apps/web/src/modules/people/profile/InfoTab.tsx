'use client'

import { useCallback, useEffect, useState, type FormEvent } from 'react'
import { api } from '@/lib/api'
import { formErrors } from '@/lib/errors'
import { formatDate } from '@/lib/format'
import { useT } from '@/lib/useT'
import { useSession } from '@/modules/session/SessionProvider'
import { Card } from '@/ui/components/Card'
import { Modal } from '@/ui/components/Modal'
import { Notice } from '@/ui/components/Notice'
import { ErrorState } from '@/ui/components/States'
import { ChangeRequestPanel } from './ChangeRequestPanel'
import { CustomFieldsCard } from './CustomFieldsCard'
import { MARITAL, type Address, type EmergencyContact, type PersonData, type ProfileTabContext, type SensitiveData } from './types'

type Editing =
  | { kind: 'person'; values: Record<string, string> }
  | { kind: 'address'; id: string | null; values: Record<string, string> }
  | { kind: 'contact'; id: string | null; values: Record<string, string> }
  | { kind: 'sensitive'; values: Record<string, string>; consent: boolean }

/**
 * COL-4 · Información: datos de la persona (compartidos entre sus relaciones laborales), direcciones,
 * contactos de emergencia y datos sensibles. Cada bloque aparece solo con su permiso de campo (RN-COL-12);
 * el backend valida lo mismo. El propio colaborador propone cambios con aprobación (RN-COL-06).
 */
export function InfoTab({ header, reload, toast }: ProfileTabContext) {
  const { t, locale } = useT()
  const { can } = useSession()
  const [person, setPerson] = useState<PersonData | null>(null)
  const [addresses, setAddresses] = useState<Address[]>([])
  const [contacts, setContacts] = useState<EmergencyContact[]>([])
  const [sensitive, setSensitive] = useState<SensitiveData | null>(null)
  const [failed, setFailed] = useState(false)
  const [editing, setEditing] = useState<Editing | null>(null)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [general, setGeneral] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  const canPersonal = can('people.people.personal_data.view')
  const canEditPerson = can('people.people.update')
  const canEditPersonal = canEditPerson && can('people.people.personal_data.edit')
  const canHealth = can('people.people.health_data.view')

  const load = useCallback(async () => {
    try {
      const pid = header.person_id
      const [p, a, c, s] = await Promise.all([
        can('people.people.view') ? api.get<{ data: PersonData }>(`/people/${pid}`).then((r) => r.data) : Promise.resolve(null),
        canPersonal ? api.get<{ data: Address[] }>(`/people/${pid}/addresses`).then((r) => r.data) : Promise.resolve([]),
        canPersonal ? api.get<{ data: EmergencyContact[] }>(`/people/${pid}/emergency-contacts`).then((r) => r.data) : Promise.resolve([]),
        canHealth ? api.get<{ data: SensitiveData }>(`/people/${pid}/sensitive-data`).then((r) => r.data) : Promise.resolve(null),
      ])
      setPerson(p)
      setAddresses(a)
      setContacts(c)
      setSensitive(s)
      setFailed(false)
    } catch {
      setFailed(true)
    }
  }, [header.person_id, can, canPersonal, canHealth])

  useEffect(() => { void load() }, [load])

  function edit(next: Editing) {
    setErrors({})
    setGeneral(null)
    setEditing(next)
  }

  async function save(e: FormEvent) {
    e.preventDefault()
    if (!editing) return
    const pid = header.person_id
    const clean = (v: Record<string, string>) => Object.fromEntries(Object.entries(v).map(([k, val]) => [k, val.trim() === '' ? null : val.trim()]))
    setBusy(true)
    setErrors({})
    setGeneral(null)
    try {
      if (editing.kind === 'person') {
        await api.request(`/people/${pid}`, 'PATCH', clean(editing.values), person ? { 'If-Match': `"${person.version}"` } : undefined)
      } else if (editing.kind === 'address') {
        if (editing.id) await api.request(`/people/${pid}/addresses/${editing.id}`, 'PATCH', clean(editing.values))
        else await api.post(`/people/${pid}/addresses`, clean(editing.values))
      } else if (editing.kind === 'contact') {
        if (editing.id) await api.request(`/people/${pid}/emergency-contacts/${editing.id}`, 'PATCH', clean(editing.values))
        else await api.post(`/people/${pid}/emergency-contacts`, clean(editing.values))
      } else {
        await api.request(`/people/${pid}/sensitive-data`, 'PUT', {
          ...clean(editing.values),
          ...(editing.consent ? { consent: { granted: true, channel: 'paper' } } : {}),
        })
      }
      setEditing(null)
      toast(t('ficha.saved'))
      await load()
      await reload()
    } catch (err) {
      const f = formErrors(err, t)
      setErrors(f.fields)
      setGeneral(f.general ?? f.fields.consent ?? null)
    } finally {
      setBusy(false)
    }
  }

  async function remove(kind: 'addresses' | 'emergency-contacts', id: string) {
    try {
      await api.request(`/people/${header.person_id}/${kind}/${id}`, 'DELETE')
      toast(t('ficha.deleted'))
      await load()
    } catch (err) {
      toast(formErrors(err, t).general ?? t('auth.generic_error'), 'error')
    }
  }

  if (failed) return <ErrorState text={t('common.load_error')} retryLabel={t('common.retry')} onRetry={() => void load()} />

  const dash = (v: string | null | undefined) => (v === null || v === undefined || v === '' ? '—' : v)
  const row = (label: string, value: string | null | undefined) => <><dt>{label}</dt><dd>{dash(value)}</dd></>
  const input = (name: string, label: string, type = 'text') => editing && 'values' in editing && (
    <div className="mb-3">
      <label className="form-label" htmlFor={`info-${name}`}>{label}</label>
      <input id={`info-${name}`} type={type} className={`form-control${errors[name] ? ' is-invalid' : ''}`} value={editing.values[name] ?? ''}
        onChange={(e) => setEditing((ed) => (ed ? { ...ed, values: { ...ed.values, [name]: e.target.value } } as Editing : ed))} />
      {errors[name] && <div className="invalid-feedback" role="alert">{errors[name]}</div>}
    </div>
  )

  return (
    <>
      {header.is_self && !canEditPerson && <ChangeRequestPanel personId={header.person_id} toast={toast} onChanged={load} />}
      <div className="emp-resumen">
        <Card title={t('ficha.section_person')} context={t('ficha.person_shared')}
          actions={canEditPerson && person ? (
            <button type="button" className="btn btn-ter btn-sm" onClick={() => edit({ kind: 'person', values: {
              first_names: person.first_names, paternal_surname: person.paternal_surname, maternal_surname: person.maternal_surname ?? '',
              preferred_name: person.preferred_name ?? '', nationality_code: person.nationality_code ?? '',
              ...(canEditPersonal ? { birth_date: person.birth_date ?? '', marital_status: person.marital_status ?? '', personal_email: person.personal_email ?? '', mobile_phone: person.mobile_phone ?? '' } : {}),
            } })}>{t('ficha.edit')}</button>
          ) : undefined}>
          {!person ? <span className="esq" style={{ width: 200 }} /> : (
            <dl className="ficha">
              {row(t('ficha.f_full_name'), person.full_name)}
              {row(t('ficha.f_preferred_name'), person.preferred_name)}
              {row(t('emp.f_document'), person.document_number ?? person.document_number_masked)}
              {row(t('ficha.f_nationality'), person.nationality_code)}
              {canPersonal && (
                <>
                  {row(t('emp.f_birth_date'), person.birth_date ? formatDate(locale, person.birth_date) : null)}
                  {row(t('ficha.f_marital'), person.marital_status ? t(`ficha.marital_${person.marital_status}`) : null)}
                  {person.sex !== undefined && row(t('emp.f_sex'), person.sex ? t(`emp.sex_${person.sex}`) : null)}
                  {row(t('emp.f_personal_email'), person.personal_email)}
                  {row(t('emp.f_mobile'), person.mobile_phone)}
                </>
              )}
            </dl>
          )}
          {!canPersonal && <p className="small text-secondary mt-2">{t('emp.personal_data_hidden')}</p>}
        </Card>

        {canPersonal && (
          <Card title={t('ficha.section_address')}
            actions={canEditPersonal ? <button type="button" className="btn btn-ter btn-sm" onClick={() => edit({ kind: 'address', id: null, values: { address: '', district_code: '' } })}>{t('ficha.new_address')}</button> : undefined}>
            {addresses.length === 0 ? <p className="text-secondary">{t('ficha.no_address')}</p> : (
              <ul className="ficha-lista">
                {addresses.map((a) => (
                  <li key={a.id}>
                    <div><b>{t(`ficha.address_${a.kind}`)}</b><span className="sub2">{dash(a.address)}{a.district_code ? ` · ${a.district_code}` : ''}</span>
                      {a.valid_from && <span className="sub2">{t('ficha.since', { date: formatDate(locale, a.valid_from) })}</span>}</div>
                    {canEditPersonal && (
                      <div className="ficha-acc">
                        <button type="button" className="btn btn-ter btn-sm" onClick={() => edit({ kind: 'address', id: a.id, values: { address: a.address ?? '', district_code: a.district_code ?? '' } })}>{t('ficha.correct')}</button>
                        <button type="button" className="btn btn-ter btn-sm" onClick={() => void remove('addresses', a.id)}>{t('ficha.delete')}</button>
                      </div>
                    )}
                  </li>
                ))}
              </ul>
            )}
            {canEditPersonal && <p className="small text-secondary mt-2">{t('ficha.address_help')}</p>}
          </Card>
        )}

        {canPersonal && (
          <Card title={t('ficha.section_emergency')}
            actions={canEditPersonal ? <button type="button" className="btn btn-ter btn-sm" onClick={() => edit({ kind: 'contact', id: null, values: { full_name: '', relationship: '', phone: '' } })}>{t('ficha.new_contact')}</button> : undefined}>
            {contacts.length === 0 ? <p className="text-secondary">{t('ficha.no_contacts')}</p> : (
              <ul className="ficha-lista">
                {contacts.map((c) => (
                  <li key={c.id}>
                    <div><b>{dash(c.full_name)}</b><span className="sub2">{[c.relationship, c.phone].filter(Boolean).join(' · ')}</span></div>
                    {canEditPersonal && (
                      <div className="ficha-acc">
                        <button type="button" className="btn btn-ter btn-sm" onClick={() => edit({ kind: 'contact', id: c.id, values: { full_name: c.full_name ?? '', relationship: c.relationship ?? '', phone: c.phone ?? '' } })}>{t('ficha.edit')}</button>
                        <button type="button" className="btn btn-ter btn-sm" onClick={() => void remove('emergency-contacts', c.id)}>{t('ficha.delete')}</button>
                      </div>
                    )}
                  </li>
                ))}
              </ul>
            )}
          </Card>
        )}

        {canHealth && sensitive && (
          <Card title={t('ficha.section_sensitive')} context={t('ficha.sensitive_context')}
            actions={canEditPersonal ? <button type="button" className="btn btn-ter btn-sm" onClick={() => edit({ kind: 'sensitive', values: { disability: sensitive.disability ?? '', blood_type: sensitive.blood_type ?? '' }, consent: false })}>{t('ficha.edit')}</button> : undefined}>
            <dl className="ficha">
              {row(t('ficha.f_disability'), sensitive.disability)}
              {row(t('ficha.f_blood_type'), sensitive.blood_type)}
              {row(t('ficha.f_consent'), sensitive.consent ? t('ficha.consent_granted', { date: formatDate(locale, sensitive.consent.granted_at) }) : t('ficha.consent_none'))}
            </dl>
          </Card>
        )}
      </div>

      {editing && (
        <Modal
          title={t(`ficha.edit_${editing.kind}`)}
          onClose={() => setEditing(null)}
          footer={(
            <>
              <button type="button" className="btn btn-sec" onClick={() => setEditing(null)}>{t('org.cancel')}</button>
              <button type="submit" form="form-ficha-info" className="btn btn-primary" disabled={busy} aria-busy={busy}>{t('ficha.save')}</button>
            </>
          )}
        >
          <form id="form-ficha-info" onSubmit={(e) => void save(e)} noValidate>
            {general && <Notice tone="error">{general}</Notice>}
            {editing.kind === 'person' && (
              <>
                {input('first_names', t('emp.f_first_names'))}
                {input('paternal_surname', t('emp.f_paternal'))}
                {input('maternal_surname', t('emp.f_maternal'))}
                {input('preferred_name', t('ficha.f_preferred_name'))}
                {input('nationality_code', t('ficha.f_nationality'))}
                {canEditPersonal && (
                  <>
                    {input('birth_date', t('emp.f_birth_date'), 'date')}
                    <div className="mb-3">
                      <label className="form-label" htmlFor="info-marital">{t('ficha.f_marital')}</label>
                      <select id="info-marital" className="form-select" value={editing.values.marital_status ?? ''}
                        onChange={(e) => setEditing((ed) => (ed ? { ...ed, values: { ...ed.values, marital_status: e.target.value } } as Editing : ed))}>
                        <option value="">{t('emp.not_specified')}</option>
                        {MARITAL.map((m) => <option key={m} value={m}>{t(`ficha.marital_${m}`)}</option>)}
                      </select>
                    </div>
                    {input('personal_email', t('emp.f_personal_email'), 'email')}
                    {input('mobile_phone', t('emp.f_mobile'), 'tel')}
                  </>
                )}
              </>
            )}
            {editing.kind === 'address' && (
              <>
                {editing.id === null && <Notice tone="info">{t('ficha.address_new_help')}</Notice>}
                {input('address', t('ficha.f_address'))}
                {input('district_code', t('ficha.f_district'))}
              </>
            )}
            {editing.kind === 'contact' && (
              <>
                {input('full_name', t('ficha.f_contact_name'))}
                {input('relationship', t('ficha.f_relationship'))}
                {input('phone', t('ficha.f_phone'), 'tel')}
              </>
            )}
            {editing.kind === 'sensitive' && (
              <>
                {input('disability', t('ficha.f_disability'))}
                {input('blood_type', t('ficha.f_blood_type'))}
                {!sensitive?.consent && (
                  <div className="form-check mb-3">
                    <input id="info-consent" type="checkbox" className="form-check-input" checked={editing.consent}
                      onChange={(e) => setEditing((ed) => (ed && ed.kind === 'sensitive' ? { ...ed, consent: e.target.checked } : ed))} />
                    <label className="form-check-label" htmlFor="info-consent">{t('ficha.consent_label')}</label>
                  </div>
                )}
              </>
            )}
          </form>
        </Modal>
      )}
      <CustomFieldsCard employmentId={header.id} toast={toast} />
    </>
  )
}
