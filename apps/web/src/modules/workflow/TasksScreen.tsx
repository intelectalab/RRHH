'use client'

import { useSearchParams } from 'next/navigation'
import { useCallback, useEffect, useState, type FormEvent } from 'react'
import { api, ApiRequestError, type Schema } from '@/lib/api'
import { formErrors } from '@/lib/errors'
import { formatDate } from '@/lib/format'
import { useT } from '@/lib/useT'
import { useSession } from '@/modules/session/SessionProvider'
import { Icon } from '@/ui/components/Icon'
import { PageHeader } from '@/ui/components/PageHeader'
import { SidePanel } from '@/ui/components/SidePanel'
import { EmptyState, ErrorState } from '@/ui/components/States'
import { useToast } from '@/ui/components/Toast'
import { CustomFieldsEditor, useCustomFields, type CustomValues } from '@/modules/customfields/CustomFieldsEditor'
import { notifyInboxChanged } from './shared'
import { useQuickCreateRequest } from '@/modules/session/quickCreateRegistry'

type Task = Schema<'Task'> & { mine?: boolean; version?: number }
type Option = { id: string; name: string }
type Filter = 'open' | 'overdue' | 'done' | 'all'

const PRIORITY_TONE: Record<string, string> = { low: 'gr', normal: 'gr', high: 'wa', urgent: 'er' }

