'use client'

import { useCallback, useEffect, useMemo, useState, type FormEvent } from 'react'
import { api, ApiRequestError, type Schema } from '@/lib/api'
import { useT } from '@/lib/useT'
import { useSession } from '@/modules/session/SessionProvider'
import { Icon } from '@/ui/components/Icon'
import { PageHeader } from '@/ui/components/PageHeader'
import { SidePanel } from '@/ui/components/SidePanel'
import { ActivationCodePanel } from './ActivationCodePanel'
import { identityPayload, LoginIdentityFields, type LoginIdentity } from './LoginIdentityFields'
import { EmptyState } from '@/ui/components/States'
import { useToast } from '@/ui/components/Toast'
import { formErrors } from '@/lib/errors'
import { useCompany } from '@/modules/session/CompanyProvider'
import { ExportButton } from '@/modules/exports/ExportButton'
import { DataTable, type BulkAction, type TableColumn, type TableFilter } from '@/ui/components/DataTable'
import { settingsTabs } from './settingsTabs'
import { useDateTime } from '@/modules/session/useDateTime'

type Assignment = { role_id: string; scope: string | null; legal_entity_ids: string[]; site_ids: string[]; org_unit_ids: string[] }
type User = Schema<'User'> & { allowed_actions?: string[]; role_assignments?: Assignment[]; version?: number }
type Role = Schema<'Role'> & { code?: string }

const STATUS_CLASS: Record<string, string> = { invited: 'in', active: 'ok', locked: 'wa', alumni: 'gr', disabled: 'gr' }

