'use client'

import { useCallback, useEffect, useState, type FormEvent } from 'react'
import { api, ApiRequestError, type Schema } from '@/lib/api'
import { formErrors } from '@/lib/errors'
import { useT } from '@/lib/useT'
import { settingsTabs } from '@/modules/identity/settingsTabs'
import { useSession } from '@/modules/session/SessionProvider'
import { Icon } from '@/ui/components/Icon'
import { PageHeader } from '@/ui/components/PageHeader'
import { SidePanel } from '@/ui/components/SidePanel'
import { EmptyState, ErrorState } from '@/ui/components/States'
import { useToast } from '@/ui/components/Toast'
import { typeLabel } from './shared'

type ApproverKind = 'user' | 'role' | 'manager' | 'manager_of_manager'
type Approver = { kind: ApproverKind; user_id?: string; role_id?: string }
type Level = { name: string; mode: 'sequential' | 'parallel_all' | 'parallel_any'; sla_hours?: number | null; approvers: Approver[]; escalate_to?: Approver | null }
type Flow = Omit<Schema<'ApprovalFlow'>, 'steps'> & { steps: { levels: Level[] } }
type Option = { id: string; name: string }

/** Tipos que hoy ofrecen los módulos para pedir aprobación. */
const SUBJECT_TYPES = ['vacation_request', 'permission_request', 'overtime_request', 'expense_report', 'job_requisition', 'position_request', 'compensation_change', 'document_request']
const KINDS: ApproverKind[] = ['manager', 'manager_of_manager', 'role', 'user']

