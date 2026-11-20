import { describe, expect, it } from 'vitest'
import { readdirSync, readFileSync } from 'fs'
import { resolve } from 'path'
import { renderToStaticMarkup } from 'react-dom/server'
import es from '../../messages/es.json'
import { nextIndex } from '@/ui/a11y'
import { formatBytes, validateFiles } from '@/ui/files'
import { Button } from '@/ui/components/Button'
import { Card } from '@/ui/components/Card'
import { FileUploader } from '@/ui/components/FileUploader'
import { MenuButton } from '@/ui/components/MenuButton'
import { Notice } from '@/ui/components/Notice'
import { PersonPicker, initials } from '@/ui/components/PersonPicker'
import { SkeletonRows, SkeletonText } from '@/ui/components/Skeleton'
import { StatusBadge, Tag } from '@/ui/components/Status'
import { Tabs } from '@/ui/components/Tabs'
import { Timeline } from '@/ui/components/Timeline'
import { Tooltip } from '@/ui/components/Tooltip'

const noop = () => undefined

describe('UI-3 · navegación con teclado', () => {
  it('las flechas avanzan, retroceden y dan la vuelta', () => {
    expect(nextIndex(3, 0, 'ArrowRight')).toBe(1)
    expect(nextIndex(3, 2, 'ArrowDown')).toBe(0)
    expect(nextIndex(3, 0, 'ArrowLeft')).toBe(2)
    expect(nextIndex(3, 1, 'ArrowUp')).toBe(0)
  })

  it('Inicio y Fin van al primero y al último habilitado; los deshabilitados se saltan', () => {
    const disabled = (i: number) => i === 0 || i === 3
    expect(nextIndex(4, 2, 'Home', disabled)).toBe(1)
    expect(nextIndex(4, 1, 'End', disabled)).toBe(2)
    expect(nextIndex(4, 2, 'ArrowRight', disabled)).toBe(1)
    expect(nextIndex(4, 1, 'ArrowLeft', disabled)).toBe(2)
  })

  it('una tecla que no navega o una lista sin habilitados no mueve nada', () => {
    expect(nextIndex(3, 0, 'a')).toBeNull()
    expect(nextIndex(0, 0, 'ArrowDown')).toBeNull()
    expect(nextIndex(2, 0, 'ArrowDown', () => true)).toBeNull()
  })
})

describe('UI-3 · validación del cargador de archivos', () => {
  const pdf = { name: 'contrato.PDF', size: 1000, type: 'application/pdf' }
  const png = { name: 'foto.png', size: 2000, type: 'image/png' }
  const exe = { name: 'virus.exe', size: 10, type: 'application/octet-stream' }
  const big = { name: 'grande.pdf', size: 6_000_000, type: 'application/pdf' }

  it('acepta por extensión y por familia de tipo, y rechaza con motivo', () => {
    const r = validateFiles([pdf, png, exe, big], { accept: ['.pdf', 'image/*'], maxBytes: 5_000_000 })
    expect(r.accepted.map((f) => f.name)).toEqual(['contrato.PDF', 'foto.png'])
    expect(r.rejected).toEqual([{ name: 'virus.exe', reason: 'type' }, { name: 'grande.pdf', reason: 'size' }])
  })

  it('respeta el máximo de archivos contando los ya elegidos', () => {
    const r = validateFiles([pdf, png], { maxFiles: 2 }, 1)
    expect(r.accepted).toHaveLength(1)
    expect(r.rejected).toEqual([{ name: 'foto.png', reason: 'count' }])
  })

  it('formatea tamaños con el formato del idioma', () => {
    expect(formatBytes(512, 'es')).toBe('512 B')
    expect(formatBytes(1536, 'es')).toBe('1,5 KB')
    expect(formatBytes(5 * 1024 * 1024, 'en')).toBe('5 MB')
  })
})

