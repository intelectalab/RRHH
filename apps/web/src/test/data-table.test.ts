import { readFileSync } from 'fs'
import { resolve } from 'path'
import { describe, expect, it } from 'vitest'
import { nextSort, tableQuery } from '@/ui/components/DataTable'

describe('UI-4 · tabla profesional', () => {
  it('pide la página al servidor con orden, búsqueda y filtros declarados', () => {
    const params = tableQuery({ q: '  ana ', filters: { status: 'locked', role_id: '' }, sort: '-last_login_at' }, 3, 50)
    expect(params.get('page')).toBe('3')
    expect(params.get('per_page')).toBe('50')
    expect(params.get('sort')).toBe('-last_login_at')
    expect(params.get('q')).toBe('ana')
    expect(params.get('filter[status]')).toBe('locked')
    // Un filtro vacío no viaja.
    expect(params.has('filter[role_id]')).toBe(false)
  })

  it('el encabezado alterna ascendente, descendente y el orden por defecto', () => {
    expect(nextSort('display_name', 'status', 'display_name')).toBe('status')
    expect(nextSort('status', 'status', 'display_name')).toBe('-status')
    expect(nextSort('-status', 'status', 'display_name')).toBe('display_name')
  })

  it('los listados de usuarios y empresas usan la tabla común (sin tablas hechas a medida)', () => {
    for (const file of ['../modules/identity/UsersScreen.tsx', '../modules/organization/CompaniesScreen.tsx']) {
      const source = readFileSync(resolve(__dirname, file), 'utf8')
      expect(source, file).toContain('<DataTable<')
      expect(source, file).not.toContain('<table')
    }
  })

  it('cada texto de la tabla existe en los tres idiomas', () => {
    const source = readFileSync(resolve(__dirname, '../ui/components/DataTable.tsx'), 'utf8')
    const keys = [...source.matchAll(/t\('(table\.[a-z_]+)'/g)].map((m) => m[1])
    expect(keys.length).toBeGreaterThan(20)
    for (const lang of ['es', 'en', 'pt']) {
      const messages = JSON.parse(readFileSync(resolve(__dirname, `../../messages/${lang}.json`), 'utf8')) as { table: Record<string, string> }
      for (const key of keys) expect(messages.table[key.replace('table.', '')], `${lang}:${key}`).toBeTruthy()
    }
  })
})
