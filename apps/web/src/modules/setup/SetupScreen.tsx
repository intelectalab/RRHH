'use client'

import Link from 'next/link'
import { useCallback, useEffect, useState } from 'react'
import { api } from '@/lib/api'
import { formatDate } from '@/lib/format'
import { useT } from '@/lib/useT'
import { Button } from '@/ui/components/Button'
import { PageHeader } from '@/ui/components/PageHeader'
import { ErrorState } from '@/ui/components/States'
import { useToast } from '@/ui/components/Toast'
import { nextStep, type SetupChecklist, type SetupStep } from './setup'

const TAG: Record<SetupStep['status'], string> = { pending: 'wa', done: 'ok', skipped: 'in' }

/**
 * IMP-3 · Primeros pasos: lista de pasos de la puesta en marcha con su avance. Cada paso lleva a la pantalla
 * real; la API decide cuándo está hecho (por el dato, no por un clic). Se puede saltar, retomar y ocultar.
 */
export function SetupScreen() {
  const { t, locale } = useT()
  const { toast, node: toastNode } = useToast()
  const [data, setData] = useState<SetupChecklist | null>(null)
  const [failed, setFailed] = useState(false)
  const [busy, setBusy] = useState<string | null>(null)

  const load = useCallback(async () => {
    try {
      setData((await api.get<{ data: SetupChecklist }>('/setup-checklist')).data)
      setFailed(false)
    } catch {
      setFailed(true)
    }
  }, [])

  useEffect(() => {
    void load()
  }, [load])

  const act = async (key: string, path: string, body?: unknown) => {
    setBusy(key)
    try {
      setData((await api.post<{ data: SetupChecklist }>(path, body)).data)
    } catch {
      toast(t('setup.action_error'), 'error')
    } finally {
      setBusy(null)
    }
  }

  const next = data ? nextStep(data) : null

  return (
    <>
      <PageHeader
        title={t('setup.title')}
        sub={t('setup.sub')}
        actions={data ? (
          <Button loading={busy === 'dismiss'} onClick={() => void act('dismiss', '/setup-checklist/dismiss', { hidden: !data.dismissed })}>
            {data.dismissed ? t('setup.show') : t('setup.hide')}
          </Button>
        ) : undefined}
      />
      <main className="main" id="contenido" tabIndex={-1}>
        {failed && <ErrorState text={t('common.load_error')} retryLabel={t('common.retry')} onRetry={() => void load()} />}
        {!failed && data === null && <div className="card card-body esq-grupo">{[1, 2, 3].map((i) => <span key={i} className="esq" style={{ width: `${40 + i * 15}%` }} />)}</div>}
        {data && (
          <section className="card">
            <div className="card-body">
              <div style={{ display: 'flex', justifyContent: 'space-between', gap: 8, flexWrap: 'wrap', alignItems: 'baseline' }}>
                <h2 className="inicio-titulo" style={{ margin: 0 }}>{t('setup.progress', { n: data.progress })}</h2>
                <span className="sub2">{t('setup.counts', { done: data.done, skipped: data.skipped, total: data.total })}</span>
              </div>
              <div className="barra-avance" role="progressbar" aria-valuemin={0} aria-valuemax={100} aria-valuenow={data.progress} aria-label={t('setup.progress', { n: data.progress })}>
                <span style={{ width: `${data.progress}%` }} />
              </div>
              {data.completed && <p className="mb-0 mt-2">{t('setup.completed')}</p>}
              {!data.completed && next && (
                <p className="mb-0 mt-2">{t('setup.next')} <Link href={next.link}>{next.title}</Link></p>
              )}
              {data.dismissed && <p className="sub2 mb-0 mt-2">{t('setup.hidden_note')}</p>}
            </div>
            <ol className="setup-pasos">
              {data.steps.map((s, i) => (
                <li key={s.code} className={`setup-paso setup-${s.status}`}>
                  <span className="setup-num" aria-hidden="true">{s.status === 'done' ? '✓' : i + 1}</span>
                  <div className="setup-texto">
                    <div><Link href={s.link}><b>{s.title}</b></Link> <span className={`tag ${TAG[s.status]}`}>{t(`setup.status_${s.status}`)}</span></div>
                    <p className="sub2 mb-0">{s.description}</p>
                    {s.code === 'employees' && s.status === 'pending' && (
                      <p className="sub2 mb-0"><Link href="/configuracion/importaciones">{t('setup.import_hint')}</Link></p>
                    )}
                    {s.completed_at && s.status !== 'pending' && <p className="sub2 mb-0">{t(`setup.when_${s.status}`, { date: formatDate(locale, s.completed_at.slice(0, 10)) })}</p>}
                  </div>
                  <div className="setup-acciones">
                    {s.status === 'pending' && <Link className="btn btn-primary btn-sm" href={s.link}>{t('setup.go')}</Link>}
                    {s.allowed_actions.includes('skip') && (
                      <Button size="sm" variant="tertiary" loading={busy === s.code} onClick={() => void act(s.code, `/setup-checklist/${s.code}/skip`)}>{t('setup.skip')}</Button>
                    )}
                    {s.allowed_actions.includes('reopen') && (
                      <Button size="sm" loading={busy === s.code} onClick={() => void act(s.code, `/setup-checklist/${s.code}/reopen`)}>{t('setup.reopen')}</Button>
                    )}
                  </div>
                </li>
              ))}
            </ol>
          </section>
        )}
      </main>
      {toastNode}
    </>
  )
}
