'use client'

import { useEffect, useState } from 'react'
import { api } from '@/lib/api'
import { useT } from '@/lib/useT'
import { settingsTabs } from '@/modules/identity/settingsTabs'
import { useSession } from '@/modules/session/SessionProvider'
import { PageHeader } from '@/ui/components/PageHeader'
import { useDateTime } from '@/modules/session/useDateTime'

type Access = { id: string; actor_name: string | null; entity: string; entity_id: string; fields: string[]; category: string; channel: string; occurred_at: string }

/** Configuración → Accesos sensibles: lecturas de datos sensibles (regla 7). */
export function SensitiveAccessScreen() {
  const { t, locale } = useT()
  const fmtDT = useDateTime()
  const { can } = useSession()
  const [rows, setRows] = useState<Access[] | null>(null)

  useEffect(() => {
    void api.get<{ data: Access[] }>('/sensitive-access-logs?per_page=100').then((r) => setRows(r.data))
  }, [])

  const fmt = (iso: string) => fmtDT(iso, true)

  return (
    <>
      <PageHeader title={t('settings.title')} sub={t('audit.sens_sub')} tabs={settingsTabs(t, can, 'sensitive')} />
      <main className="main" id="contenido" tabIndex={-1}>
        <section className="card">
          <div className="tabla-wrap">
            <table className="table">
              <thead><tr><th>{t('audit.col_when')}</th><th>{t('audit.col_user')}</th><th>{t('audit.sens_category')}</th><th>{t('audit.col_entity')}</th><th>{t('audit.sens_fields')}</th><th>{t('audit.sens_channel')}</th></tr></thead>
              <tbody>
                {rows === null && <tr><td colSpan={6}><span className="esq" /></td></tr>}
                {rows?.length === 0 && <tr><td colSpan={6}><div className="vacio">{t('audit.sens_empty')}</div></td></tr>}
                {rows?.map((r) => (
                  <tr key={r.id}>
                    <td className="nowrap">{fmt(r.occurred_at)}</td>
                    <td>{r.actor_name ?? t('audit.system')}</td>
                    <td><span className="tag wa">{t(`audit.cat_${r.category}`)}</span></td>
                    <td>{r.entity}<span className="sub2">{r.entity_id.slice(0, 8)}</span></td>
                    <td className="small">{r.fields.join(', ')}</td>
                    <td className="small">{r.channel}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </section>
      </main>
    </>
  )
}