/** Configuración → Flujos de aprobación (WFL-1): niveles, quién aprueba, modo y plazo; versiones al publicar. */
export function FlowsScreen() {
  const { t } = useT()
  const { can } = useSession()
  const { toast, node: toastNode } = useToast()
  const [rows, setRows] = useState<Flow[] | null>(null)
  const [loadError, setLoadError] = useState(false)
  const [editing, setEditing] = useState<Flow | 'new' | null>(null)

  const load = useCallback(async () => {
    try {
      setRows((await api.get<{ data: Flow[] }>('/approval-flows?per_page=100')).data)
      setLoadError(false)
    } catch {
      setLoadError(true)
    }
  }, [])
  useEffect(() => { void load() }, [load])

  async function act(flow: Flow, action: 'publish' | 'retire') {
    if (action === 'retire' && !window.confirm(t('flows.confirm_retire', { name: flow.name }))) return
    try {
      await api.post(`/approval-flows/${flow.id}/${action}`, {})
      toast(t(action === 'publish' ? 'flows.published' : 'flows.retired'))
      setEditing(null)
      await load()
    } catch (e) {
      toast(e instanceof ApiRequestError ? e.message : t('auth.generic_error'), 'error')
    }
  }

  const tone = (s: string) => (s === 'published' ? 'ok' : s === 'draft' ? 'wa' : 'gr')

  return (
    <>
      <PageHeader
        title={t('flows.title')}
        sub={t('flows.sub')}
        tabs={settingsTabs(t, can, 'flows')}
        actions={can('workflow.approval_flows.create') && <button type="button" className="btn btn-primary" onClick={() => setEditing('new')}><Icon name="plus" /> {t('flows.new')}</button>}
      />
      <main className="main" id="contenido" tabIndex={-1}>
        <section className="card">
          <div className="tabla-wrap">
            <table className="table">
              <thead><tr><th>{t('flows.col_name')}</th><th>{t('flows.col_type')}</th><th>{t('flows.col_levels')}</th><th>{t('flows.col_status')}</th><th /></tr></thead>
              <tbody>
                {loadError && <tr><td colSpan={5}><ErrorState text={t('common.load_error')} retryLabel={t('common.retry')} onRetry={() => void load()} /></td></tr>}
                {!loadError && rows === null && [1, 2].map((i) => <tr key={i}><td colSpan={5}><span className="esq" style={{ width: `${30 + i * 20}%` }} /></td></tr>)}
                {rows?.length === 0 && <tr><td colSpan={5}><EmptyState title={t('flows.empty_title')} text={t('flows.empty_text')} action={can('workflow.approval_flows.create') && <button type="button" className="btn btn-primary btn-sm" onClick={() => setEditing('new')}><Icon name="plus" /> {t('flows.new')}</button>} /></td></tr>}
                {rows?.map((f) => (
                  <tr key={f.id}>
                    <td><b>{f.name}</b>{f.version > 0 && <span className="sub2">{t('flows.version', { n: f.version })}</span>}</td>
                    <td>{typeLabel(t, f.subject_type)}</td>
                    <td>{f.steps.levels.map((l, i) => l.name || t('flows.level_n', { n: i + 1 })).join(' → ') || '—'}</td>
                    <td><span className={`st ${tone(f.status)}`}>{t(`flows.status_${f.status}`)}</span>{f.has_draft && <span className="tag wa" style={{ marginLeft: 6 }}>{t('flows.has_draft')}</span>}</td>
                    <td className="text-end nowrap">
                      {can('workflow.approval_flows.update') && f.status !== 'retired' && <button type="button" className="btn btn-ter btn-sm" onClick={() => setEditing(f)}>{t('common.edit')}</button>}
                      {can('workflow.approval_flows.publish') && f.allowed_actions?.includes('publish') && (f.status !== 'published' || f.has_draft) && <button type="button" className="btn btn-ter btn-sm" onClick={() => void act(f, 'publish')}>{t('flows.publish')}</button>}
                      {can('workflow.approval_flows.retire') && f.allowed_actions?.includes('retire') && <button type="button" className="btn btn-ter btn-sm" onClick={() => void act(f, 'retire')}>{t('flows.retire')}</button>}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </section>
      </main>
      {editing !== null && (
        <FlowPanel flow={editing === 'new' ? null : editing} onClose={() => setEditing(null)} onPublish={(f) => void act(f, 'publish')}
          onSaved={async () => { setEditing(null); toast(t('flows.saved')); await load() }} />
      )}
      {toastNode}
    </>
  )
}

function FlowPanel({ flow, onClose, onSaved, onPublish }: { flow: Flow | null; onClose: () => void; onSaved: () => Promise<void>; onPublish: (f: Flow) => void }) {
  const { t } = useT()
  const { can } = useSession()
  const [name, setName] = useState(flow?.name ?? '')
  const [subject, setSubject] = useState(flow?.subject_type ?? SUBJECT_TYPES[0])
  const [levels, setLevels] = useState<Level[]>(flow?.steps.levels.length ? flow.steps.levels : [{ name: '', mode: 'sequential', sla_hours: null, approvers: [{ kind: 'manager' }] }])
  const [people, setPeople] = useState<Option[]>([])
  const [roles, setRoles] = useState<Option[]>([])
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  useEffect(() => {
    if (can('identity.users.view')) void api.get<{ data: Array<{ id: string; display_name: string }> }>('/users?per_page=100&status=active').then((r) => setPeople(r.data.map((u) => ({ id: u.id, name: u.display_name }))))
    if (can('identity.roles.view')) void api.get<{ data: Array<{ id: string; name: string }> }>('/roles?per_page=100').then((r) => setRoles(r.data.map((x) => ({ id: x.id, name: x.name }))))
  }, [can])

  const patchLevel = (i: number, change: Partial<Level>) => setLevels((ls) => ls.map((l, j) => (j === i ? { ...l, ...change } : l)))
  const patchApprover = (i: number, j: number, change: Partial<Approver>) => patchLevel(i, { approvers: levels[i].approvers.map((a, k) => (k === j ? { ...a, ...change } : a)) })

  async function submit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault()
    setBusy(true)
    setErrors({})
    setError(null)
    const clean = levels.map((l) => ({
      name: l.name || null,
      mode: l.mode,
      sla_hours: l.sla_hours ? Number(l.sla_hours) : null,
      approvers: l.approvers.map((a) => (a.kind === 'user' ? { kind: a.kind, user_id: a.user_id } : a.kind === 'role' ? { kind: a.kind, role_id: a.role_id } : { kind: a.kind })),
      escalate_to: l.escalate_to?.kind === 'user' ? { kind: 'user', user_id: l.escalate_to.user_id } : l.escalate_to?.kind === 'role' ? { kind: 'role', role_id: l.escalate_to.role_id } : null,
    }))
    try {
      const body = { name, subject_type: subject, steps: { levels: clean }, conditions: flow?.conditions ?? {} }
      if (flow) await api.request(`/approval-flows/${flow.id}`, 'PATCH', body)
      else await api.post('/approval-flows', body)
      await onSaved()
    } catch (err) {
      const { fields, general } = formErrors(err, t)
      setErrors(fields)
      setError(general ?? (Object.keys(fields).length ? Object.values(fields)[0] : null))
    } finally {
      setBusy(false)
    }
  }

  const err = (key: string) => errors[key] && <div className="invalid-feedback" role="alert">{errors[key]}</div>

  return (
    <SidePanel
      title={t(flow ? 'flows.form_edit' : 'flows.form_new')}
      onClose={onClose}
      closeLabel={t('common.close')}
      footer={<>
        {flow && can('workflow.approval_flows.publish') && flow.allowed_actions?.includes('publish') && <button type="button" className="btn btn-sec" onClick={() => onPublish(flow)}>{t('flows.publish')}</button>}
        <button type="submit" form="flujo" className="btn btn-primary" disabled={busy}>{busy && <span className="spin" aria-hidden="true" />}{t('flows.save')}</button>
      </>}
    >
      <form id="flujo" onSubmit={submit} noValidate>
        <div className="mb-3">
          <label className="form-label" htmlFor="f-name">{t('flows.name')}</label>
          <input id="f-name" className={`form-control${errors.name ? ' is-invalid' : ''}`} value={name} onChange={(e) => setName(e.target.value)} required maxLength={160} />
          {err('name')}
        </div>
        <div className="mb-3">
          <label className="form-label" htmlFor="f-type">{t('flows.subject_type')}</label>
          <select id="f-type" className="form-select" value={subject} onChange={(e) => setSubject(e.target.value)}>
            {SUBJECT_TYPES.map((s) => <option key={s} value={s}>{typeLabel(t, s)}</option>)}
          </select>
        </div>
        <h3 className="small" style={{ fontWeight: 700, margin: '16px 0 4px' }}>{t('flows.levels')}</h3>
        <p className="small text-secondary">{t('flows.levels_help')}</p>
        <div className="niveles">
          {levels.map((l, i) => (
            <fieldset key={i} className="nivel">
              <div className="nivel-cab">
                <b>{t('flows.level_n', { n: i + 1 })}</b>
                {levels.length > 1 && <button type="button" className="ibtn" aria-label={t('flows.remove_level')} onClick={() => setLevels((ls) => ls.filter((_, j) => j !== i))}><Icon name="trash" /></button>}
              </div>
              <div className="mb-2">
                <label className="form-label" htmlFor={`l${i}-name`}>{t('flows.level_name')}</label>
                <input id={`l${i}-name`} className="form-control" value={l.name ?? ''} onChange={(e) => patchLevel(i, { name: e.target.value })} />
              </div>
              <div className="mb-2">
                <span className="form-label" id={`l${i}-mode`}>{t('flows.mode')}</span>
                <div className="seg" role="radiogroup" aria-labelledby={`l${i}-mode`}>
                  {(['sequential', 'parallel_all', 'parallel_any'] as const).map((m) => (
                    <button key={m} type="button" role="radio" aria-checked={l.mode === m} className={l.mode === m ? 'on' : undefined} onClick={() => patchLevel(i, { mode: m })}>{t(`flows.mode_${m}`)}</button>
                  ))}
                </div>
                {err(`steps.levels.${i}.mode`)}
              </div>
              <span className="form-label">{t('flows.approvers')}</span>
              {l.approvers.map((a, j) => (
                <div key={j} className="aprobador">
                  <select className="form-select" aria-label={t('flows.approvers')} value={a.kind} onChange={(e) => patchApprover(i, j, { kind: e.target.value as ApproverKind })}>
                    {KINDS.map((k) => <option key={k} value={k}>{t(`flows.kind_${k}`)}</option>)}
                  </select>
                  {a.kind === 'user' ? (
                    <select className="form-select" aria-label={t('flows.kind_user')} value={a.user_id ?? ''} onChange={(e) => patchApprover(i, j, { user_id: e.target.value })}>
                      <option value="">{t('flows.pick')}</option>
                      {people.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
                    </select>
                  ) : a.kind === 'role' ? (
                    <select className="form-select" aria-label={t('flows.kind_role')} value={a.role_id ?? ''} onChange={(e) => patchApprover(i, j, { role_id: e.target.value })}>
                      <option value="">{t('flows.pick')}</option>
                      {roles.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
                    </select>
                  ) : <span />}
                  <button type="button" className="ibtn" aria-label={t('flows.remove_approver')} disabled={l.approvers.length === 1} onClick={() => patchLevel(i, { approvers: l.approvers.filter((_, k) => k !== j) })}><Icon name="close" /></button>
                  {err(`steps.levels.${i}.approvers.${j}`)}
                </div>
              ))}
              {err(`steps.levels.${i}.approvers`)}
              <button type="button" className="btn btn-ter btn-sm" onClick={() => patchLevel(i, { approvers: [...l.approvers, { kind: 'role' }] })}><Icon name="plus" /> {t('flows.add_approver')}</button>
              <div className="grid-2" style={{ gridTemplateColumns: '1fr 1.4fr', gap: 8, marginTop: 8 }}>
                <div>
                  <label className="form-label" htmlFor={`l${i}-sla`}>{t('flows.sla')}</label>
                  <input id={`l${i}-sla`} type="number" min={1} inputMode="numeric" className={`form-control${errors[`steps.levels.${i}.sla_hours`] ? ' is-invalid' : ''}`} value={l.sla_hours ?? ''} onChange={(e) => patchLevel(i, { sla_hours: e.target.value === '' ? null : Number(e.target.value) })} aria-describedby={`l${i}-sla-h`} />
                  {err(`steps.levels.${i}.sla_hours`)}
                </div>
                <div>
                  <label className="form-label" htmlFor={`l${i}-esc`}>{t('flows.escalate_to')}</label>
                  <select id={`l${i}-esc`} className="form-select" disabled={!l.sla_hours}
                    value={l.escalate_to?.kind === 'user' ? `u:${l.escalate_to.user_id ?? ''}` : l.escalate_to?.kind === 'role' ? `r:${l.escalate_to.role_id ?? ''}` : ''}
                    onChange={(e) => { const v = e.target.value; patchLevel(i, { escalate_to: v.startsWith('u:') ? { kind: 'user', user_id: v.slice(2) } : v.startsWith('r:') ? { kind: 'role', role_id: v.slice(2) } : null }) }}>
                    <option value="">{t('flows.escalate_default')}</option>
                    {roles.map((r) => <option key={r.id} value={`r:${r.id}`}>{t('flows.kind_role')}: {r.name}</option>)}
                    {people.map((p) => <option key={p.id} value={`u:${p.id}`}>{p.name}</option>)}
                  </select>
                </div>
              </div>
              <p id={`l${i}-sla-h`} className="small text-secondary" style={{ marginTop: 4 }}>{t('flows.sla_help')}</p>
            </fieldset>
          ))}
        </div>
        <button type="button" className="btn btn-sec btn-sm mt-2" onClick={() => setLevels((ls) => [...ls, { name: '', mode: 'sequential', sla_hours: null, approvers: [{ kind: 'manager_of_manager' }] }])}><Icon name="plus" /> {t('flows.add_level')}</button>
        <p className="small text-secondary mt-2">{t('flows.publish_help')}</p>
        {error && <div className="invalid-feedback" role="alert">{error}</div>}
      </form>
    </SidePanel>
  )
}