export function UsersScreen() {
  const { t, locale } = useT()
  const fmtDT = useDateTime()
  const { session, can } = useSession()
  const { toast, node: toastNode } = useToast()
  const [roles, setRoles] = useState<Role[]>([])
  const [creating, setCreating] = useState(false)
  const [suspending, setSuspending] = useState<User | null>(null)
  const [revoking, setRevoking] = useState<User | null>(null)
  const [resettingMfa, setResettingMfa] = useState<User | null>(null)
  const [codeFor, setCodeFor] = useState<User | null>(null)
  const [editingUser, setEditingUser] = useState<User | null>(null)
  const [reloadKey, setReloadKey] = useState(0)
  const [counts, setCounts] = useState<{ active: number; total: number } | null>(null)

  // UI-4: la tabla pide sus páginas al servidor; recargar es volver a pedir la página y los conteos.
  const load = useCallback(async () => {
    setReloadKey((k) => k + 1)
  }, [])

  useEffect(() => {
    void Promise.all([
      api.get<{ meta?: { total?: number | null } }>('/users?per_page=1'),
      api.get<{ meta?: { total?: number | null } }>('/users?per_page=1&filter[status]=active'),
    ]).then(([all, act]) => setCounts({ total: all.meta?.total ?? 0, active: act.meta?.total ?? 0 })).catch(() => setCounts(null))
  }, [reloadKey])

  useEffect(() => {
    if (can('identity.roles.view')) void api.get<{ data: Role[] }>('/roles?per_page=100').then((r) => setRoles(r.data))
  }, [can])

  const roleName = useMemo(() => new Map(roles.map((r) => [r.id, r.name])), [roles])

  async function act(user: User, action: 'invite' | 'reactivate' | 'reset-password', doneKey: string) {
    try {
      await api.post(`/users/${user.id}/${action}`, {})
      toast(t(doneKey))
      await load()
    } catch (e) {
      toast(formErrors(e, t).general ?? t('auth.generic_error'), 'error')
    }
  }

  const fmt = (iso: string | null | undefined) =>
    iso ? fmtDT(iso) : null

  const columns: Array<TableColumn<User>> = [
    {
      key: 'user', label: t('users.col_user'), sort: 'display_name', hideable: false, width: 320,
      render: (u) => (
        <div className="who">
          <div className="min-w-0">
            <b>{u.display_name}</b> {u.id === session.user.id && <span className="tag in">{t('users.you')}</span>} {u.mfa_enabled && <span className="tag ok" title={t('users.mfa_on_title')}>{t('users.mfa_on')}</span>}
            <span className="sub2">{[u.login, u.email && u.email !== u.login ? u.email : null, u.document_number_masked ? `${t(`auth.doc_${u.document_type ?? 'other'}`)} ${u.document_number_masked}` : null].filter(Boolean).join(' · ')}</span>
          </div>
        </div>
      ),
    },
    { key: 'roles', label: t('users.col_roles'), render: (u) => u.role_ids.map((id) => roleName.get(id)).filter(Boolean).join(', ') || '—' },
    { key: 'last_login_at', label: t('users.col_last'), sort: 'last_login_at', render: (u) => fmt(u.last_login_at) ?? <span className="sub2">{t('users.never')}</span> },
    { key: 'status', label: t('users.col_status'), sort: 'status', render: (u) => <span className={`st ${STATUS_CLASS[u.status] ?? 'gr'}`}>{t(`users.status_${u.status}`)}</span> },
    { key: 'created_at', label: t('users.col_created'), sort: 'created_at', hiddenByDefault: true, render: (u) => fmt(u.created_at) ?? '—' },
  ]

  const tableFilters: TableFilter[] = [
    { key: 'status', label: t('users.filter_status'), options: ['invited', 'active', 'locked', 'alumni', 'disabled'].map((s) => ({ value: s, label: t(`users.status_${s}`) })) },
    ...(roles.length > 0 ? [{ key: 'role_id', label: t('users.filter_role'), options: roles.map((r) => ({ value: r.id, label: r.name })) }] : []),
  ]

  // Acción masiva con operación real detrás (RN-UI-01): reenviar la invitación a los invitados con correo.
  const bulkActions: Array<BulkAction<User>> = can('identity.users.invite') ? [{
    key: 'resend',
    label: t('users.bulk_resend'),
    applies: (u) => (u.allowed_actions ?? []).includes('invite') && Boolean(u.email),
    run: async (rows) => {
      let done = 0
      for (const u of rows) {
        try { await api.post(`/users/${u.id}/invite`, {}); done++ } catch { /* se informa en el total */ }
      }
      await load()
      return done
    },
  }] : []

  return (
    <>
      <PageHeader
        title={t('settings.title')}
        sub={counts ? t('users.sub', counts) : undefined}
        tabs={settingsTabs(t, can, 'users')}
        actions={(
          <>
            {can('identity.users.create') && (
              <button type="button" className="btn btn-primary" onClick={() => setCreating(true)}>
                <Icon name="plus" /> {t('users.new')}
              </button>
            )}
          </>
        )}
      />
      <main className="main" id="contenido" tabIndex={-1}>
        <DataTable<User>
          resource="users"
          endpoint="/users"
          urlSearch
          defaultSort="display_name"
          searchLabel={t('users.search')}
          reloadKey={reloadKey}
          rowKey={(u) => u.id}
          columns={columns}
          filters={tableFilters}
          bulkActions={bulkActions}
          toolbar={(state) => <ExportButton resource="users" filters={{ q: state.q.trim(), status: state.filters.status ?? '' }} onError={(m) => toast(m, 'error')} />}
          empty={(
            <EmptyState title={t('users.empty_title')} text={t('users.empty_text')} action={can('identity.users.create') && (
              <button type="button" className="btn btn-primary btn-sm" onClick={() => setCreating(true)}><Icon name="plus" /> {t('users.new')}</button>
            )} />
          )}
          rowActions={(u) => {
            const actions = u.allowed_actions ?? []
            const self = u.id === session.user.id
            return (
              <>
                {can('identity.users.update') && u.status !== 'disabled' && (
                  <button type="button" className="btn btn-ter btn-sm" onClick={() => setEditingUser(u)}>{t('users.edit')}</button>
                )}
                {u.status === 'invited' && u.document_number_masked && can('identity.users.invite') && (
                  <button type="button" className="btn btn-ter btn-sm" onClick={() => setCodeFor(u)}>{t('users.action_activation_code')}</button>
                )}
                {actions.includes('invite') && u.email && can('identity.users.invite') && (
                  <button type="button" className="btn btn-ter btn-sm" onClick={() => void act(u, 'invite', 'users.done_invited')}>{t('users.action_resend')}</button>
                )}
                {u.status === 'active' && u.email && can('identity.users.reset_password') && !self && (
                  <button type="button" className="btn btn-ter btn-sm" onClick={() => void act(u, 'reset-password', 'users.done_reset')}>{t('users.action_reset_password')}</button>
                )}
                {u.status === 'active' && can('identity.users.suspend') && !self && (
                  <button type="button" className="btn btn-ter btn-sm" onClick={() => setRevoking(u)}>{t('users.action_revoke_sessions')}</button>
                )}
                {u.mfa_enabled && (u.status === 'active' || u.status === 'locked') && can('identity.users.reset_mfa') && !self && (
                  <button type="button" className="btn btn-ter btn-sm" onClick={() => setResettingMfa(u)}>{t('users.action_reset_mfa')}</button>
                )}
                {actions.includes('suspend') && can('identity.users.suspend') && !self && (
                  <button type="button" className="btn btn-ter btn-sm" onClick={() => setSuspending(u)}>{t('users.action_suspend')}</button>
                )}
                {actions.includes('reactivate') && can('identity.users.reactivate') && (
                  <button type="button" className="btn btn-ter btn-sm" onClick={() => void act(u, 'reactivate', 'users.done_reactivated')}>{t('users.action_reactivate')}</button>
                )}
              </>
            )
          }}
        />
      </main>
      {creating && (
        <NewUserPanel
          roles={roles}
          onClose={() => setCreating(false)}
          onCreated={async () => {
            setCreating(false)
            toast(t('users.done_created'))
            await load()
          }}
        />
      )}
      {editingUser && (
        <EditUserPanel
          user={editingUser}
          roles={roles}
          onClose={() => setEditingUser(null)}
          onSaved={async () => {
            setEditingUser(null)
            toast(t('users.done_saved'))
            await load()
          }}
        />
      )}
      {revoking && (
        <SuspendPanel
          mode="revoke"
          user={revoking}
          onClose={() => setRevoking(null)}
          onDone={async () => {
            setRevoking(null)
            toast(t('users.done_sessions_revoked'))
          }}
        />
      )}
      {codeFor && (
        <ActivationCodePanel
          user={codeFor}
          onClose={() => setCodeFor(null)}
          onSent={(msg) => { setCodeFor(null); toast(msg) }}
        />
      )}
      {resettingMfa && (
        <SuspendPanel
          mode="reset-mfa"
          user={resettingMfa}
          onClose={() => setResettingMfa(null)}
          onDone={async () => {
            setResettingMfa(null)
            toast(t('users.done_mfa_reset'))
            await load()
          }}
        />
      )}
      {suspending && (
        <SuspendPanel
          user={suspending}
          onClose={() => setSuspending(null)}
          onDone={async () => {
            setSuspending(null)
            toast(t('users.done_suspended'))
            await load()
          }}
        />
      )}
      {toastNode}
    </>
  )
}

