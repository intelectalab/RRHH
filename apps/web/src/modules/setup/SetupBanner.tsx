'use client'

import Link from 'next/link'
import { useEffect, useState } from 'react'
import { api } from '@/lib/api'
import { useT } from '@/lib/useT'
import { useSession } from '@/modules/session/SessionProvider'
import { nextStep, type SetupChecklist } from './setup'

/**
 * IMP-3 · Aviso de primeros pasos en el inicio del administrador: avance y siguiente paso. Desaparece cuando la
 * puesta en marcha termina o la organización decide omitirla (lo decide la API con visible).
 */
export function SetupBanner() {
  const { t } = useT()
  const { can, session } = useSession()
  const [data, setData] = useState<SetupChecklist | null>(null)
  const allowed = can('imports.setup.use') && session.modules.includes('core')

  useEffect(() => {
    if (!allowed) return
    let alive = true
    api.get<{ data: SetupChecklist }>('/setup-checklist')
      .then((res) => { if (alive) setData(res.data) })
      .catch(() => { /* sin aviso: el asistente sigue en Primeros pasos */ })
    return () => { alive = false }
  }, [allowed])

  if (!allowed || !data?.visible) return null
  const next = nextStep(data)

  return (
    <section className="card mb-3 setup-aviso" aria-labelledby="setup-aviso-titulo">
      <div className="card-body" style={{ display: 'flex', gap: 16, flexWrap: 'wrap', alignItems: 'center', justifyContent: 'space-between' }}>
        <div style={{ flex: '1 1 260px' }}>
          <h2 id="setup-aviso-titulo" className="inicio-titulo" style={{ margin: 0 }}>{t('setup.banner_title', { n: data.progress })}</h2>
          {next && <p className="sub2 mb-0">{t('setup.next')} {next.title}</p>}
          <div className="barra-avance" aria-hidden="true"><span style={{ width: `${data.progress}%` }} /></div>
        </div>
        <div style={{ display: 'flex', gap: 8 }}>
          {next && <Link className="btn btn-primary btn-sm" href={next.link}>{t('setup.go')}</Link>}
          <Link className="btn btn-sec btn-sm" href="/primeros-pasos">{t('setup.see_all')}</Link>
        </div>
      </div>
    </section>
  )
}
