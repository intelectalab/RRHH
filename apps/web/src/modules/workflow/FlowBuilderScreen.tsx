'use client'

import { useCallback, useEffect, useRef, useState, type KeyboardEvent, type PointerEvent as ReactPointerEvent } from 'react'
import Link from 'next/link'
import { useRouter } from 'next/navigation'
import { api, ApiRequestError } from '@/lib/api'
import { formErrors } from '@/lib/errors'
import { useT } from '@/lib/useT'
import { settingsTabs } from '@/modules/identity/settingsTabs'
import { useSession } from '@/modules/session/SessionProvider'
import { useDateTime } from '@/modules/session/useDateTime'
import { Icon } from '@/ui/components/Icon'
import { PageHeader } from '@/ui/components/PageHeader'
import { EmptyState, ErrorState } from '@/ui/components/States'
import { useToast } from '@/ui/components/Toast'
import { eventLabel } from './AutomationsScreen'
import { typeLabel } from './shared'
import { ADDABLE, addNode, canvasSize, connect, disconnect, edgePath, moveNode, NODE_H, NODE_W, patchNode, removeNode, type FlowGraph, type FlowNode, type NodeKind } from './flowGraph'

type Issue = { code: string; node: string | null; fields?: string[]; message: string }
type Flow = {
  id: string; name: string; description?: string | null; status: 'draft' | 'active' | 'paused' | 'retired'; graph: FlowGraph; issues: Issue[]
  version: number; has_draft: boolean; published_at?: string | null; workflow_id?: string | null; allowed_actions: string[]; updated_at: string
}
type Catalog = { triggers: Array<{ event: string; fields: string[] }>; operators: string[]; subject_types: string[] }
type Option = { id: string; name: string }
type Step = { node: string; kind: NodeKind; label: string; status: string; branch?: 'yes' | 'no'; days?: number; error?: string; params?: Record<string, unknown>; conditions?: Array<{ field: string; op: string; actual: unknown; result: boolean }> }
type TestResult = { path: Step[]; reached_end: boolean; issues: Issue[] }
type Cond = { field: string; op: string; value?: unknown }

const tone = (s: string) => (s === 'active' ? 'ok' : s === 'draft' ? 'wa' : 'gr')

