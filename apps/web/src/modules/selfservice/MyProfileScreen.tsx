'use client'

import { useCallback, useEffect, useState } from 'react'
import { api } from '@/lib/api'
import { formatDate } from '@/lib/format'
import { useT } from '@/lib/useT'
import { ChangeRequestPanel } from '@/modules/people/profile/ChangeRequestPanel'
import { WorkDataCards } from '@/modules/people/profile/SummaryTab'
import { CHANGE_FIELDS } from '@/modules/people/profile/types'
import { Card } from '@/ui/components/Card'
import { Notice } from '@/ui/components/Notice'
import { PageHeader } from '@/ui/components/PageHeader'
import { SkeletonBlock } from '@/ui/components/Skeleton'
import { EmptyState, ErrorState } from '@/ui/components/States'
import { StatusBadge } from '@/ui/components/Status'
import { useToast } from '@/ui/components/Toast'
import { EmploymentSwitch } from './EmploymentSwitch'
import { proposableFields } from './portal'
import type { MeEmployment } from './types'

/**
 * ESS-2 · Mi perfil: el colaborador ve sus datos laborales vigentes y los personales propios (GET
 * /me/employment). No edita la ficha: propone cambios (COL-4, /profile-change-requests) solo de los datos
 * que la organización habilita; los que esperan a RRHH se muestran como pendientes.
 */
export function MyProfileScreen() {
  const { t, locale } = useT()
  const { toast, node: toastNode } = useToast()
  const [employmentId, setEmploymentId] = useState<string | null>(null)
  const [me, setMe] = useState<MeEmployment | null>(null)
  const [failed, setFailed] = useState(false)

  const load = useCallback(async () => {
    try {
      const q = employmentId ? `?employment_id=${encodeURIComponent(employmentId)}` : ''
      setMe((await api.get<{ data: MeEmployment }>(`/me/employment${q}`)).data)
      setFailed(false)
    } catch {
      setFailed(true)
    }
  }, [employmentId])

  useEffect(() => { void load() }, [load])

  const pending = me?.pending_fields ?? []
  const mark = (field: string) => (pending.includes(field) ? <>{' '}<StatusBadge tone="wa">{t('portal.field_pending')}</StatusBadge></> : null)
  const dash = (v: string | null | undefined) => (v === null || v === undefined || v === '' ? '—' : v)
  const person = me?.person

  return (
    <>
      <PageHeader title={t('portal.profile_title')} sub={me?.header?.person_name ?? undefined} />
      <main className="main portal-main" id="contenido" tabIndex={-1}>
        {failed ? (
          <ErrorState text={t('common.load_error')} retryLabel={t('common.retry')} onRetry={() => void load()} />
        ) : !me ? (
          <SkeletonBlock height={240} />
        ) : !me.employment ? (
          <EmptyState title={t('portal.no_employment_title')} text={t('portal.no_employment_text')} />
        ) : (
          <>
            {me.employments.length > 1 && <EmploymentSwitch employments={me.employments} value={me.employment.id} onChange={setEmploymentId} />}
            {me.employment.read_only && <Notice tone="info" title={t('portal.read_only_title')}>{t('portal.read_only_text')}</Notice>}

            {me.work_data && <WorkDataCards data={me.work_data} />}

            {person && (
              <div className="emp-resumen">
                <Card title={t('ficha.section_person')} context={t('portal.personal_context')}>
                  <dl className="ficha">
                    <dt>{t('ficha.f_full_name')}</dt><dd>{dash(person.full_name)}</dd>
                    <dt>{t('ficha.f_preferred_name')}</dt><dd>{dash(person.preferred_name)}{mark('preferred_name')}</dd>
                    <dt>{t('emp.f_document')}</dt><dd>{dash(person.document_number ?? person.document_number_masked)}</dd>
                    <dt>{t('ficha.f_nationality')}</dt><dd>{dash(person.nationality_code)}{mark('nationality_code')}</dd>
                    <dt>{t('emp.f_birth_date')}</dt><dd>{person.birth_date ? formatDate(locale, person.birth_date) : '—'}{mark('birth_date')}</dd>
                    <dt>{t('ficha.f_marital')}</dt><dd>{person.marital_status ? t(`ficha.marital_${person.marital_status}`) : '—'}{mark('marital_status')}</dd>
                    <dt>{t('emp.f_personal_email')}</dt><dd>{dash(person.personal_email)}{mark('personal_email')}</dd>
                    <dt>{t('emp.f_mobile')}</dt><dd>{dash(person.mobile_phone)}{mark('mobile_phone')}</dd>
                  </dl>
                </Card>

                <Card title={t('ficha.section_address')}>
                  {(me.addresses ?? []).length === 0 ? <p className="text-secondary">{t('ficha.no_address')}{mark('address')}</p> : (
                    <ul className="ficha-lista">
                      {(me.addresses ?? []).map((a) => (
                        <li key={a.id}><div><b>{t(`ficha.address_${a.kind}`)}</b>{a.kind === 'home' && mark('address')}<span className="sub2">{dash(a.address)}</span></div></li>
                      ))}
                    </ul>
                  )}
                </Card>

                <Card title={t('ficha.section_emergency')} context={t('portal.emergency_context')}>
                  {(me.emergency_contacts ?? []).length === 0 ? <p className="text-secondary">{t('ficha.no_contacts')}</p> : (
                    <ul className="ficha-lista">
                      {(me.emergency_contacts ?? []).map((c) => (
                        <li key={c.id}><div><b>{dash(c.full_name)}</b><span className="sub2">{[c.relationship, c.phone].filter(Boolean).join(' · ')}</span></div></li>
                      ))}
                    </ul>
                  )}
                </Card>
              </div>
            )}

            {me.header && (
              <ChangeRequestPanel
                personId={me.header.person_id}
                toast={toast}
                onChanged={load}
                fields={proposableFields(CHANGE_FIELDS, me.editable_fields)}
                pendingFields={pending}
                disabled={!me.can_propose}
              />
            )}
          </>
        )}
      </main>
      {toastNode}
    </>
  )
}
