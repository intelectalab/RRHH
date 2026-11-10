'use client'

import { useCallback, useEffect, useMemo, useState } from 'react'
import { api, ApiRequestError, type Schema } from '@/lib/api'
import { useT } from '@/lib/useT'
import { useSession } from '@/modules/session/SessionProvider'
import { Icon } from '@/ui/components/Icon'
import { PageHeader } from '@/ui/components/PageHeader'
import { useToast } from '@/ui/components/Toast'
import { settingsTabs } from './settingsTabs'

type Role = Schema<'Role'> & { code?: string; description?: string | null; version?: number }
type Permission = { code: string; module: string; module_name: string; resource: string; action: string; kind: 'action' | 'field'; sensitive: boolean; description: string | null }
type Scope = Role['scope']

const SCOPES: Scope[] = ['organization', 'legal_entity', 'site', 'unit', 'team', 'self']

export function RolesScreen() {
  const { t } = useT()
  const { can } = useSession()
  const { toast, node: toastNode } = useToast()
  const [roles, setRoles] = useState<Role[] | null>(null)
  const [catalog, setCatalog] = useState<Permission[]>([])
  const [selectedId, setSelectedId] = useState<string | null>(null)
  const [draft, setDraft] = useState<Role | null>(null)
  const [busy, setBusy] = useState(false)

  const load = useCallback(async (select?: string) => {
    const res = await api.get<{ data: Role[] }>('/roles?per_page=100')
    setRoles(res.data)
    const id = select ?? selectedId ?? res.data[0]?.id ?? null
    setSelectedId(id)
    setDraft(res.data.find((r) => r.id === id) ?? null)
  }, [selectedId])

  useEffect(() => {
    // Enlace desde la búsqueda global: /configuracion/roles?id=…
    void load(new URLSearchParams(window.location.search).get('id') ?? undefined)
    void api.get<{ data: Permission[] }>('/permissions').then((r) => setCatalog(r.data))
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  const byModule = useMemo(() => {
    const groups = new Map<string, Permission[]>()
    for (const p of catalog) {
      const list = groups.get(p.module_name) ?? []
      list.push(p)
      groups.set(p.module_name, list)
    }
    return [...groups.entries()]
  }, [catalog])

  const editable = draft !== null && !draft.system && can('identity.roles.update')

  function toggle(code: string, kind: 'action' | 'field', checked: boolean) {
    if (!draft) return
    const key = kind === 'action' ? 'permissions' : 'field_permissions'
    const current = new Set(draft[key])
    if (checked) current.add(code)
    else current.delete(code)
    setDraft({ ...draft, [key]: [...current].sort() })
  }

  async function run(fn: () => Promise<string | undefined>, doneKey: string) {
    setBusy(true)
    try {
      const select = await fn()
      toast(t(doneKey))
      await load(select)
    } catch (e) {
      toast(e instanceof ApiRequestError ? (e.details[0]?.message ?? e.message) : t('auth.generic_error'), 'error')
    } finally {
      setBusy(false)
    }
  }

  const save = () => run(async () => {
    if (!draft) return undefined
    await api.request(`/roles/${draft.id}`, 'PATCH', {
      name: draft.name, scope: draft.scope, permissions: draft.permissions, field_permissions: draft.field_permissions,
    }, draft.version !== undefined ? { 'If-Match': `"${draft.version}"` } : undefined)
    return draft.id
  }, 'roles.saved')

  const duplicate = () => run(async () => {
    if (!draft) return undefined
    const res = await api.post<{ data: Role }>(`/roles/${draft.id}/duplicate`, {})
    return res.data.id
  }, 'roles.duplicated')

  const remove = () => {
    if (!draft || !window.confirm(t('roles.confirm_delete', { name: draft.name }))) return
    void run(async () => {
      await api.request(`/roles/${draft.id}`, 'DELETE')
      setSelectedId(null)
      return undefined
    }, 'roles.deleted')
  }

  const create = () => run(async () => {
    const res = await api.post<{ data: Role }>('/roles', { name: t('roles.new'), permissions: [], scope: 'organization' })
    return res.data.id
  }, 'roles.created')

  return (
    <>
      <PageHeader
        title={t('settings.title')}
        sub={roles ? t('roles.sub', { count: roles.length }) : undefined}
        tabs={settingsTabs(t, can, 'roles')}
        actions={can('identity.roles.create') && (
          <button type="button" className="btn btn-primary" onClick={() => void create()} disabled={busy}>
            <Icon name="plus" /> {t('roles.new')}
          </button>
        )}
      />
      <main className="main" id="contenido" tabIndex={-1}>
        <div className="grid-roles">
          <section className="card">
            <ul className="lista">
              {roles === null && <li className="card-body"><span className="esq" /></li>}
              {roles?.map((r) => (
                <li key={r.id}>
                  <button type="button" className={r.id === selectedId ? 'on' : undefined} aria-current={r.id === selectedId ? 'true' : undefined} onClick={() => { setSelectedId(r.id); setDraft(r) }}>
                    <span className="min-w-0">
                      <b>{r.name}</b>
                      <span className="sub2">{t('roles.granted', { count: r.permissions.length + r.field_permissions.length })}</span>
                    </span>
                    <span className={`tag ${r.system ? 'pe' : 'gr'}`}>{r.system ? t('roles.system') : t('roles.custom')}</span>
                  </button>
                </li>
              ))}
            </ul>
          </section>

          <section className="card">
            {draft === null ? (
              <div className="vacio">{t('roles.select')}</div>
            ) : (
              <>
                <div className="card-header">
                  <div className="min-w-0" style={{ flex: 1 }}>
                    {editable ? (
                      <input className="form-control" aria-label={t('roles.name')} value={draft.name} onChange={(e) => setDraft({ ...draft, name: e.target.value })} />
                    ) : (
                      <h2>{draft.name}</h2>
                    )}
                  </div>
                  <div className="acc" style={{ display: 'flex', gap: 8 }}>
                    {can('identity.roles.duplicate') && <button type="button" className="btn btn-sec btn-sm" onClick={() => void duplicate()} disabled={busy}>{t('roles.duplicate')}</button>}
                    {!draft.system && can('identity.roles.delete') && <button type="button" className="btn btn-sec btn-sm" onClick={remove} disabled={busy}>{t('roles.delete')}</button>}
                    {editable && <button type="button" className="btn btn-primary btn-sm" onClick={() => void save()} disabled={busy}>{t('roles.save')}</button>}
                  </div>
                </div>
                <div className="card-body">
                  {draft.system && <p className="aviso-ok mb-3" style={{ background: 'var(--in-50)', color: 'var(--in)' }}>{t('roles.system_note')}</p>}
                  <label className="form-label" htmlFor="r-scope">{t('roles.scope')}</label>
                  <select id="r-scope" className="form-select" style={{ maxWidth: 320 }} disabled={!editable} value={draft.scope} onChange={(e) => setDraft({ ...draft, scope: e.target.value as Scope })}>
                    {SCOPES.map((s) => <option key={s} value={s}>{t(`roles.scope_${s}`)}</option>)}
                  </select>
                </div>
                {byModule.map(([module, perms]) => (
                  <div key={module} className="modulo-permisos">
                    <h3>{module}</h3>
                    <div className="permisos">
                      {perms.map((p) => {
                        const checked = (p.kind === 'action' ? draft.permissions : draft.field_permissions).includes(p.code)
                        return (
                          <label key={p.code} className="check">
                            <input type="checkbox" disabled={!editable} checked={checked} onChange={(e) => toggle(p.code, p.kind, e.target.checked)} />
                            <span>
                              {p.description ?? p.code} {p.sensitive && <span className="tag wa">{t('roles.sensitive')}</span>}
                              <code>{p.code}</code>
                            </span>
                          </label>
                        )
                      })}
                    </div>
                  </div>
                ))}
              </>
            )}
          </section>
        </div>
      </main>
      {toastNode}
    </>
  )
}
