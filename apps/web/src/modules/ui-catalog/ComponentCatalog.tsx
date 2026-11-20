'use client'

import { useCallback, useState, type ReactNode } from 'react'
import { useT } from '@/lib/useT'
import { searchActiveUsers } from '@/modules/identity/peopleSearch'
import { useSession } from '@/modules/session/SessionProvider'
import { Button } from '@/ui/components/Button'
import { Calendar, type CalendarItem } from '@/ui/components/Calendar'
import { Card } from '@/ui/components/Card'
import { BarChart, DonutChart, Heatmap, LineChart } from '@/ui/components/Charts'
import { addDaysIso } from '@/ui/chartMath'
import { Disclosure } from '@/ui/components/Disclosure'
import { Kanban, type KanbanCard } from '@/ui/components/Kanban'
import { DataTable } from '@/ui/components/DataTable'
import { FileUploader } from '@/ui/components/FileUploader'
import { Icon } from '@/ui/components/Icon'
import { MenuButton } from '@/ui/components/MenuButton'
import { Modal } from '@/ui/components/Modal'
import { Notice } from '@/ui/components/Notice'
import { PageHeader } from '@/ui/components/PageHeader'
import { PersonPicker, type Person } from '@/ui/components/PersonPicker'
import { SidePanel } from '@/ui/components/SidePanel'
import { SkeletonBlock, SkeletonRows, SkeletonText } from '@/ui/components/Skeleton'
import { EmptyState, ErrorState } from '@/ui/components/States'
import { StatusBadge, Tag } from '@/ui/components/Status'
import { Tabs } from '@/ui/components/Tabs'
import { Timeline } from '@/ui/components/Timeline'
import { useToast } from '@/ui/components/Toast'
import { Tooltip } from '@/ui/components/Tooltip'
import { Tree, type TreeNode } from '@/ui/components/Tree'

const SECTIONS = ['buttons', 'cards', 'tabs', 'overlays', 'timeline', 'status', 'notices', 'tooltips', 'menus', 'people', 'files', 'skeletons', 'states', 'tables', 'disclosure', 'charts', 'kanban', 'calendar', 'tree'] as const
type CatalogUser = { id: string; display_name: string; status: string; last_login_at?: string | null }
type Section = (typeof SECTIONS)[number]

/** Bloque de un estado del componente con su nombre (normal, foco, deshabilitado, error, cargando). */
function Estado({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div className="cat-estado">
      <span className="cat-estado-t">{label}</span>
      <div className="cat-estado-c">{children}</div>
    </div>
  )
}

/**
 * UI-3 · Catálogo navegable de la biblioteca de componentes (/componentes). Cada ejemplo es el
 * componente real con sus estados; todo control del catálogo hace algo (abre, cierra, cambia, avisa).
 */
