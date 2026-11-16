'use client'

import Link from 'next/link'
import { useCallback, useEffect, useState } from 'react'
import { api } from '@/lib/api'
import { formatDate, formatNumber } from '@/lib/format'
import { useT } from '@/lib/useT'
import { seniorityText } from '@/modules/people/profile/seniority'
import { useSession } from '@/modules/session/SessionProvider'
import { Card } from '@/ui/components/Card'
import { Icon } from '@/ui/components/Icon'
import { Notice } from '@/ui/components/Notice'
import { PageHeader } from '@/ui/components/PageHeader'
import { SkeletonBlock } from '@/ui/components/Skeleton'
import { EmptyState, ErrorState } from '@/ui/components/States'
import { StatusBadge } from '@/ui/components/Status'
import { EmploymentSwitch } from './EmploymentSwitch'
import { cardOf, certificateTone, days, ticketTone } from './portal'
import type { MeHome } from './types'

/**
 * ESS-1 · Inicio del colaborador: su resumen propio (perfil, saldo de vacaciones, solicitudes en curso,
 * próximas ausencias y pendientes) desde GET /me/home. El servidor solo devuelve datos propios (RN-ESS-01)
 * y solo tarjetas de módulos contratados (RN-ESS-02); los saldos vienen calculados (regla 11).
 */
