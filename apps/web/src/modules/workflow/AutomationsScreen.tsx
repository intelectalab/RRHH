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

type Op = 'eq' | 'neq' | 'in' | 'not_in' | 'contains' | 'gt' | 'gte' | 'lt' | 'lte' | 'exists' | 'not_exists'
type Condition = { field: string; op: Op; value?: string | number | string[] }
type ActionType = 'create_task' | 'notify' | 'start_approval'
type Action = {
  type: ActionType
  title?: string
  description?: string
  message?: string
  assignee_user_id?: string
  assignee_role_id?: string
  assignee_field?: string
  due_in_days?: number
  priority?: 'low' | 'normal' | 'high' | 'urgent'
  user_ids?: string[]
  role_ids?: string[]
  user_field?: string
  subject_type?: string
  subject_id_field?: string
  requester_field?: string
}
type Rule = Omit<Schema<'Workflow'>, 'conditions' | 'actions'> & { conditions: { match?: 'all' | 'any'; rules?: Condition[] }; actions: Action[] }
type RunAction = { index: number; type: ActionType; status: 'succeeded' | 'failed' | 'not_run'; error?: string }
type Run = Omit<Schema<'WorkflowRun'>, 'actions'> & { actions: RunAction[] }
type Catalog = { triggers: Array<{ event: string; fields: string[] }>; operators: Op[]; actions: Array<{ type: ActionType }>; subject_types: string[] }
type Option = { id: string; name: string }
type Preview = { matches: boolean; actions: Array<{ index: number; type: ActionType; status: 'ready' | 'error'; error?: string; params?: Record<string, unknown> }> }

const NO_VALUE: Op[] = ['exists', 'not_exists']
const LIST_VALUE: Op[] = ['in', 'not_in']

/** Nombre del evento disparador; los eventos sin texto propio muestran su código. */
export function eventLabel(t: (k: string) => string, event: string): string {
  const key = `autoev.${event.replace(/\.v\d+$/, '').replace(/\./g, '_')}`
  const label = t(key)
  return label === key ? event : label
}