export function ComponentCatalog() {
  const { t, locale } = useT()
  const { can } = useSession()
  const { toast, node: toastNode } = useToast()
  const [busy, setBusy] = useState(false)
  const [tab, setTab] = useState('summary')
  const [panel, setPanel] = useState(false)
  const [modal, setModal] = useState<'normal' | 'danger' | null>(null)
  const [noticeOpen, setNoticeOpen] = useState(true)
  const [person, setPerson] = useState<Person | null>(null)
  const [files, setFiles] = useState<File[]>([])
  const canSearchPeople = can('identity.users.view')
  const search = useCallback((q: string) => searchActiveUsers(q), [])

  const s = (key: string) => t(`uicat.${key}`)
  const did = (what: string) => toast(t('uicat.did', { what }))
  const [cards, setCards] = useState<KanbanCard[]>([
    { id: 'k1', column: 'todo', title: s('demo_card1') },
    { id: 'k2', column: 'todo', title: s('demo_card2') },
    { id: 'k3', column: 'doing', title: s('demo_card3') },
  ])
  const today = new Date().toISOString().slice(0, 10)
  const [calItems, setCalItems] = useState<CalendarItem[]>([
    { key: 'c1', title: s('demo_event1'), starts_on: today, ends_on: today, dot: 'tasks', movable: true },
    { key: 'c2', title: s('demo_event2'), starts_on: addDaysIso(today, 3), ends_on: addDaysIso(today, 3), dot: 'holidays' },
  ])
  const [treeSel, setTreeSel] = useState<string | null>(null)
  const chartLabels = { category: s('chart_category'), showTable: t('ui.chart_show_table'), hideTable: t('ui.chart_hide_table') }
  const months = [0, 1, 2, 3, 4, 5].map((m) => new Intl.DateTimeFormat(locale, { month: 'short', timeZone: 'UTC' }).format(new Date(Date.UTC(2026, m, 1))))
  const weekdayNames = [5, 6, 7, 8, 9].map((d) => new Intl.DateTimeFormat(locale, { weekday: 'short', timeZone: 'UTC' }).format(new Date(Date.UTC(2026, 0, d))))
  const tree: TreeNode[] = [
    { id: 'g', text: s('demo_unit_gm'), label: s('demo_unit_gm'), children: [
      { id: 'c', text: s('demo_unit_sales'), label: s('demo_unit_sales'), meta: '12', children: [{ id: 'v', text: s('demo_unit_team'), label: s('demo_unit_team'), meta: '9' }] },
      { id: 'o', text: s('demo_unit_ops'), label: s('demo_unit_ops'), meta: '20' },
    ] },
  ]

  function runLoading() {
    setBusy(true)
    window.setTimeout(() => { setBusy(false); did(s('btn_save')) }, 1200)
  }

  const section = (id: Section, children: ReactNode) => (
    <section id={`c-${id}`} className="cat-seccion" aria-labelledby={`c-${id}-t`}>
      <h2 id={`c-${id}-t`}>{s(`sec_${id}`)}</h2>
      <p className="text-secondary">{s(`sec_${id}_help`)}</p>
      {children}
    </section>
  )

  return (
    <>
      <PageHeader title={s('title')} sub={s('sub')} />
      <main id="contenido" className="main" tabIndex={-1}>
        <div className="cat-layout">
          <nav className="cat-indice" aria-label={s('index')}>
            <ul>
              {SECTIONS.map((id) => <li key={id}><a href={`#c-${id}`}>{s(`sec_${id}`)}</a></li>)}
            </ul>
          </nav>
          <div className="min-w-0">
            <Notice tone="info" title={s('keyboard_title')}>{s('keyboard_text')}</Notice>

            {section('buttons', (
              <div className="cat-estados">
                <Estado label={s('st_normal')}>
                  <Button variant="primary" onClick={() => did(s('btn_save'))}>{s('btn_save')}</Button>
                  <Button onClick={() => did(s('btn_cancel'))}>{s('btn_cancel')}</Button>
                  <Button variant="tertiary" icon="plus" onClick={() => did(s('btn_add'))}>{s('btn_add')}</Button>
                  <Button variant="danger" icon="trash" onClick={() => setModal('danger')}>{s('btn_delete')}</Button>
                </Estado>
                <Estado label={s('st_focus')}>
                  <Button variant="primary" className="cat-foco" onClick={() => did(s('btn_save'))}>{s('btn_save')}</Button>
                  <span className="small text-secondary">{s('st_focus_help')}</span>
                </Estado>
                <Estado label={s('st_disabled')}>
                  <Button variant="primary" disabled>{s('btn_save')}</Button>
                  <Button disabled>{s('btn_cancel')}</Button>
                </Estado>
                <Estado label={s('st_loading')}>
                  <Button variant="primary" loading={busy} onClick={runLoading}>{s(busy ? 'btn_saving' : 'btn_save')}</Button>
                  <span className="small text-secondary">{s('st_loading_help')}</span>
                </Estado>
              </div>
            ))}

            {section('cards', (
              <div className="cat-grid2">
                <Card title={s('card_title')} context={s('card_context')} actions={<Button size="sm" onClick={() => did(s('btn_edit'))}>{s('btn_edit')}</Button>}>
                  {s('card_body')}
                </Card>
                <Card title={s('card_title_flush')} flush footer={<Button variant="tertiary" size="sm" onClick={() => did(s('btn_view_all'))}>{s('btn_view_all')}</Button>}>
                  <ul className="lista-compacta cat-pad">
                    <li><StatusBadge tone="ok">{s('status_active')}</StatusBadge></li>
                    <li><StatusBadge tone="wa">{s('status_pending')}</StatusBadge></li>
                  </ul>
                </Card>
              </div>
            ))}

            {section('tabs', (
              <Card>
                <Tabs
                  label={s('sec_tabs')}
                  value={tab}
                  onChange={setTab}
                  items={[
                    { id: 'summary', label: s('tab_summary'), content: <p>{s('tab_summary_text')}</p> },
                    { id: 'history', label: s('tab_history'), count: 3, content: <p>{s('tab_history_text')}</p> },
                    { id: 'documents', label: s('tab_documents'), content: <p>{s('tab_documents_text')}</p> },
                    { id: 'locked', label: s('tab_locked'), disabled: true, content: null },
                  ]}
                />
              </Card>
            ))}

            {section('overlays', (
              <div className="fila-acciones">
                <Button onClick={() => setPanel(true)}>{s('open_panel')}</Button>
                <Button onClick={() => setModal('normal')}>{s('open_modal')}</Button>
                <Button variant="danger" onClick={() => setModal('danger')}>{s('open_confirm')}</Button>
              </div>
            ))}

            {section('timeline', (
              <Card>
                <Timeline
                  label={s('sec_timeline')}
                  items={[
                    { id: '1', title: s('tl_requested'), when: s('tl_requested_when'), tone: 'gr' },
                    { id: '2', title: s('tl_approved'), when: s('tl_approved_when'), text: s('tl_approved_text'), tone: 'ok' },
                    { id: '3', title: s('tl_waiting'), when: s('tl_waiting_when'), tone: 'wa' },
                    { id: '4', title: s('tl_rejected'), when: s('tl_rejected_when'), tone: 'er' },
                  ]}
                />
              </Card>
            ))}

            {section('status', (
              <div className="cat-estados">
                <Estado label={s('st_status')}>
                  <StatusBadge tone="ok">{s('status_active')}</StatusBadge>
                  <StatusBadge tone="wa">{s('status_pending')}</StatusBadge>
                  <StatusBadge tone="er">{s('status_rejected')}</StatusBadge>
                  <StatusBadge tone="in">{s('status_in_progress')}</StatusBadge>
                  <StatusBadge tone="gr">{s('status_archived')}</StatusBadge>
                </Estado>
                <Estado label={s('st_tags')}>
                  <Tag tone="pe">{s('tag_package')}</Tag>
                  <Tag tone="wa">{s('tag_to_validate')}</Tag>
                  <Tag tone="oc">{s('tag_new')}</Tag>
                  <Tag tone="ok">{s('tag_verified')}</Tag>
                  <Tag tone="er">{s('tag_overdue')}</Tag>
                  <Tag>{s('tag_own')}</Tag>
                </Estado>
              </div>
            ))}

            {section('notices', (
              <>
                <Notice tone="ok" title={s('notice_ok')}>{s('notice_ok_text')}</Notice>
                <Notice tone="warn" title={s('notice_warn')}>{s('notice_warn_text')}</Notice>
                <Notice tone="error" title={s('notice_error')} action={<Button size="sm" onClick={() => did(s('retry'))}>{s('retry')}</Button>}>{s('notice_error_text')}</Notice>
                {noticeOpen
                  ? <Notice tone="info" title={s('notice_info')} onDismiss={() => setNoticeOpen(false)}>{s('notice_info_text')}</Notice>
                  : <Button size="sm" onClick={() => setNoticeOpen(true)}>{s('notice_restore')}</Button>}
              </>
            ))}

            {section('tooltips', (
              <div className="fila-acciones">
                <Tooltip text={s('tip_export')}>
                  <Button icon="download" onClick={() => did(s('btn_export'))}>{s('btn_export')}</Button>
                </Tooltip>
                <Tooltip text={s('tip_info')} side="bottom">
                  <button type="button" className="ibtn" aria-label={s('tip_info_label')} onClick={() => did(s('tip_info_label'))}>
                    <Icon name="info" />
                  </button>
                </Tooltip>
              </div>
            ))}

            {section('menus', (
              <div className="fila-acciones">
                <MenuButton
                  label={s('menu_actions')}
                  align="left"
                  items={[
                    { id: 'edit', label: s('btn_edit'), icon: 'checkSquare', onSelect: () => did(s('btn_edit')) },
                    { id: 'dup', label: s('menu_duplicate'), onSelect: () => did(s('menu_duplicate')) },
                    { id: 'locked', label: s('menu_locked'), disabled: true, onSelect: () => undefined },
                    { id: 'del', label: s('btn_delete'), icon: 'trash', danger: true, onSelect: () => setModal('danger') },
                  ]}
                />
                <MenuButton
                  label={s('menu_more')}
                  icon="more"
                  iconOnly
                  align="left"
                  items={[{ id: 'export', label: s('btn_export'), icon: 'download', onSelect: () => did(s('btn_export')) }]}
                />
              </div>
            ))}

            {section('people', (
              <div className="cat-estados">
                <Estado label={s('st_normal')}>
                  <div className="cat-campo">
                    <PersonPicker
                      label={s('person_label')}
                      value={person}
                      onChange={(p) => { setPerson(p); if (p) did(p.name) }}
                      search={search}
                      disabled={!canSearchPeople}
                      hint={canSearchPeople ? s('person_hint') : s('person_no_permission')}
                    />
                  </div>
                </Estado>
                <Estado label={s('st_error')}>
                  <div className="cat-campo">
                    <PersonPicker label={s('person_label_required')} value={null} onChange={setPerson} search={search} disabled={!canSearchPeople} required error={s('person_error')} />
                  </div>
                </Estado>
                <Estado label={s('st_disabled')}>
                  <div className="cat-campo">
                    <PersonPicker label={s('person_label')} value={null} onChange={setPerson} search={search} disabled />
                  </div>
                </Estado>
              </div>
            ))}

            {section('files', (
              <div className="cat-estados">
                <Estado label={s('st_normal')}>
                  <div className="cat-campo">
                    <FileUploader label={s('files_label')} files={files} onChange={setFiles} accept={['.pdf', 'image/*']} maxBytes={5 * 1024 * 1024} maxFiles={3} hint={s('files_hint')} />
                  </div>
                </Estado>
                <Estado label={s('st_loading')}>
                  <div className="cat-campo">
                    <FileUploader label={s('files_label')} files={[]} onChange={setFiles} uploading />
                  </div>
                </Estado>
                <Estado label={s('st_error')}>
                  <div className="cat-campo">
                    <FileUploader label={s('files_label')} files={[]} onChange={setFiles} error={s('files_error')} />
                  </div>
                </Estado>
                <Estado label={s('st_disabled')}>
                  <div className="cat-campo">
                    <FileUploader label={s('files_label')} files={[]} onChange={setFiles} disabled />
                  </div>
                </Estado>
              </div>
            ))}

            {section('skeletons', (
              <div className="cat-grid2">
                <Card title={s('sk_text')}><SkeletonText lines={4} /></Card>
                <Card title={s('sk_rows')} flush><div className="cat-pad"><SkeletonRows rows={3} /></div></Card>
                <Card title={s('sk_block')}><SkeletonBlock height={96} /></Card>
              </div>
            ))}

            {section('states', (
              <div className="cat-grid2">
                <Card flush><EmptyState title={s('empty_title')} text={s('empty_text')} action={<Button variant="primary" icon="plus" onClick={() => did(s('btn_add'))}>{s('btn_add')}</Button>} /></Card>
                <Card flush><ErrorState text={s('error_text')} retryLabel={s('retry')} onRetry={() => did(s('retry'))} /></Card>
              </div>
            ))}

            {section('tables', canSearchPeople ? (
              <DataTable<CatalogUser>
                resource="ui_catalog_users"
                endpoint="/users"
                defaultSort="display_name"
                searchLabel={t('users.search')}
                rowKey={(u) => u.id}
                columns={[
                  { key: 'name', label: t('users.col_user'), sort: 'display_name', hideable: false, render: (u) => <b>{u.display_name}</b> },
                  { key: 'status', label: t('users.col_status'), sort: 'status', render: (u) => t(`users.status_${u.status}`) },
                  { key: 'last_login_at', label: t('users.col_last'), sort: 'last_login_at', render: (u) => u.last_login_at ?? '—' },
                ]}
                filters={[{ key: 'status', label: t('users.filter_status'), options: ['invited', 'active', 'locked'].map((v) => ({ value: v, label: t(`users.status_${v}`) })) }]}
                bulkActions={[{ key: 'demo', label: s('btn_save'), applies: () => true, run: async (rows) => { did(s('btn_save')); return rows.length } }]}
                empty={<EmptyState title={s('empty_title')} text={s('empty_text')} />}
              />
            ) : <Notice tone="info">{s('tables_no_access')}</Notice>)}

            {section('disclosure', (
              <Card>
                <label className="form-label" htmlFor="cat-pl-nombre">{s('panel_field')}</label>
                <input id="cat-pl-nombre" className="form-control mb-3" />
                <Disclosure label={t('ui.more_options')} summary={s('disclosure_summary')}>
                  <label className="form-label" htmlFor="cat-pl-nota">{s('disclosure_field')}</label>
                  <textarea id="cat-pl-nota" className="form-control" rows={2} />
                </Disclosure>
              </Card>
            ))}

            {section('charts', (
              <div className="cat-graficos">
                <Card><BarChart title={s('chart_bar')} categories={[s('demo_site1'), s('demo_site2'), s('demo_site3')]} series={[{ name: s('chart_filled'), values: [42, 18, 11] }, { name: s('chart_vacant'), values: [5, 3, 4] }]} labels={chartLabels} /></Card>
                <Card><LineChart title={s('chart_line')} categories={months} series={[{ name: s('chart_hires'), values: [4, 6, 3, 8, 5, 7] }, { name: s('chart_exits'), values: [2, 1, 3, 2, 4, 1] }]} labels={chartLabels} /></Card>
                <Card><DonutChart title={s('chart_donut')} centerLabel={s('chart_people')} slices={[{ name: s('demo_kind1'), value: 64 }, { name: s('demo_kind2'), value: 9 }, { name: s('demo_kind3'), value: 5 }]} labels={chartLabels} /></Card>
                <Card><Heatmap title={s('chart_heat')} rows={[s('demo_unit_sales'), s('demo_unit_ops'), s('demo_unit_fin')]} columns={weekdayNames} values={[[2, 0, 1, 3, 5], [1, 1, 0, 2, 4], [0, 0, 1, 0, 2]]} labels={chartLabels} /></Card>
              </div>
            ))}

            {section('kanban', (
              <Kanban
                columns={[{ id: 'todo', title: s('kanban_todo') }, { id: 'doing', title: s('kanban_doing') }, { id: 'done', title: s('kanban_done') }]}
                cards={cards}
                canMove={(card, to) => !(card.column === 'done' && to !== 'done')}
                onMove={async (card, to) => { setCards((cs) => cs.map((c) => (c.id === card.id ? { ...c, column: to } : c))); const text = t('uicat.did', { what: card.title }); toast(text); return text }}
                labels={{ moveTo: s('kanban_move_to'), moving: s('kanban_moving') }}
                emptyText={s('kanban_empty')}
              />
            ))}

            {section('calendar', (
              <Card>
                <Calendar
                  view="week"
                  anchor={today}
                  today={today}
                  locale={locale}
                  items={calItems}
                  onMove={async (item, day) => { setCalItems((xs) => xs.map((x) => (x.key === item.key ? { ...x, starts_on: day, ends_on: day } : x))); const text = t('uicat.did', { what: item.title }); toast(text); return text }}
                  labels={{ move: s('cal_move'), moveTitle: s('cal_move'), moveField: s('cal_new_day'), save: s('btn_save'), cancel: s('btn_cancel') }}
                />
              </Card>
            ))}

            {section('tree', (
              <Card><Tree nodes={tree} label={s('sec_tree')} defaultOpen={['g']} selected={treeSel} onSelect={(n) => { setTreeSel(n.id); did(n.text) }} /></Card>
            ))}
          </div>
        </div>
      </main>

      {panel && (
        <SidePanel
          title={s('panel_title')}
          closeLabel={t('ui.close')}
          onClose={() => setPanel(false)}
          footer={<><Button onClick={() => setPanel(false)}>{s('btn_cancel')}</Button><Button variant="primary" onClick={() => { setPanel(false); did(s('btn_save')) }}>{s('btn_save')}</Button></>}
        >
          <label className="form-label" htmlFor="cat-panel-campo">{s('panel_field')}</label>
          <input id="cat-panel-campo" className="form-control" />
          <p className="small text-secondary">{s('panel_text')}</p>
        </SidePanel>
      )}
      {modal && (
        <Modal
          title={s(modal === 'danger' ? 'confirm_title' : 'modal_title')}
          tone={modal === 'danger' ? 'danger' : 'normal'}
          size="s"
          onClose={() => setModal(null)}
          footer={modal === 'danger'
            ? <><Button onClick={() => setModal(null)}>{s('btn_cancel')}</Button><Button variant="danger" onClick={() => { setModal(null); did(s('btn_delete')) }}>{s('btn_delete')}</Button></>
            : <Button variant="primary" onClick={() => { setModal(null); did(s('btn_accept')) }}>{s('btn_accept')}</Button>}
        >
          <p>{s(modal === 'danger' ? 'confirm_text' : 'modal_text')}</p>
        </Modal>
      )}
      {toastNode}
    </>
  )
}
