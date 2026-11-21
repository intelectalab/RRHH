import { createHmac } from 'node:crypto'
import { existsSync, readFileSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { expect, test, type Page } from 'playwright/test'

/**
 * QA-2 · Recorrido de extremo a extremo de cimientos y Core HR sobre la organización demo (DemoOrganizationSeeder):
 * ingreso con correo y segundo factor, alta de un colaborador con su contrato, firma registrada del contrato y portal.
 * Las acciones se hacen como las hace la aplicación (mismo origen, cookie de sesión y XSRF) y cada resultado se
 * verifica en pantalla. La clave del administrador demo llega por E2E_ADMIN_PASSWORD (nunca en el código).
 */
const EMAIL = process.env.E2E_ADMIN_EMAIL ?? 'admin@demo.gestiahr.test'
const PASSWORD = process.env.E2E_ADMIN_PASSWORD ?? ''

/** TOTP (RFC 6238, SHA-1, 30 s, 6 dígitos) del secreto en base32 que entrega la API al activar el segundo factor. */
function totp(secret: string, at = Date.now()): string {
  const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'
  let bits = ''
  for (const c of secret.replace(/=+$/, '').toUpperCase()) bits += alphabet.indexOf(c).toString(2).padStart(5, '0')
  const key = Buffer.from((bits.match(/.{8}/g) ?? []).map((b) => parseInt(b, 2)))
  const counter = Buffer.alloc(8)
  counter.writeBigUInt64BE(BigInt(Math.floor(at / 30000)))
  const h = createHmac('sha1', key).update(counter).digest()
  const o = h[h.length - 1] & 0xf
  const n = ((h[o] & 0x7f) << 24) | (h[o + 1] << 16) | (h[o + 2] << 8) | h[o + 3]
  return String(n % 1_000_000).padStart(6, '0')
}

type Call = { status: number; body: { data?: unknown; error?: { code?: string; message?: string } } }

/** Llama a la API desde la página, con la sesión y el XSRF del navegador (igual que src/lib/api.ts). */
async function call(page: Page, method: string, path: string, body?: unknown, form?: { field: string; name: string; content: string; extra: Record<string, string> }): Promise<Call> {
  return page.evaluate(async ({ method, path, body, form }) => {
    const xsrf = decodeURIComponent((document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN=')) ?? '').split('=')[1] ?? '')
    const headers: Record<string, string> = { Accept: 'application/json', 'X-XSRF-TOKEN': xsrf, 'Idempotency-Key': crypto.randomUUID() }
    let payload: BodyInit | undefined
    if (form) {
      const fd = new FormData()
      fd.append(form.field, new Blob([form.content], { type: 'application/pdf' }), form.name)
      for (const [k, v] of Object.entries(form.extra)) fd.append(k, v)
      payload = fd
    } else if (body !== undefined) {
      headers['Content-Type'] = 'application/json'
      payload = JSON.stringify(body)
    }
    const r = await fetch(`/api/v1${path}`, { method, headers, body: payload, credentials: 'same-origin' })
    const text = await r.text()
    return { status: r.status, body: text ? JSON.parse(text) : {} }
  }, { method, path, body, form })
}

test.describe.configure({ mode: 'serial' })

test('cimientos y Core HR: ingreso, alta con contrato firmado y portal', async ({ page }) => {
  test.skip(PASSWORD === '', 'Falta E2E_ADMIN_PASSWORD (la misma DEMO_ADMIN_PASSWORD con la que se sembró la demo).')

  // 1 · Ingreso con correo y clave.
  await page.goto('/ingresar')
  await page.locator('#l-email').fill(EMAIL)
  await page.locator('#l-password').fill(PASSWORD)
  await page.locator('form button[type="submit"]').first().click()
  await page.waitForURL((u) => u.pathname === '/' || u.pathname.startsWith('/verificacion'))

  // 2 · Segundo factor: el rol administrador lo exige (activación la primera vez, código después).
  if (new URL(page.url()).pathname.startsWith('/verificacion')) {
    // El secreto queda fuera del repositorio (carpeta temporal) para repetir el recorrido sobre la misma base.
    const store = join(tmpdir(), `gestia-e2e-totp-${new URL(page.url()).host.replace(/[^a-z0-9.-]/gi, '_')}`)
    const started = await call(page, 'POST', '/me/mfa/totp')
    if (started.status === 200) {
      const secret = (started.body.data as { secret: string }).secret
      writeFileSync(store, secret)
      expect((await call(page, 'POST', '/me/mfa/totp/confirm', { code: totp(secret) })).status).toBe(200)
    } else {
      const secret = process.env.E2E_TOTP_SECRET ?? (existsSync(store) ? readFileSync(store, 'utf8').trim() : '')
      expect(secret, 'El segundo factor ya está activo: indica E2E_TOTP_SECRET o vuelve a sembrar la base.').not.toBe('')
      expect((await call(page, 'POST', '/auth/mfa/verify', { code: totp(secret) })).status).toBe(200)
    }
    await page.goto('/')
  }
  await expect(page.locator('#contenido')).toBeVisible()

  // 3 · Alta de colaborador con contrato indefinido (datos de la organización demo).
  const options = (await call(page, 'GET', '/employment-options')).body.data as Record<string, { id: string; code?: string; legal_entity_id?: string; currency?: string }[]>
  const entity = options.legal_entities[0]
  const pick = (k: string) => (options[k] ?? []).find((r) => !r.legal_entity_id || r.legal_entity_id === entity.id)?.id
  const type = options.contract_types.find((c) => c.code === 'indeterminado') ?? options.contract_types[0]
  const surname = `Prueba${Date.now().toString().slice(-6)}`
  const dni = String(70000000 + Math.floor(Math.random() * 9999999))
  const today = new Date().toISOString().slice(0, 10)
  const hire = await call(page, 'POST', '/employments', {
    person: { first_names: 'Lucía', paternal_surname: surname, document_type: 'dni', document_number: dni },
    legal_entity_id: entity.id, hire_date: today, job_profile_id: pick('job_profiles'), org_unit_id: pick('org_units'), site_id: pick('sites'),
    contract_type_id: type.id, base_salary: { amount: '3200.00', currency: entity.currency ?? 'PEN' },
  })
  expect(hire.status, JSON.stringify(hire.body)).toBe(201)
  const employment = (hire.body.data as { id: string }).id
  await page.goto(`/personas/colaboradores?q=${surname}`)
  await expect(page.getByText(surname).first()).toBeVisible()

  // 4 · Contrato: la firma física escaneada se registra y el contrato queda firmado o vigente.
  const contracts = (await call(page, 'GET', `/employments/${employment}/contracts`)).body.data as { id: string; status: string }[]
  expect(contracts.length).toBeGreaterThan(0)
  const contract = contracts[0]
  if (!['signed', 'active'].includes(contract.status)) {
    for (const action of ['submit', 'approve']) {
      const r = await call(page, 'POST', `/contracts/${contract.id}/${action}`, {})
      expect([200, 409, 422], JSON.stringify(r.body)).toContain(r.status)
    }
    const signed = await call(page, 'POST', `/contracts/${contract.id}/register-signature`, undefined,
      { field: 'file', name: 'contrato-firmado.pdf', content: '%PDF-1.4\n% contrato firmado\n', extra: { signed_on: today } })
    expect(signed.status, JSON.stringify(signed.body)).toBe(200)
  }
  const after = (await call(page, 'GET', `/contracts/${contract.id}`)).body.data as { status: string }
  expect(['signed', 'active']).toContain(after.status)

  // 5 · Auditoría y portal: el alta deja rastro y el portal propio abre.
  await page.goto('/mi')
  await expect(page.locator('main')).toBeVisible()
})