function NewUserPanel({ roles, onClose, onCreated }: { roles: Role[]; onClose: () => void; onCreated: () => Promise<void> }) {
  const { t } = useT()
  const [login, setLogin] = useState('')
  const [displayName, setDisplayName] = useState('')
  const [email, setEmail] = useState('')
  const [identity, setIdentity] = useState<LoginIdentity>({ docType: 'dni', docNumber: '', phone: '' })
  const [roleIds, setRoleIds] = useState<string[]>([])
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  async function submit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault()
    setBusy(true)
    setError(null)
    try {
      await api.post('/users', { login: login || undefined, display_name: displayName || undefined, email: email || null, ...identityPayload(identity), role_ids: roleIds })
      await onCreated()
    } catch (err) {
      setError(Object.values(formErrors(err, t).fields)[0] ?? formErrors(err, t).general)
    } finally {
      setBusy(false)
    }
  }

  return (
    <SidePanel
      title={t('users.form_title')}
      onClose={onClose}
      closeLabel={t('common.close')}
      footer={<>
        <button type="button" className="btn btn-sec" onClick={onClose}>{t('users.cancel')}</button>
        <button type="submit" form="nuevo-usuario" className="btn btn-primary" disabled={busy}>{busy && <span className="spin" aria-hidden="true" />}{t('users.create')}</button>
      </>}
    >
      <form id="nuevo-usuario" onSubmit={submit} noValidate>
        <label className="form-label" htmlFor="u-login">{t('users.login_optional')}</label>
        <input id="u-login" className="form-control mb-2" value={login} onChange={(e) => setLogin(e.target.value)} autoComplete="off" />
        <p className="small text-secondary mb-3">{t('users.login_help')}</p>
        <label className="form-label" htmlFor="u-name">{t('users.display_name')}</label>
        <input id="u-name" className="form-control mb-3" value={displayName} onChange={(e) => setDisplayName(e.target.value)} />
        <label className="form-label" htmlFor="u-email">{t('users.email')} <span className="text-secondary">{t('users.email_optional')}</span></label>
        <input id="u-email" className="form-control mb-2" type="email" value={email} onChange={(e) => setEmail(e.target.value)} />
        <p className="small text-secondary mb-3">{t('users.email_help')}</p>
        <LoginIdentityFields idPrefix="u" value={identity} onChange={setIdentity} />
        <fieldset style={{ border: 0, padding: 0, margin: 0 }}>
          <legend className="form-label">{t('users.roles')}</legend>
          {roles.map((r) => (
            <label key={r.id} className="check mb-2">
              <input type="checkbox" checked={roleIds.includes(r.id)} onChange={(e) => setRoleIds((ids) => (e.target.checked ? [...ids, r.id] : ids.filter((id) => id !== r.id)))} />
              <span>{r.name}</span>
            </label>
          ))}
        </fieldset>
        {error && <div className="invalid-feedback" role="alert">{error}</div>}
      </form>
    </SidePanel>
  )
}

