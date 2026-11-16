'use client'

import Link from 'next/link'
import { useCallback, useEffect, useState } from 'react'
import { api } from '@/lib/api'
import { formatDate } from '@/lib/format'
import { useT } from '@/lib/useT'
import { Button } from '@/ui/components/Button'
import { Notice } from '@/ui/components/Notice'
import { PageHeader } from '@/ui/components/PageHeader'
import { EmptyState, ErrorState } from '@/ui/components/States'
import { useToast } from '@/ui/components/Toast'
import { packState, type CountryPack } from './setup'

/**
 * IMP-4 · Plantillas del país: el paquete declara qué crea cada parte y cuánto ya existe. Aplicarlo crea solo lo
 * que falta (no pisa lo editado) y cada parte lleva a la pantalla real donde se edita después.
 */
export function CountryPacksScreen() {
  const { t, locale } = useT()
  const { toast, node: toastNode } = useToast()
  const [packs, setPacks] = useState<CountryPack[] | null>(null)
  const [failed, setFailed] = useState(false)
  const [busy, setBusy] = useState(false)
  const [open, setOpen] = useState<string | null>(null)

  const load = useCallback(async () => {
    try {
      setPacks((await api.get<{ data: CountryPack[] }>('/setup/country-packs')).data)
      setFailed(false)
    } catch {
      setFailed(true)
    }
  }, [])

  useEffect(() => {
    void load()
  }, [load])

  const apply = async (pack: CountryPack) => {
    setBusy(true)
    try {
      const res = await api.post<{ data: { pack: CountryPack; created: Record<string, number> } }>(`/setup/country-packs/${pack.code}/apply`)
      const created = Object.values(res.data.created).reduce((a, b) => a + b, 0)
      setPacks((prev) => (prev ?? []).map((p) => (p.code === pack.code ? res.data.pack : p)))
      toast(created > 0 ? t('packs.applied', { n: created }) : t('packs.applied_nothing'))
    } catch {
      toast(t('packs.apply_error'), 'error')
    } finally {
      setBusy(false)
    }
  }

  return (
    <>
      <PageHeader title={t('packs.title')} sub={t('packs.sub')} />
      <main className="main" id="contenido" tabIndex={-1}>
        {failed && <ErrorState text={t('common.load_error')} retryLabel={t('common.retry')} onRetry={() => void load()} />}
        {!failed && packs === null && <div className="card card-body esq-grupo">{[1, 2, 3].map((i) => <span key={i} className="esq" style={{ width: `${40 + i * 15}%` }} />)}</div>}
        {!failed && packs?.length === 0 && <EmptyState title={t('packs.empty_title')} text={t('packs.empty_text')} />}
        {packs?.map((pack) => {
          const state = packState(pack)
          return (
            <section key={pack.code} className="card mb-3">
              <div className="card-body" style={{ display: 'flex', justifyContent: 'space-between', gap: 12, flexWrap: 'wrap' }}>
                <div>
                  <h2 className="inicio-titulo" style={{ margin: 0 }}>{pack.name}</h2>
                  <p className="sub2 mb-0">
                    {t('packs.version', { v: pack.version, date: formatDate(locale, pack.released_on) })}
                    {' · '}
                    {pack.installed_version
                      ? t('packs.installed', { v: pack.installed_version, date: formatDate(locale, (pack.installed_at ?? '').slice(0, 10)) })
                      : t('packs.not_installed')}
                  </p>
                </div>
                <Button variant={state === 'complete' ? 'secondary' : 'primary'} loading={busy} onClick={() => void apply(pack)}>
                  {t(`packs.action_${state}`)}
                </Button>
              </div>
              {pack.pending_validation.length > 0 && (
                <div className="card-body" style={{ paddingTop: 0 }}>
                  <Notice tone="warn" title={t('packs.pending_validation')}>
                    <ul className="mb-0">{pack.pending_validation.map((p) => <li key={p}>{p}</li>)}</ul>
                  </Notice>
                </div>
              )}
              <ul className="setup-pasos">
                {pack.components.map((c) => (
                  <li key={c.code} className="setup-paso">
                    <div className="setup-texto">
                      <div>
                        <b>{c.name}</b>{' '}
                        {!c.module_enabled
                          ? <span className="tag in">{t('packs.module_off')}</span>
                          : <span className={`tag ${c.present >= c.total ? 'ok' : 'wa'}`}>{t('packs.present', { present: c.present, total: c.total })}</span>}
                      </div>
                      <button type="button" className="btn btn-sm btn-ter" aria-expanded={open === c.code} onClick={() => setOpen(open === c.code ? null : c.code)}>
                        {open === c.code ? t('packs.hide_items') : t('packs.show_items', { n: c.total })}
                      </button>
                      {open === c.code && <ul className="sub2 mb-0">{c.items.map((item) => <li key={item}>{item}</li>)}</ul>}
                    </div>
                    <div className="setup-acciones">
                      <Link className="btn btn-sm btn-sec" href={c.link}>{t('packs.edit')}</Link>
                    </div>
                  </li>
                ))}
              </ul>
            </section>
          )
        })}
      </main>
      {toastNode}
    </>
  )
}
