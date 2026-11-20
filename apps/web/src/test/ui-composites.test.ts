import { describe, expect, it } from 'vitest'
import { canDownload, exportTone, hasPending } from '@/modules/exports/exports'
import { boardAction } from '@/modules/workflow/TasksScreen'
import { viewRange, weekDays } from '@/ui/components/Calendar'
import { donutArcs, heatLevel, linePath, niceMax } from '@/ui/chartMath'
import { visibleNodes, type TreeNode } from '@/ui/components/Tree'

describe('UI-12 · cálculos de gráficos', () => {
  it('el máximo del eje es redondo y nunca cero', () => {
    expect(niceMax([])).toBe(1)
    expect(niceMax([0, 0])).toBe(1)
    expect(niceMax([42, 18])).toBe(50)
    expect(niceMax([7])).toBe(10)
    expect(niceMax([180])).toBe(200)
  })

  it('la línea recorre el ancho y baja con el valor', () => {
    const { points, d } = linePath([0, 5, 10], 10, { x: 0, y: 0, w: 100, h: 50 })
    expect(points.map((p) => p.x)).toEqual([0, 50, 100])
    expect(points.map((p) => p.y)).toEqual([50, 25, 0])
    expect(d.startsWith('M0.0,50.0')).toBe(true)
  })

  it('la dona tiene una porción por valor positivo y cierra con una sola de 100 %', () => {
    expect(donutArcs([0, 0], 90, 60, { cx: 110, cy: 110 })).toEqual([])
    const arcs = donutArcs([1, 1, 0], 90, 60, { cx: 110, cy: 110 })
    expect(arcs).toHaveLength(3)
    expect(arcs[2]).toBe('')
    expect(donutArcs([5], 90, 60, { cx: 110, cy: 110 })[0]?.split('M')).toHaveLength(3)
  })

  it('el mapa de calor usa cinco niveles y el cero tiene el suyo', () => {
    expect(heatLevel(0, 10)).toBe(0)
    expect(heatLevel(1, 10)).toBe(1)
    expect(heatLevel(10, 10)).toBe(4)
    expect(heatLevel(6, 10)).toBe(3)
  })
})

describe('UI-12 · calendario', () => {
  it('la semana va de lunes a domingo y la agenda cubre cuatro semanas', () => {
    expect(weekDays('2026-11-19')).toEqual(['2026-11-16', '2026-11-17', '2026-11-18', '2026-11-19', '2026-11-20', '2026-11-21', '2026-11-22'])
    const agenda = viewRange('agenda', '2026-11-19')
    expect(agenda.days).toHaveLength(28)
    expect(agenda.from).toBe('2026-11-19')
    expect(viewRange('month', '2026-11-19').from).toBe('2026-10-26')
  })
})

describe('UI-12 · tablero de tareas', () => {
  it('soltar en una columna dispara solo la acción que la máquina de estados admite', () => {
    expect(boardAction('pending', 'in_progress', ['start', 'complete', 'cancel'])).toBe('start')
    expect(boardAction('pending', 'done', ['start', 'complete'])).toBe('complete')
    expect(boardAction('in_progress', 'done', ['complete'])).toBe('complete')
    expect(boardAction('in_progress', 'pending', ['complete'])).toBeNull()
    expect(boardAction('done', 'in_progress', [])).toBeNull()
    expect(boardAction('pending', 'in_progress', ['complete'])).toBeNull()
  })
})

describe('UI-12 · árbol', () => {
  const tree: TreeNode[] = [{ id: 'a', text: 'A', label: 'A', children: [{ id: 'b', text: 'B', label: 'B', children: [{ id: 'c', text: 'C', label: 'C' }] }] }, { id: 'd', text: 'D', label: 'D' }]
  it('solo se ven los hijos de los nodos abiertos, con su nivel y su padre', () => {
    expect(visibleNodes(tree, new Set()).map((v) => v.node.id)).toEqual(['a', 'd'])
    const open = visibleNodes(tree, new Set(['a', 'b']))
    expect(open.map((v) => [v.node.id, v.level, v.parent])).toEqual([['a', 1, null], ['b', 2, 'a'], ['c', 3, 'b'], ['d', 1, null]])
  })
})

describe('UI-11 · exportaciones', () => {
  const now = new Date('2026-11-19T12:00:00Z')
  it('se descarga solo lo terminado y vigente', () => {
    expect(canDownload('completed', '2026-11-20T00:00:00Z', now)).toBe(true)
    expect(canDownload('completed', '2026-11-18T00:00:00Z', now)).toBe(false)
    expect(canDownload('queued', null, now)).toBe(false)
  })
  it('el tono sigue el estado y la pantalla vuelve a preguntar mientras haya alguna en cola', () => {
    expect(exportTone('failed', null, now)).toBe('er')
    expect(exportTone('running', null, now)).toBe('wa')
    expect(exportTone('completed', '2026-11-18T00:00:00Z', now)).toBe('gr')
    expect(exportTone('completed', null, now)).toBe('ok')
    expect(hasPending([{ status: 'completed' }, { status: 'queued' }])).toBe(true)
    expect(hasPending([{ status: 'failed' }])).toBe(false)
  })
})