function SuspendPanel({ user, onClose, onDone, mode = 'suspend' }: { user: User; onClose: () => void; onDone: () => Promise<void>; mode?: 'suspend' | 'revoke' | 'reset-mfa' }) {
  const { t } = useT()
  const [reason, setReason] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  async function submit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault()
    setBusy(true)
    setError(null)
    try {
      await api.post(`/users/${user.id}/${mode === 'revoke' ? 'revoke-sessions' : mode}`, { reason })
      await onDone()
    } catch (err) {
      setError(Object.values(formErrors(err, t).fields)[0] ?? formErrors(err, t).general)
    } finally {
      setBusy(false)
    }
  }

  return (
    <SidePanel
      title={t(`users.${mode.replace('-', '_')}_title`, { name: user.display_name })}
      onClose={onClose}
      closeLabel={t('common.close')}
      footer={<>
        <button type="button" className="btn btn-sec" onClick={onClose}>{t('users.cancel')}</button>
        <button type="submit" form="bloquear-usuario" className="btn btn-primary" disabled={busy}>{t(`users.confirm_${mode.replace('-', '_')}`)}</button>
      </>}
    >
      <form id="bloquear-usuario" onSubmit={submit} noValidate>
        <p className="text-secondary mb-3">{t(`users.${mode.replace('-', '_')}_text`)}</p>
        <label className="form-label" htmlFor="s-reason">{t('users.reason')}</label>
        <textarea id="s-reason" className="form-control mb-2" rows={3} required value={reason} onChange={(e) => setReason(e.target.value)} />
        {error && <div className="invalid-feedback" role="alert">{error}</div>}
      </form>
    </SidePanel>
  )
}

const SCOPES = ['organization', 'legal_entity', 'team', 'self'] as const