/** Configuración → Automatizaciones (WFL-5): reglas CUANDO → SI → ENTONCES y el centro de ejecuciones. */
export function AutomationsScreen() {
  const { t } = useT()
  const { can } = useSession()
  const { toast, node: toastNode } = useToast()
  const [view, setView] = useState<'rules' | 'runs'>('rules')
  const [rules, setRules] = useState<Rule[] | null>(null)
  const [runs, setRuns] = useState<Run[] | null>(null)
  const [catalog, setCatalog] = useState<Catalog | null>(null)
  const [loadError, setLoadError] = useState(false)
  const [editing, setEditing] = useState<Rule | 'new' | null>(null)
  const [openRun, setOpenRun] = useState<string | null>(null)

  const load = useCallback(async () => {
    try {
      const [r, c] = await Promise.all([api.get<{ data: Rule[] }>('/workflows?per_page=100'), api.get<{ data: Catalog }>('/workflows/catalog')])
      setRules(r.data)
      setCatalog(c.data)
      if (can('workflow.runs.view')) setRuns((await api.get<{ data: Run[] }>('/workflow-runs?per_page=50')).data)
      setLoadError(false)
    } catch {
      setLoadError(true)
    }
  }, [can])
  useEffect(() => { void load() }, [load])

  async function act(rule: Rule, action: 'activate' | 'pause' | 'retire') {
    if (action === 'retire' && !window.confirm(t('auto.confirm_retire', { name: rule.name }))) return
    try {
      await api.post(`/workflows/${rule.id}/${action}`, {})
      toast(t(`auto.done_${action}`))
      setEditing(null)
      await load()
    } catch (e) {
      toast(e instanceof ApiRequestError ? e.message : t('auth.generic_error'), 'error')
    }
  }

  async function runAct(run: Run, action: 'retry' | 'cancel') {
    try {
      const r = await api.post<{ data: Run }>(`/workflow-runs/${run.id}/${action}`, {})
      toast(t(action === 'retry' ? (r.data.status === 'succeeded' ? 'auto.retried_ok' : 'auto.retried_failed') : 'auto.run_cancelled'), r.data.status === 'failed' ? 'error' : undefined)
      await load()
    } catch (e) {
      toast(e instanceof ApiRequestError ? e.message : t('auth.generic_error'), 'error')
    }
  }

  const ruleTone = (s: string) => (s === 'active' ? 'ok' : s === 'draft' ? 'wa' : 'gr')
  const runTone = (s: string) => (s === 'succeeded' ? 'ok' : s === 'failed' ? 'er' : s === 'skipped' || s === 'cancelled' ? 'gr' : 'wa')
  const when = (iso?: string | null) => (iso ? new Date(iso).toLocaleString() : '—')
  const canCreate = can('workflow.workflows.create')

  return (
    <>
      <PageHeader
        title={t('auto.title')}
        sub={t('auto.sub')}
        tabs={settingsTabs(t, can, 'automations')}
        actions={canCreate && <button type="button" className="btn btn-primary" onClick={() => setEditing('new')} disabled={!catalog}><Icon name="plus" /> {t('auto.new')}</button>}
      />
      <main className="main" id="contenido" tabIndex={-1}>
        {can('workflow.runs.view') && (
          <div className="seg mb-3" role="tablist" aria-label={t('auto.title')}>
            {(['rules', 'runs'] as const).map((v) => (
              <button key={v} type="button" role="tab" aria-selected={view === v} className={view === v ? 'on' : undefined} onClick={() => setView(v)}>{t(`auto.view_${v}`)}</button>
            ))}
          </div>
        )}
        {view === 'rules' ? (
          <section className="card">
            <div className="tabla-wrap">
              <table className="table">
                <thead><tr><th>{t('auto.col_name')}</th><th>{t('auto.col_when')}</th><th>{t('auto.col_then')}</th><th>{t('auto.col_last')}</th><th>{t('auto.col_status')}</th><th /></tr></thead>
                <tbody>
                  {loadError && <tr><td colSpan={6}><ErrorState text={t('common.load_error')} retryLabel={t('common.retry')} onRetry={() => void load()} /></td></tr>}
                  {!loadError && rules === null && [1, 2].map((i) => <tr key={i}><td colSpan={6}><span className="esq" style={{ width: `${30 + i * 20}%` }} /></td></tr>)}
                  {rules?.length === 0 && <tr><td colSpan={6}><EmptyState title={t('auto.empty_title')} text={t('auto.empty_text')} action={canCreate && catalog && <button type="button" className="btn btn-primary btn-sm" onClick={() => setEditing('new')}><Icon name="plus" /> {t('auto.new')}</button>} /></td></tr>}
                  {rules?.map((r) => (
                    <tr key={r.id}>
                      <td><b>{r.name}</b>{(r.conditions.rules?.length ?? 0) > 0 && <span className="sub2">{t('auto.n_conditions', { n: r.conditions.rules?.length ?? 0 })}</span>}</td>
                      <td>{eventLabel(t, r.trigger_event)}</td>
                      <td>{r.actions.map((a) => t(`auto.action_${a.type}`)).join(' → ')}</td>
                      <td className="nowrap">{when(r.last_run_at)}</td>
                      <td><span className={`st ${ruleTone(r.status)}`}>{t(`auto.status_${r.status}`)}</span></td>
                      <td className="text-end nowrap">
                        {can('workflow.workflows.update') && <button type="button" className="btn btn-ter btn-sm" onClick={() => setEditing(r)}>{t('common.edit')}</button>}
                        {can('workflow.workflows.activate') && r.allowed_actions.includes('activate') && <button type="button" className="btn btn-ter btn-sm" onClick={() => void act(r, 'activate')}>{t('auto.activate')}</button>}
                        {can('workflow.workflows.pause') && r.allowed_actions.includes('pause') && <button type="button" className="btn btn-ter btn-sm" onClick={() => void act(r, 'pause')}>{t('auto.pause')}</button>}
                        {can('workflow.workflows.retire') && r.allowed_actions.includes('retire') && <button type="button" className="btn btn-ter btn-sm" onClick={() => void act(r, 'retire')}>{t('auto.retire')}</button>}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <p className="small text-secondary" style={{ padding: '0 16px 12px' }}>{t('auto.safety')}</p>
          </section>
        ) : (
          <section className="card">
            <div className="tabla-wrap">
              <table className="table">
                <thead><tr><th>{t('auto.col_rule')}</th><th>{t('auto.col_event')}</th><th>{t('auto.col_started')}</th><th>{t('auto.col_result')}</th><th /></tr></thead>
                <tbody>
                  {runs?.length === 0 && <tr><td colSpan={5}><EmptyState title={t('auto.runs_empty_title')} text={t('auto.runs_empty_text')} /></td></tr>}
                  {runs?.map((r) => (
                    <tr key={r.id}>
                      <td>
                        <b>{r.workflow_name ?? '—'}</b>
                        {r.attempts > 1 && <span className="sub2">{t('auto.attempts', { n: r.attempts })}</span>}
                        {openRun === r.id && (
                          <ol className="small" style={{ margin: '8px 0 0', paddingLeft: 18 }}>
                            {r.actions.map((a) => <li key={a.index}>{t(`auto.action_${a.type}`)} · <span className={`st ${runTone(a.status === 'not_run' ? 'skipped' : a.status)}`}>{t(`auto.astatus_${a.status}`)}</span>{a.error && <span className="sub2">{a.error}</span>}</li>)}
                          </ol>
                        )}
                      </td>
                      <td>{r.event_type ? eventLabel(t, r.event_type) : '—'}</td>
                      <td className="nowrap">{when(r.started_at)}</td>
                      <td><span className={`st ${runTone(r.status)}`}>{t(`auto.rstatus_${r.status}`)}</span>{r.error && <span className="sub2">{r.error}</span>}{r.next_retry_at && <span className="sub2">{t('auto.next_retry', { at: when(r.next_retry_at) })}</span>}</td>
                      <td className="text-end nowrap">
                        {r.actions.length > 0 && <button type="button" className="btn btn-ter btn-sm" aria-expanded={openRun === r.id} onClick={() => setOpenRun(openRun === r.id ? null : r.id)}>{t(openRun === r.id ? 'auto.hide_detail' : 'auto.show_detail')}</button>}
                        {can('workflow.runs.retry') && r.allowed_actions.includes('retry') && <button type="button" className="btn btn-ter btn-sm" onClick={() => void runAct(r, 'retry')}>{t('auto.retry')}</button>}
                        {can('workflow.runs.cancel') && r.allowed_actions.includes('cancel') && <button type="button" className="btn btn-ter btn-sm" onClick={() => void runAct(r, 'cancel')}>{t('auto.cancel_run')}</button>}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </section>
        )}
      </main>
      {editing !== null && catalog && (
        <RulePanel rule={editing === 'new' ? null : editing} catalog={catalog} onClose={() => setEditing(null)}
          onSaved={async () => { setEditing(null); toast(t('auto.saved')); await load() }} />
      )}
      {toastNode}
    </>
  )
}

function RulePanel({ rule, catalog, onClose, onSaved }: { rule: Rule | null; catalog: Catalog; onClose: () => void; onSaved: () => Promise<void> }) {
  const { t } = useT()
  const { can } = useSession()
  const [name, setName] = useState(rule?.name ?? '')
  const [trigger, setTrigger] = useState(rule?.trigger_event ?? catalog.triggers[0]?.event ?? '')
  const [match, setMatch] = useState<'all' | 'any'>(rule?.conditions.match ?? 'all')
  const [conditions, setConditions] = useState<Condition[]>(rule?.conditions.rules ?? [])
  const [actions, setActions] = useState<Action[]>(rule?.actions.length ? rule.actions : [{ type: 'create_task', title: '', priority: 'normal' }])
  const [people, setPeople] = useState<Option[]>([])
  const [roles, setRoles] = useState<Option[]>([])
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const [sample, setSample] = useState('')
  const [preview, setPreview] = useState<Preview | null>(null)
  const locked = rule?.status === 'active'
  const fields = catalog.triggers.find((x) => x.event === trigger)?.fields ?? []

  useEffect(() => {
    if (can('identity.users.view')) void api.get<{ data: Array<{ id: string; display_name: string }> }>('/users?per_page=100&status=active').then((r) => setPeople(r.data.map((u) => ({ id: u.id, name: u.display_name }))))
    if (can('identity.roles.view')) void api.get<{ data: Array<{ id: string; name: string }> }>('/roles?per_page=100').then((r) => setRoles(r.data.map((x) => ({ id: x.id, name: x.name }))))
  }, [can])

  const patchCond = (i: number, change: Partial<Condition>) => setConditions((cs) => cs.map((c, j) => (j === i ? { ...c, ...change } : c)))
  const patchAction = (i: number, change: Partial<Action>) => setActions((as) => as.map((a, j) => (j === i ? { ...a, ...change } : a)))

  function cleanConditions(): Condition[] {
    return conditions.map((c) => {
      if (NO_VALUE.includes(c.op)) return { field: c.field, op: c.op }
      if (LIST_VALUE.includes(c.op)) return { field: c.field, op: c.op, value: String(c.value ?? '').split(',').map((v) => v.trim()).filter(Boolean) }
      return { field: c.field, op: c.op, value: c.value ?? '' }
    })
  }

  function cleanActions(): Action[] {
    return actions.map((a) => {
      if (a.type === 'create_task') return { type: a.type, title: a.title, description: a.description || undefined, assignee_user_id: a.assignee_user_id || undefined, assignee_role_id: a.assignee_role_id || undefined, assignee_field: a.assignee_field || undefined, due_in_days: a.due_in_days ?? undefined, priority: a.priority ?? 'normal' }
      if (a.type === 'notify') return { type: a.type, title: a.title, message: a.message, user_ids: a.user_ids?.length ? a.user_ids : undefined, role_ids: a.role_ids?.length ? a.role_ids : undefined, user_field: a.user_field || undefined }
      return { type: a.type, subject_type: a.subject_type ?? catalog.subject_types[0], subject_id_field: a.subject_id_field, requester_field: a.requester_field }
    })
  }

  async function submit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault()
    setBusy(true)
    setErrors({})
    setError(null)
    try {
      const body = locked ? { name } : { name, trigger_event: trigger, conditions: { match, rules: cleanConditions() }, actions: cleanActions() }
      if (rule) await api.request(`/workflows/${rule.id}`, 'PATCH', body)
      else await api.post('/workflows', body)
      await onSaved()
    } catch (err) {
      const { fields: fe, general } = formErrors(err, t)
      setErrors(fe)
      setError(general ?? (Object.keys(fe).length ? Object.values(fe)[0] : null))
    } finally {
      setBusy(false)
    }
  }

  async function test() {
    if (!rule) return
    setPreview(null)
    let payload: unknown = {}
    try {
      payload = sample.trim() === '' ? {} : JSON.parse(sample)
    } catch {
      setError(t('auto.sample_invalid'))
      return
    }
    try {
      setPreview((await api.post<{ data: Preview }>(`/workflows/${rule.id}/test`, { payload })).data)
      setError(null)
    } catch (err) {
      setError(err instanceof ApiRequestError ? err.message : t('auth.generic_error'))
    }
  }

  const err = (key: string) => errors[key] && <div className="invalid-feedback" role="alert">{errors[key]}</div>
  const fieldList = `auto-fields-${trigger.replace(/\W/g, '')}`

  return (
    <SidePanel
      title={t(rule ? 'auto.form_edit' : 'auto.form_new')}
      onClose={onClose}
      closeLabel={t('common.close')}
      footer={<button type="submit" form="regla" className="btn btn-primary" disabled={busy}>{busy && <span className="spin" aria-hidden="true" />}{t('auto.save')}</button>}
    >
      <form id="regla" onSubmit={submit} noValidate>
        <datalist id={fieldList}>{fields.map((f) => <option key={f} value={f} />)}</datalist>
        <div className="mb-3">
          <label className="form-label" htmlFor="r-name">{t('auto.name')}</label>
          <input id="r-name" className={`form-control${errors.name ? ' is-invalid' : ''}`} value={name} onChange={(e) => setName(e.target.value)} required maxLength={160} />
          {err('name')}
        </div>
        {locked && <p className="small text-secondary">{t('auto.locked_help')}</p>}
        <fieldset disabled={locked}>
          <h3 className="small" style={{ fontWeight: 700, margin: '16px 0 4px' }}>{t('auto.when')}</h3>
          <div className="mb-3">
            <label className="form-label" htmlFor="r-event">{t('auto.trigger')}</label>
            <select id="r-event" className={`form-select${errors.trigger_event ? ' is-invalid' : ''}`} value={trigger} onChange={(e) => setTrigger(e.target.value)}>
              {catalog.triggers.map((x) => <option key={x.event} value={x.event}>{eventLabel(t, x.event)}</option>)}
            </select>
            {fields.length > 0 && <p className="small text-secondary" style={{ marginTop: 4 }}>{t('auto.fields_help', { fields: fields.join(', ') })}</p>}
            {err('trigger_event')}
          </div>

          <h3 className="small" style={{ fontWeight: 700, margin: '16px 0 4px' }}>{t('auto.if')}</h3>
          {conditions.length > 1 && (
            <div className="seg mb-2" role="radiogroup" aria-label={t('auto.match')}>
              {(['all', 'any'] as const).map((m) => <button key={m} type="button" role="radio" aria-checked={match === m} className={match === m ? 'on' : undefined} onClick={() => setMatch(m)}>{t(`auto.match_${m}`)}</button>)}
            </div>
          )}
          {conditions.length === 0 && <p className="small text-secondary">{t('auto.no_conditions')}</p>}
          {conditions.map((c, i) => (
            <div key={i} className="aprobador" style={{ gridTemplateColumns: '1fr 1fr 1fr auto' }}>
              <input className={`form-control${errors[`conditions.rules.${i}.field`] ? ' is-invalid' : ''}`} list={fieldList} aria-label={t('auto.cond_field')} placeholder={t('auto.cond_field')} value={c.field} onChange={(e) => patchCond(i, { field: e.target.value })} />
              <select className="form-select" aria-label={t('auto.cond_op')} value={c.op} onChange={(e) => patchCond(i, { op: e.target.value as Op })}>
                {catalog.operators.map((o) => <option key={o} value={o}>{t(`auto.op_${o}`)}</option>)}
              </select>
              {NO_VALUE.includes(c.op) ? <span /> : (
                <input className={`form-control${errors[`conditions.rules.${i}.value`] ? ' is-invalid' : ''}`} aria-label={t('auto.cond_value')} placeholder={t(LIST_VALUE.includes(c.op) ? 'auto.cond_value_list' : 'auto.cond_value')}
                  value={Array.isArray(c.value) ? c.value.join(', ') : String(c.value ?? '')} onChange={(e) => patchCond(i, { value: e.target.value })} />
              )}
              <button type="button" className="ibtn" aria-label={t('auto.remove_condition')} onClick={() => setConditions((cs) => cs.filter((_, j) => j !== i))}><Icon name="close" /></button>
              {err(`conditions.rules.${i}.field`) || err(`conditions.rules.${i}.op`) || err(`conditions.rules.${i}.value`)}
            </div>
          ))}
          <button type="button" className="btn btn-ter btn-sm" onClick={() => setConditions((cs) => [...cs, { field: fields[0] ?? '', op: 'eq', value: '' }])}><Icon name="plus" /> {t('auto.add_condition')}</button>

          <h3 className="small" style={{ fontWeight: 700, margin: '16px 0 4px' }}>{t('auto.then')}</h3>
          <p className="small text-secondary">{t('auto.then_help')}</p>
          <div className="niveles">
            {actions.map((a, i) => (
              <fieldset key={i} className="nivel">
                <div className="nivel-cab">
                  <b>{t('auto.action_n', { n: i + 1 })}</b>
                  {actions.length > 1 && <button type="button" className="ibtn" aria-label={t('auto.remove_action')} onClick={() => setActions((as) => as.filter((_, j) => j !== i))}><Icon name="trash" /></button>}
                </div>
                <div className="mb-2">
                  <label className="form-label" htmlFor={`a${i}-type`}>{t('auto.action_type')}</label>
                  <select id={`a${i}-type`} className={`form-select${errors[`actions.${i}.type`] ? ' is-invalid' : ''}`} value={a.type} onChange={(e) => setActions((as) => as.map((x, j) => (j === i ? { type: e.target.value as ActionType } : x)))}>
                    {catalog.actions.map((x) => <option key={x.type} value={x.type}>{t(`auto.action_${x.type}`)}</option>)}
                  </select>
                  {err(`actions.${i}.type`)}
                </div>
                {a.type !== 'start_approval' && (
                  <div className="mb-2">
                    <label className="form-label" htmlFor={`a${i}-title`}>{t('auto.a_title')}</label>
                    <input id={`a${i}-title`} className={`form-control${errors[`actions.${i}.title`] ? ' is-invalid' : ''}`} value={a.title ?? ''} onChange={(e) => patchAction(i, { title: e.target.value })} maxLength={200} />
                    {err(`actions.${i}.title`)}
                  </div>
                )}
                {a.type === 'create_task' && (
                  <>
                    <div className="mb-2">
                      <label className="form-label" htmlFor={`a${i}-desc`}>{t('auto.a_description')}</label>
                      <textarea id={`a${i}-desc`} className="form-control" rows={2} value={a.description ?? ''} onChange={(e) => patchAction(i, { description: e.target.value })} />
                    </div>
                    <div className="mb-2">
                      <label className="form-label" htmlFor={`a${i}-who`}>{t('auto.a_assignee')}</label>
                      <select id={`a${i}-who`} className={`form-select${errors[`actions.${i}.assignee_user_id`] ? ' is-invalid' : ''}`}
                        value={a.assignee_field ? `f:${a.assignee_field}` : a.assignee_user_id ? `u:${a.assignee_user_id}` : a.assignee_role_id ? `r:${a.assignee_role_id}` : ''}
                        onChange={(e) => { const v = e.target.value; patchAction(i, { assignee_user_id: v.startsWith('u:') ? v.slice(2) : undefined, assignee_role_id: v.startsWith('r:') ? v.slice(2) : undefined, assignee_field: v.startsWith('f:') ? v.slice(2) : undefined }) }}>
                        <option value="">{t('flows.pick')}</option>
                        {roles.map((r) => <option key={r.id} value={`r:${r.id}`}>{t('flows.kind_role')}: {r.name}</option>)}
                        {people.map((p) => <option key={p.id} value={`u:${p.id}`}>{p.name}</option>)}
                        {fields.filter((f) => f.endsWith('user_id') || f.endsWith('_by')).map((f) => <option key={f} value={`f:${f}`}>{t('auto.from_field', { field: f })}</option>)}
                      </select>
                      {err(`actions.${i}.assignee_user_id`)}
                    </div>
                    <div className="grid-2" style={{ gap: 8 }}>
                      <div>
                        <label className="form-label" htmlFor={`a${i}-due`}>{t('auto.a_due')}</label>
                        <input id={`a${i}-due`} type="number" min={0} max={365} inputMode="numeric" className={`form-control${errors[`actions.${i}.due_in_days`] ? ' is-invalid' : ''}`} value={a.due_in_days ?? ''} onChange={(e) => patchAction(i, { due_in_days: e.target.value === '' ? undefined : Number(e.target.value) })} />
                      </div>
                      <div>
                        <label className="form-label" htmlFor={`a${i}-prio`}>{t('auto.a_priority')}</label>
                        <select id={`a${i}-prio`} className="form-select" value={a.priority ?? 'normal'} onChange={(e) => patchAction(i, { priority: e.target.value as Action['priority'] })}>
                          {(['low', 'normal', 'high', 'urgent'] as const).map((p) => <option key={p} value={p}>{t(`auto.prio_${p}`)}</option>)}
                        </select>
                      </div>
                    </div>
                  </>
                )}
                {a.type === 'notify' && (
                  <>
                    <div className="mb-2">
                      <label className="form-label" htmlFor={`a${i}-msg`}>{t('auto.a_message')}</label>
                      <textarea id={`a${i}-msg`} className={`form-control${errors[`actions.${i}.message`] ? ' is-invalid' : ''}`} rows={2} value={a.message ?? ''} onChange={(e) => patchAction(i, { message: e.target.value })} maxLength={1000} />
                      {err(`actions.${i}.message`)}
                    </div>
                    <div className="mb-2">
                      <label className="form-label" htmlFor={`a${i}-to`}>{t('auto.a_recipients')}</label>
                      <select id={`a${i}-to`} multiple className={`form-select${errors[`actions.${i}.user_ids`] ? ' is-invalid' : ''}`} size={4}
                        value={[...(a.role_ids ?? []).map((x) => `r:${x}`), ...(a.user_ids ?? []).map((x) => `u:${x}`), ...(a.user_field ? [`f:${a.user_field}`] : [])]}
                        onChange={(e) => { const vs = Array.from(e.target.selectedOptions, (o) => o.value); patchAction(i, { role_ids: vs.filter((v) => v.startsWith('r:')).map((v) => v.slice(2)), user_ids: vs.filter((v) => v.startsWith('u:')).map((v) => v.slice(2)), user_field: vs.find((v) => v.startsWith('f:'))?.slice(2) }) }}>
                        {roles.map((r) => <option key={r.id} value={`r:${r.id}`}>{t('flows.kind_role')}: {r.name}</option>)}
                        {people.map((p) => <option key={p.id} value={`u:${p.id}`}>{p.name}</option>)}
                        {fields.filter((f) => f.endsWith('user_id') || f.endsWith('_by')).map((f) => <option key={f} value={`f:${f}`}>{t('auto.from_field', { field: f })}</option>)}
                      </select>
                      {err(`actions.${i}.user_ids`)}
                    </div>
                  </>
                )}
                {a.type === 'start_approval' && (
                  <>
                    <div className="mb-2">
                      <label className="form-label" htmlFor={`a${i}-st`}>{t('flows.subject_type')}</label>
                      <select id={`a${i}-st`} className="form-select" value={a.subject_type ?? catalog.subject_types[0]} onChange={(e) => patchAction(i, { subject_type: e.target.value })}>
                        {catalog.subject_types.map((s) => <option key={s} value={s}>{typeLabel(t, s)}</option>)}
                      </select>
                    </div>
                    <div className="grid-2" style={{ gap: 8 }}>
                      <div>
                        <label className="form-label" htmlFor={`a${i}-sid`}>{t('auto.a_subject_field')}</label>
                        <input id={`a${i}-sid`} list={fieldList} className={`form-control${errors[`actions.${i}.subject_id_field`] ? ' is-invalid' : ''}`} value={a.subject_id_field ?? ''} onChange={(e) => patchAction(i, { subject_id_field: e.target.value })} />
                      </div>
                      <div>
                        <label className="form-label" htmlFor={`a${i}-req`}>{t('auto.a_requester_field')}</label>
                        <input id={`a${i}-req`} list={fieldList} className={`form-control${errors[`actions.${i}.requester_field`] ? ' is-invalid' : ''}`} value={a.requester_field ?? ''} onChange={(e) => patchAction(i, { requester_field: e.target.value })} />
                      </div>
                    </div>
                    <p className="small text-secondary" style={{ marginTop: 4 }}>{t('auto.approval_help')}</p>
                  </>
                )}
              </fieldset>
            ))}
          </div>
          <button type="button" className="btn btn-sec btn-sm mt-2" disabled={actions.length >= 10} onClick={() => setActions((as) => [...as, { type: 'notify', title: '', message: '' }])}><Icon name="plus" /> {t('auto.add_action')}</button>
          <p className="small text-secondary mt-2">{t('auto.template_help')}</p>
        </fieldset>

        {rule && can('workflow.workflows.update') && (
          <>
            <h3 className="small" style={{ fontWeight: 700, margin: '16px 0 4px' }}>{t('auto.test')}</h3>
            <label className="form-label" htmlFor="r-sample">{t('auto.sample')}</label>
            <textarea id="r-sample" className="form-control" rows={3} spellCheck={false} placeholder={`{"${fields[0] ?? 'campo'}": "…"}`} value={sample} onChange={(e) => setSample(e.target.value)} />
            <button type="button" className="btn btn-sec btn-sm mt-2" onClick={() => void test()}>{t('auto.run_test')}</button>
            {preview && (
              <div className="small mt-2" role="status">
                <span className={`st ${preview.matches ? 'ok' : 'gr'}`}>{t(preview.matches ? 'auto.test_matches' : 'auto.test_no_match')}</span>
                <ol style={{ margin: '8px 0 0', paddingLeft: 18 }}>
                  {preview.actions.map((p) => (
                    <li key={p.index}>{t(`auto.action_${p.type}`)}{typeof p.params?.title === 'string' && <>{t('auto.preview_titled', { title: p.params.title })}</>}{p.error && <span className="invalid-feedback" style={{ display: 'block' }}>{p.error}</span>}</li>
                  ))}
                </ol>
              </div>
            )}
          </>
        )}
        {error && <div className="invalid-feedback" role="alert" style={{ display: 'block' }}>{error}</div>}
      </form>
    </SidePanel>
  )
}
