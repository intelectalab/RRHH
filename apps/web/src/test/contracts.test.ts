import { describe, it, expect } from 'vitest'
import type { paths, components } from '@contracts'
import { api, ApiClient, type ApiError } from '@/lib/api'
import * as fs from 'fs'
import * as path from 'path'

describe('PLT-5 · Contratos OpenAPI y tipos compartidos en Web', () => {
  it('importa tipos de respuesta directamente desde packages/contracts', () => {
    // Validamos que los tipos del contrato estén accesibles
    type ErrorSchema = components['schemas']['Error']
    const testError: ErrorSchema = {
      error: {
        code: 'validation_failed',
        message: 'Error de validación',
        request_id: '123-abc',
      },
    }

    expect(testError.error.code).toBe('validation_failed')
    expect(api).toBeInstanceOf(ApiClient)
  })

  it('no declara a mano tipos de datos de backend en el código fuente de web', () => {
    // Escaneamos apps/web/src para confirmar que no se declaran interfaces duplicadas de entidades OpenAPI
    const srcDir = path.resolve(__dirname, '..')

    function getAllTsFiles(dir: string): string[] {
      let results: string[] = []
      const list = fs.readdirSync(dir)
      for (const file of list) {
        const fullPath = path.resolve(dir, file)
        const stat = fs.statSync(fullPath)
        if (stat && stat.isDirectory()) {
          if (file !== 'test' && file !== 'node_modules') {
            results = results.concat(getAllTsFiles(fullPath))
          }
        } else if (file.endsWith('.ts') || file.endsWith('.tsx')) {
          results.push(fullPath)
        }
      }
      return results
    }

    const tsFiles = getAllTsFiles(srcDir)
    const forbiddenPatterns = [
      /interface\s+AbsenceType\b/,
      /interface\s+Employee\b/,
      /interface\s+Employment\b/,
      /interface\s+Tenant\b/,
      /interface\s+Workflow\b/,
    ]

    for (const file of tsFiles) {
      const content = fs.readFileSync(file, 'utf-8')
      for (const pattern of forbiddenPatterns) {
        const match = pattern.exec(content)
        expect(
          match,
          `El archivo ${path.basename(file)} declara a mano un tipo de backend (${match?.[0]}). Debe importarlo de @contracts`,
        ).toBeNull()
      }
    }
  })
})