describe('UI-3 · marcado accesible de los componentes', () => {
  it('Botón: cargando queda deshabilitado y anuncia que está ocupado', () => {
    const html = renderToStaticMarkup(<Button loading onClick={noop}>{'x'}</Button>)
    expect(html).toContain('disabled')
    expect(html).toContain('aria-busy="true"')
    expect(html).toContain('type="button"')
  })

  it('Pestañas: tablist con una sola pestaña en el orden de tabulación', () => {
    const html = renderToStaticMarkup(
      <Tabs label="L" value="b" onChange={noop} items={[{ id: 'a', label: 'A', content: 'contenido-A' }, { id: 'b', label: 'B', content: 'contenido-B' }, { id: 'c', label: 'C', content: 'contenido-C', disabled: true }]} />,
    )
    expect(html).toContain('role="tablist"')
    expect(html.match(/role="tab"/g)).toHaveLength(3)
    expect(html.match(/tabindex="0"/g)).toHaveLength(2) // la pestaña activa y su panel
    expect(html).toContain('aria-selected="true"')
    expect(html).toContain('role="tabpanel"')
    expect(html).toContain('contenido-B')
    expect(html).not.toContain('contenido-A')
  })

  it('Menú: botón con aria-haspopup y cerrado al inicio; sin acciones no se muestra', () => {
    const html = renderToStaticMarkup(<MenuButton label="Acciones" items={[{ id: 'e', label: 'Editar', onSelect: noop }]} />)
    expect(html).toContain('aria-haspopup="menu"')
    expect(html).toContain('aria-expanded="false"')
    expect(html).not.toContain('role="menu"')
    expect(renderToStaticMarkup(<MenuButton label="Acciones" items={[]} />)).toBe('')
  })

  it('Selector de persona: combobox con error enlazado y estado obligatorio', () => {
    const html = renderToStaticMarkup(<PersonPicker label="Responsable" value={null} onChange={noop} search={async () => []} required error="Elige" />)
    expect(html).toContain('role="combobox"')
    expect(html).toContain('aria-invalid="true"')
    expect(html).toContain('aria-required="true"')
    expect(html).toMatch(/aria-describedby="[^"]+-e"/)
    expect(initials('Ana María Quispe')).toBe('AM')
  })

  it('Selector de persona: con valor elegido muestra a la persona y el botón para quitarla', () => {
    const html = renderToStaticMarkup(<PersonPicker label="Responsable" value={{ id: '1', name: 'Rosa Huamán' }} onChange={noop} search={async () => []} />)
    expect(html).toContain('Rosa Huamán')
    expect(html).toContain(es.ui.person_clear.replace('{name}', 'Rosa Huamán'))
  })

  it('Cargador: muestra las reglas y el estado de carga', () => {
    const html = renderToStaticMarkup(<FileUploader label="Docs" files={[]} onChange={noop} accept={['.pdf']} maxFiles={2} uploading />)
    expect(html).toContain(es.ui.files_uploading)
    expect(html).toContain('aria-busy="true"')
    expect(html).toContain('accept=".pdf"')
  })

  it('Aviso: el error se anuncia como alerta y la información como estado', () => {
    expect(renderToStaticMarkup(<Notice tone="error" title="E" />)).toContain('role="alert"')
    expect(renderToStaticMarkup(<Notice tone="info" title="I" />)).toContain('role="status"')
    expect(renderToStaticMarkup(<Notice tone="info" title="I" onDismiss={noop} />)).toContain(`aria-label="${es.ui.dismiss}"`)
  })

  it('Ayuda emergente: queda enlazada al control con aria-describedby', () => {
    const html = renderToStaticMarkup(<Tooltip text="Ayuda"><button type="button">{'b'}</button></Tooltip>)
    expect(html).toMatch(/aria-describedby="([^"]+)"[\s\S]*id="\1" role="tooltip"/)
  })

  it('Esqueletos, línea de tiempo, estados y tarjeta', () => {
    expect(renderToStaticMarkup(<SkeletonText lines={2} />)).toContain('aria-busy="true"')
    expect(renderToStaticMarkup(<SkeletonRows rows={2} />).match(/<li/g)).toHaveLength(2)
    const tl = renderToStaticMarkup(<Timeline label="H" items={[{ id: '1', title: 'Aprobada', tone: 'ok' }, { id: '2', title: 'Enviada', tone: 'gr' }]} />)
    expect(tl).toContain('<ol class="traza" aria-label="H">')
    expect(tl).toContain('<li class="ok">')
    expect(renderToStaticMarkup(<StatusBadge tone="wa">{'P'}</StatusBadge>)).toBe('<span class="st wa">P</span>')
    expect(renderToStaticMarkup(<Tag>{'T'}</Tag>)).toBe('<span class="tag gr">T</span>')
    expect(renderToStaticMarkup(<Card title="T" id="x">{'c'}</Card>)).toContain('aria-labelledby="x-t"')
  })
})

describe('UI-3 · el catálogo muestra todos los componentes de la historia', () => {
  it('cada componente de la biblioteca tiene su sección en el catálogo', () => {
    const catalog = readFileSync(resolve(__dirname, '../modules/ui-catalog/ComponentCatalog.tsx'), 'utf8')
    const components = readdirSync(resolve(__dirname, '../ui/components'))
      .map((f) => f.replace('.tsx', ''))
      .filter((c) => !['BrandMark', 'BrandName', 'Icon', 'PageHeader', 'QrCode', 'Toast'].includes(c))
    const imported: Record<string, string> = { Status: 'StatusBadge', States: 'EmptyState', Skeleton: 'SkeletonText', Charts: 'BarChart' }
    for (const c of components) expect(catalog, c).toContain(`<${imported[c] ?? c}`)
  })
})
