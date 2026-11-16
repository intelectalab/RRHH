'use client'

import { useCallback, useEffect, useState } from 'react'
import { api, type Schema } from '@/lib/api'
import { formatDate } from '@/lib/format'
import { useT } from '@/lib/useT'
import type { Legajo } from '@/modules/documents/documents'
import { useSession } from '@/modules/session/SessionProvider'
import { Card } from '@/ui/components/Card'
import { PageHeader } from '@/ui/components/PageHeader'
import { SkeletonBlock } from '@/ui/components/Skeleton'
import { EmptyState, ErrorState } from '@/ui/components/States'
import { StatusBadge } from '@/ui/components/Status'
import { EmploymentSwitch } from './EmploymentSwitch'
import { FileViewer } from './FileViewer'
import type { MeHome } from './types'

type Contract = Schema<'Contract'>

/** Contratos que se muestran como vigentes en el portal (los demás quedan en su historial del legajo). */
const CURRENT_CONTRACT = ['active', 'signed', 'pending_signature']

/**
 * ESS-3 · Mis documentos y contrato: el legajo propio por carpeta con solo lo publicado para el colaborador
 * (lo filtra el servidor) y su contrato vigente. Cada documento se abre en el visor y la apertura queda
 * registrada en la API. La firma desde el portal llega con DOC-6.
 */
export function MyDocumentsScreen() {
  const { t, locale } = useT()
  const { can } = useSession()
  const [employmentId, setEmploymentId] = useState<string | null>(null)
  const [home, setHome] = useState<MeHome | null>(null)
  const [legajo, setLegajo] = useState<Legajo | null>(null)
  const [contracts, setContracts] = useState<Contract[]>([])
  const [failed, setFailed] = useState(false)
  const [viewing, setViewing] = useState<{ url: string; title: string } | null>(null)

  const load = useCallback(async () => {
    try {
      const q = employmentId ? `?employment_id=${encodeURIComponent(employmentId)}` : ''
      const me = (await api.get<{ data: MeHome }>(`/me/home${q}`)).data
      setHome(me)
      if (me.employment) {
        const id = me.employment.id
        setLegajo((await api.get<{ data: Legajo }>(`/employments/${id}/documents`)).data)
        if (can('contracts.contracts.view')) {
          const list = (await api.get<{ data: Contract[] }>('/contracts')).data
          setContracts(list.filter((c) => c.employment_id === id && CURRENT_CONTRACT.includes(c.status)))
        }
      }
      setFailed(false)
    } catch {
      setFailed(true)
    }
  }, [employmentId, can])

  useEffect(() => { void load() }, [load])

  const folders = legajo?.folders.filter((f) => f.documents.length + f.linked.length > 0) ?? []

  return (
    <>
      <PageHeader title={t('portal.documents_title')} sub={t('portal.documents_sub')} />
      <main className="main portal-main" id="contenido" tabIndex={-1}>
        {failed ? (
          <ErrorState text={t('common.load_error')} retryLabel={t('common.retry')} onRetry={() => void load()} />
        ) : !home ? (
          <SkeletonBlock height={240} />
        ) : !home.employment ? (
          <EmptyState title={t('portal.no_employment_title')} text={t('portal.no_employment_text')} />
        ) : (
          <>
            {home.employments.length > 1 && <EmploymentSwitch employments={home.employments} value={home.employment.id} onChange={setEmploymentId} />}
            <div className="portal-cards">
              {contracts.map((c) => (
                <Card key={c.id} title={t('portal.my_contract')} actions={c.has_document ? (
                  <button type="button" className="btn btn-ter btn-sm" onClick={() => setViewing({ url: `/contracts/${c.id}/document`, title: t('portal.my_contract') })}>{t('portal.open')}</button>
                ) : undefined}>
                  <dl className="ficha">
                    <dt>{t('portal.contract_type')}</dt><dd>{c.contract_type_name ?? '—'}</dd>
                    <dt>{t('portal.contract_from')}</dt><dd>{formatDate(locale, c.starts_on)}</dd>
                    <dt>{t('portal.contract_to')}</dt><dd>{c.ends_on ? formatDate(locale, c.ends_on) : t('portal.contract_indefinite')}</dd>
                  </dl>
                </Card>
              ))}

              {!legajo ? <SkeletonBlock height={160} /> : folders.length === 0 ? (
                <EmptyState title={t('portal.no_documents_title')} text={t('portal.no_documents_text')} />
              ) : folders.map((f) => (
                <Card key={f.id} title={f.name}>
                  <ul className="portal-list">
                    {f.documents.map((d) => (
                      <li key={d.id}>
                        <button type="button" className="portal-link" onClick={() => setViewing({ url: `/documents/${d.id}/file?inline=true`, title: d.title })}>
                          <b>{d.title}</b>
                          <span className="sub2">
                            {d.issued_on ? formatDate(locale, d.issued_on) : formatDate(locale, d.created_at)}
                            {d.expires_on && <> · {t('portal.expires_on', { date: formatDate(locale, d.expires_on) })}</>}
                          </span>
                          {d.status === 'expired' && <StatusBadge tone="er">{t('portal.expired')}</StatusBadge>}
                          {d.status === 'pending_approval' && <StatusBadge tone="wa">{t('portal.in_review')}</StatusBadge>}
                        </button>
                      </li>
                    ))}
                    {f.linked.map((l) => (
                      <li key={l.ref}>
                        <button type="button" className="portal-link" onClick={() => setViewing({ url: `/employments/${home.employment?.id ?? ''}/linked-documents/${encodeURIComponent(l.ref)}/file`, title: l.title })}>
                          <b>{l.title}</b>
                          {l.subtitle && <span className="sub2">{l.subtitle}</span>}
                        </button>
                      </li>
                    ))}
                  </ul>
                </Card>
              ))}
            </div>
          </>
        )}
      </main>
      {viewing && <FileViewer url={viewing.url} title={viewing.title} onClose={() => setViewing(null)} />}
    </>
  )
}