/** Tareas de RRHH (WFL-8): a una persona o a un rol, con vencimiento y prioridad. */
export function TasksScreen() {
  const { t, locale } = useT()
  const { session, can } = useSession()
  const params = useSearchParams()
  const { toast, node: toastNode } = useToast()
  const wide = (session.scopes['workflow.tasks.view'] ?? '') !== 'own' && (session.scopes['workflow.tasks.view'] ?? '') !== 'assigned'
  const [mine, setMine] = useState(true)
  const [filter, setFilter] = useState<Filter>('open')
  const [rows, setRows] = useState<Task[] | null>(null)
  const [loadError, setLoadError] = useState(false)
  const [editing, setEditing] = useState<Task | 'new' | null>(null)
  useQuickCreateRequest('task', () => setEditing('new'), can('workflow.tasks.create'))

  const load = useCallback(async () => {
    const q = new URLSearchParams({ per_page: '100' })
    if (mine) q.set('filter[assigned]', 'me')
    if (filter === 'overdue') q.set('filter[overdue]', '1')
    else if (filter !== 'all') q.set('filter[status]', filter)
    try {
      const res = await api.get<{ data: Task[] }>(`/tasks?${q.toString()}`)
      setRows(res.data)
      setLoadError(false)
    } catch {
      setLoadError(true)
    }
  }, [mine, filter])

  useEffect(() => { setRows(null); void load() }, [load])

  useEffect(() => {
    const id = params.get('id')
    if (!id) return
    void api.get<{ data: Task }>(`/tasks/${id}`).then((r) => setEditing(r.data)).catch(() => undefined)
  }, [params])

  async function transition(row: Task, action: 'complete' | 'cancel') {
    if (action === 'cancel' && !window.confirm(t('tasks.confirm_cancel', { title: row.title }))) return
    try {
      await api.post(`/tasks/${row.id}/${action}`, {})
      toast(t(action === 'complete' ? 'tasks.completed' : 'tasks.cancelled'))
      setEditing(null)
      notifyInboxChanged()
      await load()
    } catch (e) {
      toast(e instanceof ApiRequestError ? e.message : t('auth.generic_error'), 'error')
    }
  }

  const open = rows?.filter((r) => r.status === 'pending' || r.status === 'in_progress').length ?? 0
  const overdue = rows?.filter((r) => r.overdue).length ?? 0

  return (
    <>
      <PageHeader
        title={t('tasks.title')}
        sub={rows ? t('tasks.sub', { open, overdue }) : undefined}
        actions={can('workflow.tasks.create') && (
          <button type="button" className="btn btn-primary" onClick={() => setEditing('new')}><Icon name="plus" /> {t('tasks.new')}</button>
        )}
      />
      <main className="main" id="contenido" tabIndex={-1}>
        <div className="barra-filtros">
          {wide && (
            <div className="seg" role="group" aria-label={t('tasks.title')}>
              <button type="button" className={mine ? 'on' : undefined} aria-pressed={mine} onClick={() => setMine(true)}>{t('tasks.tab_mine')}</button>
              <button type="button" className={!mine ? 'on' : undefined} aria-pressed={!mine} onClick={() => setMine(false)}>{t('tasks.tab_all')}</button>
            </div>
          )}
          <div className="seg" role="group" aria-label={t('tasks.col_status')}>
            {(['open', 'overdue', 'done', 'all'] as const).map((f) => (
              <button key={f} type="button" className={filter === f ? 'on' : undefined} aria-pressed={filter === f} onClick={() => setFilter(f)}>{t(`tasks.filter_${f}`)}</button>
            ))}
          </div>
        </div>
        <section className="card">
          <div className="tabla-wrap">
            <table className="table">
              <thead><tr><th>{t('tasks.col_title')}</th><th>{t('tasks.col_assignee')}</th><th>{t('tasks.col_due')}</th><th>{t('tasks.col_priority')}</th><th>{t('tasks.col_status')}</th><th /></tr></thead>
              <tbody>
                {loadError && <tr><td colSpan={6}><ErrorState text={t('common.load_error')} retryLabel={t('common.retry')} onRetry={() => void load()} /></td></tr>}
                {!loadError && rows === null && [1, 2, 3].map((i) => <tr key={i}><td colSpan={6}><span className="esq" style={{ width: `${25 + i * 15}%` }} /></td></tr>)}
                {rows?.length === 0 && <tr><td colSpan={6}><EmptyState title={t('tasks.empty_title')} text={t('tasks.empty_text')} /></td></tr>}
                {rows?.map((r) => (
                  <tr key={r.id} className={r.overdue ? 'vencida' : undefined}>
                    <td>
                      <button type="button" className="nm" style={{ background: 'none', border: 0, padding: 0, font: 'inherit', fontWeight: 800, cursor: 'pointer', color: 'inherit', textAlign: 'left' }} onClick={() => setEditing(r)}>{r.title}</button>
                      {r.created_by_name && <span className="sub2">{t('tasks.created_by', { name: r.created_by_name })}</span>}
                    </td>
                    <td>{r.assignee_name ?? '—'}{r.assignee_role_id && <span className="tag pe" style={{ marginLeft: 6 }}>{t('tasks.role_tag')}</span>}</td>
                    <td className="nowrap">{r.due_on ? formatDate(locale, r.due_on) : '—'}{r.overdue && <span className="tag er" style={{ marginLeft: 6 }}>{t('tasks.overdue')}</span>}</td>
                    <td><span className={`tag ${PRIORITY_TONE[r.priority] ?? 'gr'}`}>{t(`tasks.priority_${r.priority}`)}</span></td>
                    <td><span className={`st ${r.status === 'done' ? 'ok' : r.status === 'cancelled' ? 'gr' : 'wa'}`}>{t(`tasks.status_${r.status}`)}</span></td>
                    <td className="text-end nowrap">
                      {r.allowed_actions?.includes('complete') && can('workflow.tasks.complete') && <button type="button" className="btn btn-ter btn-sm" onClick={() => void transition(r, 'complete')}><Icon name="check" /> {t('tasks.complete')}</button>}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </section>
      </main>
      {editing !== null && (
        <TaskPanel
          task={editing === 'new' ? null : editing}
          onClose={() => setEditing(null)}
          onTransition={(row, action) => void transition(row, action)}
          onSaved={async (created) => {
            setEditing(null)
            toast(t(created ? 'tasks.created' : 'tasks.saved'))
            notifyInboxChanged()
            await load()
          }}
        />
      )}
      {toastNode}
    </>
  )
}

function TaskPanel({ task, onClose, onSaved, onTransition }: { task: Task | null; onClose: () => void; onSaved: (created: boolean) => Promise<void>; onTransition: (row: Task, action: 'complete' | 'cancel') => void }) {
  const { t } = useT()
  const { can } = useSession()
  const editable = task === null ? can('workflow.tasks.create') : can('workflow.tasks.update') && (task.status === 'pending' || task.status === 'in_progress')
  const [form, setForm] = useState({
    title: task?.title ?? '',
    description: task?.description ?? '',
    to: task?.assignee_role_id ? 'role' : 'user',
    assignee_user_id: task?.assignee_user_id ?? '',
    assignee_role_id: task?.assignee_role_id ?? '',
    due_on: task?.due_on ?? '',
    priority: task?.priority ?? 'normal',
  })
  const [people, setPeople] = useState<Option[]>([])
  const [roles, setRoles] = useState<Option[]>([])
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const custom = useCustomFields('task')
  const [customValues, setCustomValues] = useState<CustomValues>({})
  useEffect(() => {
    if (task) void api.get<{ data: { custom_fields?: CustomValues } }>(`/tasks/${task.id}`).then((r) => setCustomValues(r.data.custom_fields ?? {}))
  }, [task])
  const set = (key: keyof typeof form) => (e: { target: { value: string } }) => setForm((f) => ({ ...f, [key]: e.target.value }))

  useEffect(() => {
    if (!editable) return
    if (can('identity.users.view')) void api.get<{ data: Array<{ id: string; display_name: string; status: string }> }>('/users?per_page=100&status=active,invited').then((r) => setPeople(r.data.map((u) => ({ id: u.id, name: u.display_name }))))
    if (can('identity.roles.view')) void api.get<{ data: Array<{ id: string; name: string }> }>('/roles?per_page=100').then((r) => setRoles(r.data.map((x) => ({ id: x.id, name: x.name }))))
  }, [editable, can])

  async function submit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault()
    setBusy(true)
    setErrors({})
    setError(null)
    const body = {
      title: form.title,
      description: form.description || null,
      assignee_user_id: form.to === 'user' ? form.assignee_user_id || null : null,
      assignee_role_id: form.to === 'role' ? form.assignee_role_id || null : null,
      due_on: form.due_on || null,
      priority: form.priority,
      ...(custom.fields.length > 0 ? { custom_fields: customValues } : {}),
    }
    try {
      if (task) await api.request(`/tasks/${task.id}`, 'PATCH', body)
      else await api.post('/tasks', body)
      await onSaved(task === null)
    } catch (err) {
      const { fields, general } = formErrors(err, t)
      setErrors(fields)
      setError(general)
    } finally {
      setBusy(false)
    }
  }

  const field = (key: string, label: string, input: React.ReactNode) => (
    <div className="mb-3">
      <label className="form-label" htmlFor={`t-${key}`}>{label}</label>
      {input}
      {errors[key] && <div className="invalid-feedback" role="alert">{errors[key]}</div>}
    </div>
  )

  const actions = task && (
    <>
      {task.allowed_actions?.includes('cancel') && can('workflow.tasks.cancel') && <button type="button" className="btn btn-pel-sec" onClick={() => onTransition(task, 'cancel')}>{t('tasks.cancel_task')}</button>}
      {task.allowed_actions?.includes('complete') && can('workflow.tasks.complete') && <button type="button" className="btn btn-sec" onClick={() => onTransition(task, 'complete')}><Icon name="check" /> {t('tasks.complete')}</button>}
    </>
  )

  return (
    <SidePanel
      title={t(task ? 'tasks.form_edit' : 'tasks.form_new')}
      onClose={onClose}
      closeLabel={t('common.close')}
      footer={<>
        {actions}
        {editable ? <button type="submit" form="tarea" className="btn btn-primary" disabled={busy}>{busy && <span className="spin" aria-hidden="true" />}{t('tasks.save')}</button>
          : <button type="button" className="btn btn-sec" onClick={onClose}>{t('common.close')}</button>}
      </>}
    >
      <form id="tarea" onSubmit={submit} noValidate>
        <fieldset disabled={!editable} style={{ border: 0, padding: 0, margin: 0 }}>
          {field('title', t('tasks.f_title'), <input id="t-title" className={`form-control${errors.title ? ' is-invalid' : ''}`} value={form.title} onChange={set('title')} required maxLength={200} />)}
          {field('description', t('tasks.f_description'), <textarea id="t-description" className="form-control" rows={3} value={form.description} onChange={set('description')} />)}
          <div className="mb-3">
            <span className="form-label" id="t-to">{t('tasks.f_assign_to')}</span>
            <div className="seg" role="radiogroup" aria-labelledby="t-to">
              {(['user', 'role'] as const).map((k) => (
                <button key={k} type="button" role="radio" aria-checked={form.to === k} className={form.to === k ? 'on' : undefined} onClick={() => setForm((f) => ({ ...f, to: k }))}>{t(k === 'user' ? 'tasks.f_person' : 'tasks.f_role')}</button>
              ))}
            </div>
          </div>
          {form.to === 'user'
            ? field('assignee_user_id', t('tasks.f_assignee_person'), (
              <select id="t-assignee_user_id" className={`form-select${errors.assignee_user_id ? ' is-invalid' : ''}`} value={form.assignee_user_id} onChange={set('assignee_user_id')}>
                <option value="">{task?.assignee_user_id && !people.length ? task.assignee_name : t('tasks.pick')}</option>
                {people.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
              </select>
            ))
            : field('assignee_role_id', t('tasks.f_assignee_role'), (
              <select id="t-assignee_role_id" className={`form-select${errors.assignee_role_id ? ' is-invalid' : ''}`} value={form.assignee_role_id} onChange={set('assignee_role_id')}>
                <option value="">{task?.assignee_role_id && !roles.length ? task.assignee_name : t('tasks.pick')}</option>
                {roles.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
              </select>
            ))}
          {field('due_on', t('tasks.f_due_on'), <input id="t-due_on" type="date" className={`form-control${errors.due_on ? ' is-invalid' : ''}`} value={form.due_on ?? ''} onChange={set('due_on')} />)}
          {field('priority', t('tasks.f_priority'), (
            <select id="t-priority" className="form-select" value={form.priority} onChange={set('priority')}>
              {(['low', 'normal', 'high', 'urgent'] as const).map((p) => <option key={p} value={p}>{t(`tasks.priority_${p}`)}</option>)}
            </select>
          ))}
          <CustomFieldsEditor fields={custom.fields} values={customValues} onChange={setCustomValues} errors={errors} />
        </fieldset>
        {task?.created_by_name && <p className="small text-secondary">{t('tasks.created_by', { name: task.created_by_name })}</p>}
        {error && <div className="invalid-feedback" role="alert">{error}</div>}
      </form>
    </SidePanel>
  )
}
