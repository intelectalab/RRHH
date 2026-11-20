'use client'

import { useCallback, useEffect, useState } from 'react'
import { api, ApiRequestError } from '@/lib/api'
import { useT } from '@/lib/useT'
import { PageHeader } from '@/ui/components/PageHeader'
import { ErrorState } from '@/ui/components/States'
import { useToast } from '@/ui/components/Toast'
import { PushDevicesPanel } from './PushDevicesPanel'
import { categoryLabel } from './shared'

type Pref = { category: string; mandatory: boolean; urgent: boolean; channels: Record<string, { enabled: boolean; locked: boolean }> }
type Prefs = { channels: string[]; categories: Pref[]; quiet_hours: { from: string | null; to: string | null } }

/** Preferencias de notificación del usuario (NOT-6): tipo de aviso × canal. */
export function PreferencesScreen() {
  const { t } = useT()
  const { toast, node: toastNode } = useToast()
  const [prefs, setPrefs] = useState<Prefs | null>(null)
  const [loadError, setLoadError] = useState(false)

  const load = useCallback(async () => {
    try {
      setPrefs((await api.get<{ data: Prefs }>('/notification-preferences')).data)
      setLoadError(false)
    } catch {
      setLoadError(true)
    }
  }, [])
  useEffect(() => { void load() }, [load])

  async function toggle(category: string, channel: string, enabled: boolean) {
    try {
      const res = await api.request<{ data: Prefs }>('/notification-preferences', 'PUT', { preferences: [{ category, channel, enabled }] })
      setPrefs(res.data)
      toast(t('prefs.saved'))
    } catch (e) {
      toast(e instanceof ApiRequestError ? e.message : t('auth.generic_error'), 'error')
    }
  }

  return (
    <>
      <PageHeader title={t('prefs.title')} sub={t('prefs.sub')} />
      <main className="main" id="contenido" tabIndex={-1}>
        {loadError && <ErrorState text={t('common.load_error')} retryLabel={t('common.retry')} onRetry={() => void load()} />}
        {prefs && (
          <div className="grid-2">
            <section className="card">
              <div className="tabla-wrap">
                <table className="table">
                  <thead><tr><th>{t('prefs.col_type')}</th>{prefs.channels.map((c) => <th key={c} className="text-center">{t(`nchannel.${c}`)}</th>)}</tr></thead>
                  <tbody>
                    {prefs.categories.map((p) => (
                      <tr key={p.category}>
                        <td>
                          <b>{categoryLabel(t, p.category)}</b>
                          {(p.mandatory || p.urgent) && (
                            <div className="tags" style={{ marginTop: 4 }}>
                              {p.mandatory && <span className="tag pe">{t('prefs.mandatory')}</span>}
                              {p.urgent && <span className="tag wa">{t('prefs.urgent')}</span>}
                            </div>
                          )}
                        </td>
                        {prefs.channels.map((c) => {
                          const ch = p.channels[c]
                          return (
                            <td key={c} className="text-center">
                              <input type="checkbox" className="switch" role="switch" checked={ch?.enabled ?? false} disabled={ch?.locked}
                                aria-label={t('prefs.switch_aria', { type: categoryLabel(t, p.category), channel: t(`nchannel.${c}`) })}
                                onChange={(e) => void toggle(p.category, c, e.target.checked)} />
                            </td>
                          )
                        })}
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </section>
            <div style={{ display: 'grid', gap: 16, alignContent: 'start' }}>
            <PushDevicesPanel onChange={() => void load()} toast={toast} />
            <section className="bloque-plano">
              <div className="cab"><h2>{t('prefs.quiet_title')}</h2></div>
              <p className="sub2">{prefs.quiet_hours.from && prefs.quiet_hours.to ? t('prefs.quiet_text', { from: prefs.quiet_hours.from, to: prefs.quiet_hours.to }) : t('prefs.quiet_none')}</p>
              <p className="sub2">{t('prefs.mandatory_help')}</p>
            </section>
            </div>
          </div>
        )}
      </main>
      {toastNode}
    </>
  )
}