function EditUserPanel({ user, roles, onClose, onSaved }: { user: User; roles: Role[]; onClose: () => void; onSaved: () => Promise<void> }) {
  const { t } = useT()
  const { companies } = useCompany()
  const [displayName, setDisplayName] = useState(user.display_name)
  const [email, setEmail] = useState(user.email ?? '')
  const [identity, setIdentity] = useState<LoginIdentity>({ docType: (user.document_type ?? 'dni') as LoginIdentity['docType'], docNumber: '', phone: '' })
  const [assignments, setAssignments] = useState<Assignment[]>(
    user.role_assignments ?? user.role_ids.map((id) => ({ role_id: id, scope: null, legal_entity_ids: [], site_ids: [], org_unit_ids: [] })),
  )
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const toggleRole = (roleId: string, on: boolean) =>
    setAssignments((list) => (on ? [...list, { role_id: roleId, scope: null, legal_entity_ids: [], site_ids: [], org_unit_ids: [] }] : list.filter((a) => a.role_id !== roleId)))
  const patch = (roleId: string, change: Partial<Assignment>) =>
    setAssignments((list) => list.map((a) => (a.role_id === roleId ? { ...a, ...change } : a)))

  async function submit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault()
    setBusy(true)
    setError(null)
    try {
      await api.request(`/users/${user.id}`, 'PATCH', { display_name: displayName, email: email || null, ...identityPayload(identity), role_assignments: assignments },
        user.version !== undefined ? { 'If-Match': `"${user.version}"` } : undefined)
      await onSaved()
    } catch (err) {
      setError(Object.values(formErrors(err, t).fields)[0] ?? formErrors(err, t).general)
    } finally {
      setBusy(false)
    }
  }

  return (
    <SidePanel
      title={t('users.form_edit')}
      onClose={onClose}
      closeLabel={t('common.close')}
      footer={<>
        <button type="button" className="btn btn-sec" onClick={onClose}>{t('users.cancel')}</button>
        <button type="submit" form="editar-usuario" className="btn btn-primary" disabled={busy}>{busy && <span className="spin" aria-hidden="true" />}{t('org.save')}</button>
      </>}
    >
      <form id="editar-usuario" onSubmit={submit} noValidate>
        <label className="form-label" htmlFor="e-name">{t('users.display_name')}</label>
        <input id="e-name" className="form-control mb-3" value={displayName} onChange={(e) => setDisplayName(e.target.value)} />
        <label className="form-label" htmlFor="e-email">{t('users.email')} <span className="text-secondary">{t('users.email_optional')}</span></label>
        <input id="e-email" className="form-control mb-3" type="email" value={email} onChange={(e) => setEmail(e.target.value)} />
        <LoginIdentityFields idPrefix="e" value={identity} onChange={setIdentity} maskedDocument={user.document_number_masked} maskedPhone={user.phone_masked} />
        <fieldset style={{ border: 0, padding: 0, margin: 0 }}>
          <legend className="form-label">{t('users.roles')}</legend>
          <p className="small text-secondary mb-2">{t('users.scope_note')}</p>
          {roles.map((r) => {
            const a = assignments.find((x) => x.role_id === r.id)
            return (
              <div key={r.id} className="mb-3">
                <label className="check">
                  <input type="checkbox" checked={a !== undefined} onChange={(e) => toggleRole(r.id, e.target.checked)} />
                  <span>{r.name}</span>
                </label>
                {a && (
                  <div style={{ marginLeft: 24, marginTop: 6 }}>
                    <label className="small text-secondary" htmlFor={`sc-${r.id}`}>{t('users.scope')}</label>
                    <select id={`sc-${r.id}`} className="form-select" value={a.scope ?? ''} onChange={(e) => patch(r.id, { scope: e.target.value === '' ? null : e.target.value })}>
                      <option value="">{t('users.scope_default')}</option>
                      {SCOPES.map((s) => <option key={s} value={s}>{t(`roles.scope_${s}`)}</option>)}
                    </select>
                    {a.scope === 'legal_entity' && (
                      <div className="mt-2" role="group" aria-label={t('users.scope_companies')}>
                        {companies.map((c) => (
                          <label key={c.id} className="check">
                            <input type="checkbox" checked={a.legal_entity_ids.includes(c.id)} onChange={(e) => patch(r.id, { legal_entity_ids: e.target.checked ? [...a.legal_entity_ids, c.id] : a.legal_entity_ids.filter((x) => x !== c.id) })} />
                            <span>{c.name}</span>
                          </label>
                        ))}
                      </div>
                    )}
                  </div>
                )}
              </div>
            )
          })}
        </fieldset>
        {error && <div className="invalid-feedback" role="alert">{error}</div>}
      </form>
    </SidePanel>
  )
}