/** Configuración → Constructor de flujos (WFL-6): lista de flujos visuales. */
export function FlowBuilderListScreen() {
  const { t } = useT()
  const { can } = useSession()
  const fmt = useDateTime()
  const router = useRouter()
  const { toast, node: toastNode } = useToast()
  const [flows, setFlows] = useState<Flow[] | null>(null)
  const [error, setError] = useState(false)

  const load = useCallback(async () => {
    try {
      setFlows((await api.get<{ data: Flow[] }>('/visual-flows?per_page=100')).data)
      setError(false)
    } catch {
      setError(true)
    }
  }, [])
  useEffect(() => { void load() }, [load])

  async function create() {
    const name = window.prompt(t('vflow.new_name'))
    if (!name?.trim()) return
    try {
      const r = await api.post<{ data: Flow }>('/visual-flows', { name: name.trim() })
      router.push(`/configuracion/constructor/${r.data.id}`)
    } catch (e) {
      toast(e instanceof ApiRequestError ? e.message : t('auth.generic_error'), 'error')
    }
  }

  const canCreate = can('workflow.workflows.create')
  return (
    <>
      <PageHeader title={t('vflow.title')} sub={t('vflow.sub')} tabs={settingsTabs(t, can, 'builder')}
        actions={canCreate && <button type="button" className="btn btn-primary" onClick={() => void create()}><Icon name="plus" /> {t('vflow.new')}</button>} />
      <main className="main" id="contenido" tabIndex={-1}>
        <section className="card">
          <div className="tabla-wrap">
            <table className="table">
              <thead><tr><th>{t('vflow.col_name')}</th><th>{t('vflow.col_version')}</th><th>{t('vflow.col_updated')}</th><th>{t('vflow.col_status')}</th></tr></thead>
              <tbody>
                {error && <tr><td colSpan={4}><ErrorState text={t('common.load_error')} retryLabel={t('common.retry')} onRetry={() => void load()} /></td></tr>}
                {!error && flows === null && <tr><td colSpan={4}><span className="esq" style={{ width: '40%' }} /></td></tr>}
                {flows?.length === 0 && <tr><td colSpan={4}><EmptyState title={t('vflow.empty_title')} text={t('vflow.empty_text')} action={canCreate && <button type="button" className="btn btn-primary btn-sm" onClick={() => void create()}><Icon name="plus" /> {t('vflow.new')}</button>} /></td></tr>}
                {flows?.map((f) => (
                  <tr key={f.id}>
                    <td><Link href={`/configuracion/constructor/${f.id}`}><b>{f.name}</b></Link>{f.description && <span className="sub2">{f.description}</span>}</td>
                    <td>{f.version > 0 ? t('vflow.version_n', { n: f.version }) : '—'}{f.has_draft && <span className="sub2">{t('vflow.has_draft')}</span>}</td>
                    <td className="nowrap">{fmt(f.updated_at)}</td>
                    <td><span className={`st ${tone(f.status)}`}>{t(`vflow.status_${f.status}`)}</span></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </section>
      </main>
      {toastNode}
    </>
  )
}

/** Editor del constructor: lienzo con nodos y conexiones, panel de propiedades y modo de prueba. */
export function FlowEditorScreen({ id }: { id: string }) {
  const { t } = useT()
  const { can } = useSession()
  const router = useRouter()
  const { toast, node: toastNode } = useToast()
  const [flow, setFlow] = useState<Flow | null>(null)
  const [graph, setGraph] = useState<FlowGraph>({ nodes: [], edges: [] })
  const [catalog, setCatalog] = useState<Catalog | null>(null)
  const [people, setPeople] = useState<Option[]>([])
  const [roles, setRoles] = useState<Option[]>([])
  const [selected, setSelected] = useState<string | null>(null)
  const [dirty, setDirty] = useState(false)
  const [busy, setBusy] = useState(false)
  const [loadError, setLoadError] = useState(false)
  const [sample, setSample] = useState('')
  const [test, setTest] = useState<TestResult | null>(null)
  const [message, setMessage] = useState<string | null>(null)
  const drag = useRef<{ id: string; dx: number; dy: number } | null>(null)
  const canvasRef = useRef<HTMLDivElement | null>(null)
  const editable = can('workflow.workflows.update') && flow?.status !== 'retired'

  const load = useCallback(async () => {
    try {
      const [f, c] = await Promise.all([api.get<{ data: Flow }>(`/visual-flows/${id}`), api.get<{ data: Catalog }>('/workflows/catalog')])
      setFlow(f.data)
      setGraph(f.data.graph)
      setCatalog(c.data)
      setDirty(false)
      setLoadError(false)
    } catch {
      setLoadError(true)
    }
  }, [id])
  useEffect(() => { void load() }, [load])
  useEffect(() => {
    if (can('identity.users.view')) void api.get<{ data: Array<{ id: string; display_name: string }> }>('/users?per_page=100&status=active').then((r) => setPeople(r.data.map((u) => ({ id: u.id, name: u.display_name }))))
    if (can('identity.roles.view')) void api.get<{ data: Array<{ id: string; name: string }> }>('/roles?per_page=100').then((r) => setRoles(r.data.map((x) => ({ id: x.id, name: x.name }))))
  }, [can])

  const change = (g: FlowGraph) => { setGraph(g); setDirty(true); setTest(null) }

  async function save(): Promise<boolean> {
    setBusy(true)
    try {
      const r = await api.request<{ data: Flow }>(`/visual-flows/${id}`, 'PATCH', { graph })
      setFlow(r.data)
      setDirty(false)
      setMessage(null)
      return true
    } catch (e) {
      const { general, fields } = formErrors(e, t)
      setMessage(general ?? Object.values(fields)[0] ?? t('auth.generic_error'))
      return false
    } finally {
      setBusy(false)
    }
  }

  async function act(action: 'publish' | 'pause' | 'activate' | 'retire') {
    if (action === 'retire' && !window.confirm(t('vflow.confirm_retire', { name: flow?.name ?? '' }))) return
    if (dirty && !(await save())) return
    setBusy(true)
    try {
      const r = await api.post<{ data: Flow }>(`/visual-flows/${id}/${action}`, {})
      toast(t(`vflow.done_${action}`))
      if (action === 'retire') router.push('/configuracion/constructor')
      else { setFlow(r.data); setGraph(r.data.graph) }
    } catch (e) {
      const { general, fields } = formErrors(e, t)
      setMessage(general ?? (Object.values(fields).join(' ') || t('auth.generic_error')))
    } finally {
      setBusy(false)
    }
  }

  async function runTest() {
    let payload: unknown = {}
    try {
      payload = sample.trim() === '' ? {} : JSON.parse(sample)
    } catch {
      setMessage(t('auto.sample_invalid'))
      return
    }
    try {
      setTest((await api.post<{ data: TestResult }>(`/visual-flows/${id}/test`, { payload, graph })).data)
      setMessage(null)
    } catch (e) {
      setMessage(e instanceof ApiRequestError ? e.message : t('auth.generic_error'))
    }
  }

  // Arrastre libre de nodos con puntero (ratón o dedo); con teclado, flechas mueven el nodo enfocado.
  const onDown = (n: FlowNode) => (e: ReactPointerEvent<HTMLDivElement>) => {
    setSelected(n.id)
    if (!editable || e.button !== 0) return
    const box = canvasRef.current?.getBoundingClientRect()
    if (!box) return
    drag.current = { id: n.id, dx: e.clientX - box.left + (canvasRef.current?.scrollLeft ?? 0) - n.x, dy: e.clientY - box.top + (canvasRef.current?.scrollTop ?? 0) - n.y }
    e.currentTarget.setPointerCapture(e.pointerId)
  }
  const onMove = (e: ReactPointerEvent<HTMLDivElement>) => {
    const d = drag.current
    const box = canvasRef.current?.getBoundingClientRect()
    if (!d || !box) return
    change(moveNode(graph, d.id, e.clientX - box.left + (canvasRef.current?.scrollLeft ?? 0) - d.dx, e.clientY - box.top + (canvasRef.current?.scrollTop ?? 0) - d.dy))
  }
  const onUp = () => { drag.current = null }
  const onKey = (n: FlowNode) => (e: KeyboardEvent<HTMLDivElement>) => {
    const step = { ArrowLeft: [-20, 0], ArrowRight: [20, 0], ArrowUp: [0, -20], ArrowDown: [0, 20] }[e.key]
    if (step && editable) {
      e.preventDefault()
      change(moveNode(graph, n.id, n.x + step[0], n.y + step[1]))
    } else if (e.key === 'Enter' || e.key === ' ') {
      e.preventDefault()
      setSelected(n.id)
    }
  }

  if (loadError) return <main className="main"><ErrorState text={t('common.load_error')} retryLabel={t('common.retry')} onRetry={() => void load()} /></main>
  if (!flow || !catalog) return <main className="main"><span className="esq" style={{ width: '50%' }} /></main>

  const size = canvasSize(graph)
  const visited = new Set(test?.path.map((s) => s.node) ?? [])
  const issuesBy = new Map<string, Issue[]>()
  for (const i of flow.issues) if (i.node) issuesBy.set(i.node, [...(issuesBy.get(i.node) ?? []), i])
  const node = graph.nodes.find((n) => n.id === selected) ?? null
  const startTrigger = String(graph.nodes.find((n) => n.kind === 'start')?.config.trigger_event ?? '')
  const fields = catalog.triggers.find((x) => x.event === startTrigger)?.fields ?? []
  const nodeName = (n: FlowNode) => n.label || t(`vflow.kind_${n.kind}`)

  return (
    <>
      <PageHeader title={flow.name} sub={<><Link href="/configuracion/constructor">{t('vflow.back')}</Link> · <span className={`st ${tone(flow.status)}`}>{t(`vflow.status_${flow.status}`)}</span>{flow.version > 0 && <> · {t('vflow.version_n', { n: flow.version })}</>}</>}
        actions={<>
          {editable && <button type="button" className="btn btn-sec" disabled={busy || !dirty} onClick={() => void save()}>{t('vflow.save')}</button>}
          {can('workflow.workflows.activate') && flow.allowed_actions.includes('publish') && (flow.status === 'draft' || flow.has_draft || dirty) && <button type="button" className="btn btn-primary" disabled={busy} onClick={() => void act('publish')}>{t('vflow.publish')}</button>}
          {can('workflow.workflows.pause') && flow.allowed_actions.includes('pause') && <button type="button" className="btn btn-ter" disabled={busy} onClick={() => void act('pause')}>{t('vflow.pause')}</button>}
          {can('workflow.workflows.activate') && flow.allowed_actions.includes('activate') && <button type="button" className="btn btn-ter" disabled={busy} onClick={() => void act('activate')}>{t('vflow.activate')}</button>}
          {can('workflow.workflows.retire') && flow.allowed_actions.includes('retire') && <button type="button" className="btn btn-ter" disabled={busy} onClick={() => void act('retire')}>{t('vflow.retire')}</button>}
        </>} />
      <main className="main" id="contenido" tabIndex={-1}>
        {message && <div className="invalid-feedback mb-2" role="alert" style={{ display: 'block' }}>{message}</div>}
        {dirty && <p className="small text-secondary" role="status">{t('vflow.unsaved')}</p>}
        {editable && (
          <div className="vf-paleta" role="toolbar" aria-label={t('vflow.palette')}>
            {ADDABLE.map((k) => (
              <button key={k} type="button" className="btn btn-sec btn-sm" onClick={() => { const r = addNode(graph, k); change(r.graph); setSelected(r.id) }}><Icon name="plus" /> {t(`vflow.kind_${k}`)}</button>
            ))}
          </div>
        )}
        <div className="vf-layout">
          <section className="card vf-canvas-card" aria-label={t('vflow.canvas')}>
            <div className="vf-canvas" ref={canvasRef} onPointerMove={onMove} onPointerUp={onUp} onPointerCancel={onUp}>
              <div style={{ position: 'relative', width: size.w, height: size.h }}>
                <svg className="vf-edges" width={size.w} height={size.h} aria-hidden="true">
                  <defs><marker id="vf-arrow" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M 0 0 L 10 5 L 0 10 z" /></marker></defs>
                  {graph.edges.map((e) => {
                    const a = graph.nodes.find((n) => n.id === e.from)
                    const b = graph.nodes.find((n) => n.id === e.to)
                    if (!a || !b) return null
                    const p = edgePath(a, b, e.branch)
                    const hot = visited.has(e.from) && visited.has(e.to)
                    return (
                      <g key={`${e.from}-${e.branch ?? ''}`} className={hot ? 'vf-edge on' : 'vf-edge'}>
                        <path d={p.d} markerEnd="url(#vf-arrow)" />
                        {e.branch && <text x={p.mx} y={p.my}>{t(`vflow.branch_${e.branch}`)}</text>}
                      </g>
                    )
                  })}
                </svg>
                {graph.nodes.map((n) => {
                  const problems = issuesBy.get(n.id) ?? []
                  return (
                    <div key={n.id} role="button" tabIndex={0} aria-pressed={selected === n.id}
                      aria-label={`${nodeName(n)} · ${t(`vflow.kind_${n.kind}`)}${problems.length ? ` · ${problems.map((p) => p.message).join(' ')}` : ''}`}
                      className={`vf-node vf-${n.kind}${selected === n.id ? ' sel' : ''}${visited.has(n.id) ? ' on' : ''}${problems.length ? ' bad' : ''}`}
                      style={{ left: n.x, top: n.y, width: NODE_W, height: NODE_H }}
                      onPointerDown={onDown(n)} onKeyDown={onKey(n)}>
                      <span className="vf-kind">{t(`vflow.kind_${n.kind}`)}</span>
                      <b>{n.kind === 'start' && !n.label ? (startTrigger ? eventLabel(t, startTrigger) : t('vflow.pick_trigger')) : nodeName(n)}</b>
                      {problems.length > 0 && <span className="vf-warn" aria-hidden="true"><Icon name="info" /></span>}
                    </div>
                  )
                })}
              </div>
            </div>
          </section>
          <aside className="card vf-props" aria-label={t('vflow.properties')}>
            {node ? (
              <NodeProps key={node.id} node={node} graph={graph} catalog={catalog} fields={fields} people={people} roles={roles} disabled={!editable}
                issues={issuesBy.get(node.id) ?? []} onChange={change} onRemove={() => { change(removeNode(graph, node.id)); setSelected(null) }} />
            ) : (
              <p className="small text-secondary">{t('vflow.select_help')}</p>
            )}
            <h3 className="vf-h">{t('vflow.issues')}</h3>
            {flow.issues.length === 0 ? <p className="small"><span className="st ok">{t('vflow.no_issues')}</span></p> : (
              <ul className="vf-issues">
                {flow.issues.map((i, k) => (
                  <li key={k}>{i.node ? <button type="button" className="link-btn" onClick={() => setSelected(i.node)}>{nodeName(graph.nodes.find((n) => n.id === i.node) ?? { id: '', kind: 'end', label: i.node, x: 0, y: 0, config: {} })}</button> : null}{i.node ? ': ' : ''}{i.message}</li>
                ))}
              </ul>
            )}
            {dirty && <p className="small text-secondary">{t('vflow.issues_after_save')}</p>}
            {can('workflow.workflows.update') && (
              <>
                <h3 className="vf-h">{t('vflow.test')}</h3>
                <label className="form-label" htmlFor="vf-sample">{t('auto.sample')}</label>
                <textarea id="vf-sample" className="form-control" rows={3} spellCheck={false} placeholder={`{"${fields[0] ?? 'campo'}": "…"}`} value={sample} onChange={(e) => setSample(e.target.value)} />
                <button type="button" className="btn btn-sec btn-sm mt-2" onClick={() => void runTest()}>{t('vflow.run_test')}</button>
                {test && (
                  <ol className="small vf-path" role="status">
                    {test.path.map((s, k) => {
                      const n = graph.nodes.find((x) => x.id === s.node)
                      return (
                        <li key={k}>
                          <b>{n ? nodeName(n) : s.node}</b> · {t(`vflow.step_${s.status}`, { days: s.days ?? 0 })}
                          {s.branch && <> → {t(`vflow.branch_${s.branch}`)}</>}
                          {typeof s.params?.title === 'string' && <span className="sub2">{s.params.title}</span>}
                          {s.conditions?.map((c, j) => <span key={j} className="sub2">{c.field} {t(`auto.op_${c.op}`)} · {String(c.actual ?? '—')} {c.result ? '✓' : '✗'}</span>)}
                          {s.error && <span className="invalid-feedback" style={{ display: 'block' }}>{s.error}</span>}
                        </li>
                      )
                    })}
                    {!test.reached_end && <li>{t('vflow.test_no_end')}</li>}
                  </ol>
                )}
              </>
            )}
          </aside>
        </div>
      </main>
      {toastNode}
    </>
  )
}

function NodeProps({ node, graph, catalog, fields, people, roles, disabled, issues, onChange, onRemove }: {
  node: FlowNode; graph: FlowGraph; catalog: Catalog; fields: string[]; people: Option[]; roles: Option[]; disabled: boolean; issues: Issue[]
  onChange: (g: FlowGraph) => void; onRemove: () => void
}) {
  const { t } = useT()
  const cfg = node.config
  const set = (k: string, v: unknown) => onChange(patchNode(graph, node.id, { config: { ...cfg, [k]: v === '' || v === undefined ? undefined : v } }))
  const bad = (k: string) => issues.some((i) => i.fields?.includes(k))
  const cls = (base: string, k: string) => `${base}${bad(k) ? ' is-invalid' : ''}`
  const others = graph.nodes.filter((n) => n.id !== node.id && n.kind !== 'start')
  const branches: Array<'yes' | 'no' | null> = node.kind === 'condition' ? ['yes', 'no'] : node.kind === 'end' ? [] : [null]
  const rules = (Array.isArray(cfg.rules) ? cfg.rules : []) as Cond[]
  const setRules = (rs: Cond[]) => set('rules', rs)
  const list = `vf-fields-${node.id}`
  const who = (u?: unknown, r?: unknown, f?: unknown) => (f ? `f:${String(f)}` : u ? `u:${String(u)}` : r ? `r:${String(r)}` : '')
  const userFields = fields.filter((f) => f.endsWith('user_id') || f.endsWith('_by'))

  return (
    <fieldset disabled={disabled}>
      <datalist id={list}>{fields.map((f) => <option key={f} value={f} />)}</datalist>
      <div className="nivel-cab"><b>{t(`vflow.kind_${node.kind}`)}</b>{node.kind !== 'start' && <button type="button" className="ibtn" aria-label={t('vflow.remove_node')} onClick={onRemove}><Icon name="trash" /></button>}</div>
      <div className="mb-2">
        <label className="form-label" htmlFor="vf-label">{t('vflow.label')}</label>
        <input id="vf-label" className="form-control" maxLength={120} value={node.label} onChange={(e) => onChange(patchNode(graph, node.id, { label: e.target.value }))} />
      </div>
      {node.kind === 'start' && (
        <div className="mb-2">
          <label className="form-label" htmlFor="vf-trigger">{t('auto.trigger')}</label>
          <select id="vf-trigger" className={cls('form-select', 'trigger_event')} value={String(cfg.trigger_event ?? '')} onChange={(e) => set('trigger_event', e.target.value)}>
            <option value="">{t('vflow.pick_trigger')}</option>
            {catalog.triggers.map((x) => <option key={x.event} value={x.event}>{eventLabel(t, x.event)}</option>)}
          </select>
          {fields.length > 0 && <p className="small text-secondary" style={{ marginTop: 4 }}>{t('auto.fields_help', { fields: fields.join(', ') })}</p>}
        </div>
      )}
      {node.kind === 'condition' && (
        <>
          {rules.length > 1 && (
            <div className="seg mb-2" role="radiogroup" aria-label={t('auto.match')}>
              {(['all', 'any'] as const).map((m) => <button key={m} type="button" role="radio" aria-checked={(cfg.match ?? 'all') === m} className={(cfg.match ?? 'all') === m ? 'on' : undefined} onClick={() => set('match', m)}>{t(`auto.match_${m}`)}</button>)}
            </div>
          )}
          {rules.map((c, i) => (
            <div key={i} className="vf-cond">
              <input className={cls('form-control', `rules.${i}.field`)} list={list} aria-label={t('auto.cond_field')} placeholder={t('auto.cond_field')} value={c.field} onChange={(e) => setRules(rules.map((x, j) => (j === i ? { ...x, field: e.target.value } : x)))} />
              <select className="form-select" aria-label={t('auto.cond_op')} value={c.op} onChange={(e) => setRules(rules.map((x, j) => (j === i ? { ...x, op: e.target.value } : x)))}>
                {catalog.operators.map((o) => <option key={o} value={o}>{t(`auto.op_${o}`)}</option>)}
              </select>
              {!['exists', 'not_exists'].includes(c.op) && (
                <input className={cls('form-control', `rules.${i}.value`)} aria-label={t('auto.cond_value')} placeholder={t('auto.cond_value')} value={Array.isArray(c.value) ? c.value.join(', ') : String(c.value ?? '')}
                  onChange={(e) => setRules(rules.map((x, j) => (j === i ? { ...x, value: ['in', 'not_in'].includes(x.op) ? e.target.value.split(',').map((v) => v.trim()).filter(Boolean) : e.target.value } : x)))} />
              )}
              <button type="button" className="ibtn" aria-label={t('auto.remove_condition')} onClick={() => setRules(rules.filter((_, j) => j !== i))}><Icon name="close" /></button>
            </div>
          ))}
          <button type="button" className={`btn btn-ter btn-sm${bad('rules') ? ' text-danger' : ''}`} onClick={() => setRules([...rules, { field: fields[0] ?? '', op: 'eq', value: '' }])}><Icon name="plus" /> {t('auto.add_condition')}</button>
        </>
      )}
      {(node.kind === 'action' || node.kind === 'notification') && (
        <div className="mb-2">
          <label className="form-label" htmlFor="vf-title">{t('auto.a_title')}</label>
          <input id="vf-title" className={cls('form-control', 'title')} maxLength={200} value={String(cfg.title ?? '')} onChange={(e) => set('title', e.target.value)} />
        </div>
      )}
      {node.kind === 'action' && (
        <>
          <div className="mb-2">
            <label className="form-label" htmlFor="vf-who">{t('auto.a_assignee')}</label>
            <select id="vf-who" className={cls('form-select', 'assignee_user_id')} value={who(cfg.assignee_user_id, cfg.assignee_role_id, cfg.assignee_field)}
              onChange={(e) => { const v = e.target.value; onChange(patchNode(graph, node.id, { config: { ...cfg, assignee_user_id: v.startsWith('u:') ? v.slice(2) : undefined, assignee_role_id: v.startsWith('r:') ? v.slice(2) : undefined, assignee_field: v.startsWith('f:') ? v.slice(2) : undefined } })) }}>
              <option value="">{t('flows.pick')}</option>
              {roles.map((r) => <option key={r.id} value={`r:${r.id}`}>{t('flows.kind_role')}: {r.name}</option>)}
              {people.map((p) => <option key={p.id} value={`u:${p.id}`}>{p.name}</option>)}
              {userFields.map((f) => <option key={f} value={`f:${f}`}>{t('auto.from_field', { field: f })}</option>)}
            </select>
          </div>
          <div className="mb-2">
            <label className="form-label" htmlFor="vf-due">{t('auto.a_due')}</label>
            <input id="vf-due" type="number" min={0} max={365} inputMode="numeric" className={cls('form-control', 'due_in_days')} value={cfg.due_in_days === undefined ? '' : String(cfg.due_in_days)} onChange={(e) => set('due_in_days', e.target.value === '' ? undefined : Number(e.target.value))} />
          </div>
        </>
      )}
      {node.kind === 'notification' && (
        <>
          <div className="mb-2">
            <label className="form-label" htmlFor="vf-msg">{t('auto.a_message')}</label>
            <textarea id="vf-msg" className={cls('form-control', 'message')} rows={2} maxLength={1000} value={String(cfg.message ?? '')} onChange={(e) => set('message', e.target.value)} />
          </div>
          <div className="mb-2">
            <label className="form-label" htmlFor="vf-to">{t('auto.a_recipients')}</label>
            <select id="vf-to" multiple size={4} className={cls('form-select', 'user_ids')}
              value={[...((cfg.role_ids as string[] | undefined) ?? []).map((x) => `r:${x}`), ...((cfg.user_ids as string[] | undefined) ?? []).map((x) => `u:${x}`), ...(cfg.user_field ? [`f:${String(cfg.user_field)}`] : [])]}
              onChange={(e) => { const vs = Array.from(e.target.selectedOptions, (o) => o.value); onChange(patchNode(graph, node.id, { config: { ...cfg, role_ids: vs.filter((v) => v.startsWith('r:')).map((v) => v.slice(2)), user_ids: vs.filter((v) => v.startsWith('u:')).map((v) => v.slice(2)), user_field: vs.find((v) => v.startsWith('f:'))?.slice(2) } })) }}>
              {roles.map((r) => <option key={r.id} value={`r:${r.id}`}>{t('flows.kind_role')}: {r.name}</option>)}
              {people.map((p) => <option key={p.id} value={`u:${p.id}`}>{p.name}</option>)}
              {userFields.map((f) => <option key={f} value={`f:${f}`}>{t('auto.from_field', { field: f })}</option>)}
            </select>
          </div>
        </>
      )}
      {node.kind === 'approval' && (
        <>
          <div className="mb-2">
            <label className="form-label" htmlFor="vf-st">{t('flows.subject_type')}</label>
            <select id="vf-st" className={cls('form-select', 'subject_type')} value={String(cfg.subject_type ?? '')} onChange={(e) => set('subject_type', e.target.value)}>
              <option value="">{t('flows.pick')}</option>
              {catalog.subject_types.map((s) => <option key={s} value={s}>{typeLabel(t, s)}</option>)}
            </select>
          </div>
          <div className="mb-2">
            <label className="form-label" htmlFor="vf-sid">{t('auto.a_subject_field')}</label>
            <input id="vf-sid" list={list} className={cls('form-control', 'subject_id_field')} value={String(cfg.subject_id_field ?? '')} onChange={(e) => set('subject_id_field', e.target.value)} />
          </div>
          <div className="mb-2">
            <label className="form-label" htmlFor="vf-req">{t('auto.a_requester_field')}</label>
            <input id="vf-req" list={list} className={cls('form-control', 'requester_field')} value={String(cfg.requester_field ?? '')} onChange={(e) => set('requester_field', e.target.value)} />
          </div>
        </>
      )}
      {node.kind === 'wait' && (
        <div className="mb-2">
          <label className="form-label" htmlFor="vf-days">{t('vflow.wait_days')}</label>
          <input id="vf-days" type="number" min={1} max={90} inputMode="numeric" className={cls('form-control', 'days')} value={String(cfg.days ?? '')} onChange={(e) => set('days', e.target.value === '' ? undefined : Number(e.target.value))} />
        </div>
      )}
      {(node.kind === 'action' || node.kind === 'notification') && <p className="small text-secondary">{t('auto.template_help')}</p>}

      {branches.length > 0 && <h3 className="vf-h">{t('vflow.exits')}</h3>}
      {branches.map((b) => {
        const edge = graph.edges.find((e) => e.from === node.id && e.branch === b)
        return (
          <div key={b ?? 'next'} className="mb-2">
            <label className="form-label" htmlFor={`vf-exit-${b ?? 'next'}`}>{t(b ? `vflow.exit_${b}` : 'vflow.exit_next')}</label>
            <select id={`vf-exit-${b ?? 'next'}`} className="form-select" value={edge?.to ?? ''} onChange={(e) => onChange(e.target.value === '' ? disconnect(graph, node.id, b) : connect(graph, node.id, e.target.value, b))}>
              <option value="">{t('vflow.not_connected')}</option>
              {others.map((n) => <option key={n.id} value={n.id}>{n.label || t(`vflow.kind_${n.kind}`)} ({n.id})</option>)}
            </select>
          </div>
        )
      })}
      {issues.length > 0 && <ul className="vf-issues">{issues.map((i, k) => <li key={k}>{i.message}</li>)}</ul>}
    </fieldset>
  )
}
