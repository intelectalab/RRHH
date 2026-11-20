'use client'

import Link from 'next/link'
import { useEffect, useMemo, useState } from 'react'
import { api } from '@/lib/api'
import { useT } from '@/lib/useT'
import { useSession } from '@/modules/session/SessionProvider'
import { Icon } from '@/ui/components/Icon'
import { PageHeader } from '@/ui/components/PageHeader'

type Group = { group: string; module: string; count: number; parameters: Array<{ code: string; name: string; description: string | null }> }
type Card = { href: string; title: string; help: string; keywords?: string }

/**
 * Centro de configuración (CFG-1): secciones según permiso y módulos contratados, con buscador de
 * ajustes. Las secciones de parámetros las aporta cada módulo desde el catálogo (GET /settings).
 */
export function SettingsHome() {
  const { t } = useT()
  const { session, can } = useSession()
  const [groups, setGroups] = useState<Group[]>([])
  const [q, setQ] = useState('')

  useEffect(() => {
    if (can('settings.parameters.view')) void api.get<{ data: Group[] }>('/settings').then((r) => setGroups(r.data)).catch(() => undefined)
  }, [can])

  const sections = useMemo(() => {
    const card = (href: string, key: string, show: boolean): Card | null => (show ? { href, title: t(`cfg.card_${key}`), help: t(`cfg.card_${key}_help`) } : null)
    const clean = (xs: Array<Card | null>) => xs.filter((x): x is Card => x !== null)
    const groupCard = (g: Group): Card => ({
      href: `/configuracion/parametros/${g.group}`,
      title: t(`cfgroup.${g.group}`) === `cfgroup.${g.group}` ? g.group : t(`cfgroup.${g.group}`),
      help: t('cfg.params_count', { n: g.count }),
      keywords: g.parameters.map((p) => `${p.name} ${p.description ?? ''}`).join(' '),
    })
    return [
      { key: 'structure', cards: clean([card('/organizacion/empresas', 'companies', can('org.legal_entities.view')), card('/configuracion/feriados', 'holidays', can('time.holiday_calendars.view')), card('/configuracion/campos', 'fields', can('settings.custom_fields.view')), card('/configuracion/monedas', 'money', can('settings.exchange_rates.view')), card('/configuracion/tipos-contrato', 'contract_types', session.modules.includes('core') && can('contracts.contract_types.view')), card('/configuracion/tipos-documentales', 'document_types', session.modules.includes('core') && can('documents.document_types.update')), card('/configuracion/tipos-ausencia', 'absence_types', session.modules.includes('time_off') && can('time.absence_types.view')), card('/configuracion/politicas-vacaciones', 'vacation_policies', session.modules.includes('time_off') && can('time.vacation_policies.view')), card('/configuracion/catalogos', 'catalogs', can('settings.catalogs.update') || can('settings.parameters.view')), card('/configuracion/importaciones', 'imports', session.modules.includes('core') && can('imports.imports.view')), card('/configuracion/paquetes-pais', 'country_packs', session.modules.includes('core') && can('imports.setup.use')), ...groups.filter((g) => ['org', 'importacion'].includes(g.group)).map(groupCard)]) },
      { key: 'access', cards: clean([card('/configuracion/usuarios', 'users', can('identity.users.view')), card('/configuracion/roles', 'roles', can('identity.roles.view')), ...groups.filter((g) => g.group === 'seguridad').map(groupCard)]) },
      { key: 'processes', cards: clean([card('/configuracion/flujos', 'flows', can('workflow.approval_flows.view')), card('/configuracion/automatizaciones', 'automations', can('workflow.workflows.view')), card('/configuracion/mensajes', 'messages', can('notifications.templates.view') || can('notifications.deliveries.view')), card('/configuracion/ayuda', 'help', session.modules.includes('core') && can('help.org_articles.view')), ...groups.filter((g) => ['aprobaciones', 'automatizaciones', 'notificaciones'].includes(g.group)).map(groupCard)]) },
      { key: 'control', cards: clean([card('/configuracion/auditoria', 'audit', can('audit.logs.view')), card('/configuracion/accesos-sensibles', 'sensitive', can('audit.sensitive_access.view')), card('/configuracion/actividad', 'activity', can('audit.activity.view')), card('/configuracion/integridad', 'integrity', can('audit.logs.view')), card('/configuracion/papelera', 'trash', can('audit.trash.view')), card('/configuracion/consumo', 'usage', can('settings.organization.view')), ...groups.filter((g) => ['papelera', 'privacidad', 'limites'].includes(g.group)).map(groupCard)]) },
      { key: 'parameters', cards: groups.filter((g) => !['org', 'importacion', 'seguridad', 'aprobaciones', 'automatizaciones', 'notificaciones', 'papelera', 'privacidad'].includes(g.group)).map(groupCard).sort((a, b) => a.title.localeCompare(b.title)) },
    ]
  }, [groups, can, t, session.modules])

  const norm = (s: string) => s.normalize('NFD').replace(/\p{Diacritic}/gu, '').toLowerCase()
  const term = norm(q.trim())
  const filtered = sections.map((s) => ({ ...s, cards: term ? s.cards.filter((c) => norm(`${c.title} ${c.help} ${c.keywords ?? ''}`).includes(term)) : s.cards })).filter((s) => s.cards.length > 0)
  // Al buscar, se muestran también los parámetros que coinciden para ir directo a ellos.
  const hits = term ? groups.flatMap((g) => g.parameters.filter((p) => norm(`${p.name} ${p.description ?? ''}`).includes(term)).map((p) => ({ ...p, group: g.group }))).slice(0, 12) : []

  return (
    <>
      <PageHeader title={t('cfg.title')} sub={t('cfg.sub')} />
      <main className="main" id="contenido" tabIndex={-1}>
        <div className="cfg-buscar">
          <Icon name="search" />
          <input type="search" className="form-control" placeholder={t('cfg.search')} aria-label={t('cfg.search_aria')} value={q} onChange={(e) => setQ(e.target.value)} />
        </div>
        {hits.length > 0 && (
          <section className="card" style={{ marginBottom: 24 }}>
            <ul className="lista">
              {hits.map((h) => (
                <li key={h.code}><Link className="cfg-hit" href={`/configuracion/parametros/${h.group}#${h.code}`}><b>{h.name}</b><span className="sub2">{t(`cfgroup.${h.group}`)}{h.description ? ` · ${h.description}` : ''}</span></Link></li>
              ))}
            </ul>
          </section>
        )}
        {filtered.length === 0 && <p className="sub2">{t('cfg.no_results', { q })}</p>}
        {filtered.map((s) => (
          <section key={s.key} className="cfg-seccion">
            <h2>{t(`cfg.sec_${s.key}`)}</h2>
            <div className="cfg-grid">
              {s.cards.map((c) => (
                <Link key={c.href} href={c.href} className="cfg-card">
                  <b>{c.title}</b>
                  <span className="sub2">{c.help}</span>
                </Link>
              ))}
            </div>
          </section>
        ))}
      </main>
    </>
  )
}