export function MyHomeScreen() {
  const { t, locale } = useT()
  const { session, can } = useSession()
  const [employmentId, setEmploymentId] = useState<string | null>(null)
  const [home, setHome] = useState<MeHome | null>(null)
  const [failed, setFailed] = useState(false)

  const load = useCallback(async () => {
    try {
      const q = employmentId ? `?employment_id=${encodeURIComponent(employmentId)}` : ''
      setHome((await api.get<{ data: MeHome }>(`/me/home${q}`)).data)
      setFailed(false)
    } catch {
      setFailed(true)
    }
  }, [employmentId])

  useEffect(() => { void load() }, [load])

  const profile = home ? cardOf(home.cards, 'profile') : undefined
  const vacations = home ? cardOf(home.cards, 'vacations') : undefined
  const absences = home ? cardOf(home.cards, 'absences') : undefined
  const documents = home ? cardOf(home.cards, 'documents') : undefined
  const certificates = home ? cardOf(home.cards, 'certificates') : undefined
  const hrRequests = home ? cardOf(home.cards, 'hr_requests') : undefined
  const name = profile?.data.first_names ?? session.user.display_name
  const readOnly = home?.employment?.read_only ?? false

  return (
    <>
      <PageHeader title={t('portal.hello', { name })} sub={home?.employment?.legal_entity_name ?? session.tenant?.name} />
      <main className="main portal-main" id="contenido" tabIndex={-1}>
        {failed ? (
          <ErrorState text={t('common.load_error')} retryLabel={t('common.retry')} onRetry={() => void load()} />
        ) : !home ? (
          <SkeletonBlock height={240} />
        ) : (
          <>
            {home.employments.length > 1 && home.employment && (
              <EmploymentSwitch employments={home.employments} value={home.employment.id} onChange={setEmploymentId} />
            )}
            {readOnly && <Notice tone="info" title={t('portal.read_only_title')}>{t('portal.read_only_text')}</Notice>}
            {!home.employment && <EmptyState title={t('portal.no_employment_title')} text={t('portal.no_employment_text')} />}

            {home.employment && (
              <div className="portal-actions" role="group" aria-label={t('portal.quick_actions')}>
                {vacations && can('time.vacation_requests.create') && !readOnly && (
                  <Link className="portal-action" href="/tiempo/vacaciones"><Icon name="calendar" size={22} /><span>{t('portal.request_vacation')}</span></Link>
                )}
                {absences && can('time.absences.create') && !readOnly && (
                  <Link className="portal-action" href="/tiempo/ausencias"><Icon name="file" size={22} /><span>{t('portal.request_absence')}</span></Link>
                )}
                {profile && (
                  <Link className="portal-action" href="/mi/perfil"><Icon name="user" size={22} /><span>{t('portal.my_profile')}</span></Link>
                )}
                {certificates && (
                  <Link className="portal-action" href="/mi/solicitudes"><Icon name="file" size={22} /><span>{t('portal.request_certificate')}</span></Link>
                )}
                {hrRequests && !readOnly && (
                  <Link className="portal-action" href="/mi/solicitudes"><Icon name="checkSquare" size={22} /><span>{t('portal.request_hr')}</span></Link>
                )}
              </div>
            )}

            <div className="portal-cards">
              {vacations && (
                <Card title={t('portal.card_vacations')} actions={<Link className="btn btn-ter btn-sm" href={vacations.href}>{t('portal.see_more')}</Link>}>
                  <p className="portal-big">
                    <b>{formatNumber(locale, days(vacations.data.available_days))}</b>
                    <span>{t('portal.available_days')}</span>
                  </p>
                  <ul className="portal-list">
                    {vacations.data.pending_validation && <li><StatusBadge tone="wa">{t('portal.balance_pending_validation')}</StatusBadge></li>}
                    {days(vacations.data.overdue_days) !== '0' && <li>{t('portal.overdue_days', { n: formatNumber(locale, days(vacations.data.overdue_days)) })}</li>}
                    {days(vacations.data.scheduled_days) !== '0' && <li>{t('portal.scheduled_days', { n: formatNumber(locale, days(vacations.data.scheduled_days)) })}</li>}
                    {vacations.data.pending_requests > 0 && <li>{t('portal.requests_pending', { n: vacations.data.pending_requests })}</li>}
                    {vacations.data.next && (
                      <li>{t('portal.next_vacation', { from: formatDate(locale, vacations.data.next.starts_on), to: formatDate(locale, vacations.data.next.ends_on) })}</li>
                    )}
                  </ul>
                </Card>
              )}

              {absences && (
                <Card title={t('portal.card_absences')} actions={<Link className="btn btn-ter btn-sm" href={absences.href}>{t('portal.see_more')}</Link>}>
                  {absences.data.pending_requests > 0 && <p>{t('portal.requests_pending', { n: absences.data.pending_requests })}</p>}
                  {absences.data.upcoming.length === 0 ? (
                    <p className="text-secondary">{t('portal.no_upcoming_absences')}</p>
                  ) : (
                    <ul className="portal-list">
                      {absences.data.upcoming.map((a) => (
                        <li key={a.id}><b>{a.type_name}</b><span className="sub2">{t('portal.range', { from: formatDate(locale, a.starts_on), to: formatDate(locale, a.ends_on) })}</span></li>
                      ))}
                    </ul>
                  )}
                </Card>
              )}

              {documents && (
                <Card title={t('portal.card_documents')} actions={<Link className="btn btn-ter btn-sm" href={documents.href}>{t('portal.see_more')}</Link>}>
                  <p className="portal-big"><b>{documents.data.count}</b><span>{t('portal.published_documents')}</span></p>
                  <ul className="portal-list">
                    {documents.data.recent.map((d) => (
                      <li key={d.id}><b>{d.title}</b>{d.issued_on && <span className="sub2">{formatDate(locale, d.issued_on)}</span>}</li>
                    ))}
                  </ul>
                </Card>
              )}

              {certificates && (
                <Card title={t('portal.card_certificates')} actions={<Link className="btn btn-ter btn-sm" href={certificates.href}>{t('portal.see_more')}</Link>}>
                  {certificates.data.pending_requests > 0 && <p><StatusBadge tone={certificateTone('requested')}>{t('portal.requests_pending', { n: certificates.data.pending_requests })}</StatusBadge></p>}
                  {certificates.data.last_issued ? (
                    <p>{t('portal.last_certificate', { name: certificates.data.last_issued.kind_name, date: formatDate(locale, certificates.data.last_issued.issued_at) })}</p>
                  ) : <p className="text-secondary">{t('portal.no_certificates')}</p>}
                </Card>
              )}

              {hrRequests && (
                <Card title={t('portal.card_hr_requests')} actions={<Link className="btn btn-ter btn-sm" href={hrRequests.href}>{t('portal.see_more')}</Link>}>
                  {hrRequests.data.waiting_info > 0 && <p><StatusBadge tone="wa">{t('portal.waiting_your_reply', { n: hrRequests.data.waiting_info })}</StatusBadge></p>}
                  {hrRequests.data.latest.length === 0 ? <p className="text-secondary">{t('portal.no_hr_requests')}</p> : (
                    <ul className="portal-list">
                      {hrRequests.data.latest.map((r) => (
                        <li key={r.id}><b>{r.number} · {r.subject}</b> <StatusBadge tone={ticketTone(r.status)}>{t(`tickets.status_${r.status}`)}</StatusBadge></li>
                      ))}
                    </ul>
                  )}
                </Card>
              )}

              {profile && (
                <Card title={t('portal.card_profile')} actions={<Link className="btn btn-ter btn-sm" href={profile.href}>{t('portal.see_more')}</Link>}>
                  <dl className="ficha">
                    <dt>{t('portal.job')}</dt><dd>{profile.data.position_title ?? profile.data.job_profile_name ?? '—'}</dd>
                    <dt>{t('portal.unit')}</dt><dd>{profile.data.org_unit_name ?? '—'}</dd>
                    <dt>{t('portal.manager')}</dt><dd>{profile.data.manager_name ?? '—'}</dd>
                    <dt>{t('portal.seniority')}</dt><dd>{profile.data.seniority ? seniorityText(t, profile.data.seniority) : '—'}</dd>
                  </dl>
                  {profile.data.pending_changes > 0 && <p className="mt-2"><StatusBadge tone="wa">{t('portal.changes_pending', { n: profile.data.pending_changes })}</StatusBadge></p>}
                </Card>
              )}

              <Card title={t('portal.card_pending')} actions={home.pending.total > 0 ? <Link className="btn btn-ter btn-sm" href="/pendientes">{t('portal.see_all')}</Link> : undefined}>
                {home.pending.items.length === 0 ? (
                  <p className="text-secondary">{t('portal.no_pending')}</p>
                ) : (
                  <ul className="portal-list">
                    {home.pending.items.map((i) => (
                      <li key={`${i.kind}-${i.id}`}>
                        <Link href={i.href} className="portal-link">
                          <b>{i.title}</b>
                          {i.subtitle && <span className="sub2">{i.subtitle}</span>}
                          {i.overdue && <StatusBadge tone="er">{t('portal.overdue')}</StatusBadge>}
                        </Link>
                      </li>
                    ))}
                  </ul>
                )}
              </Card>
            </div>
          </>
        )}
      </main>
    </>
  )
}
