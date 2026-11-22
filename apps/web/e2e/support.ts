import { createHmac } from 'node:crypto'
import { existsSync, readFileSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join, resolve } from 'node:path'
import { expect, type Page } from 'playwright/test'

/**
 * QA-2 · Ayudas comunes de los recorridos de extremo a extremo. Ninguna clave vive en el código: llegan por variables
 * de entorno (E2E_ADMIN_PASSWORD, E2E_PLATFORM_PASSWORD) o se generan en la corrida.
 */
export const EMAIL = process.env.E2E_ADMIN_EMAIL ?? 'admin@demo.gestiahr.test'
export const PASSWORD = process.env.E2E_ADMIN_PASSWORD ?? ''
export const PLATFORM_EMAIL = process.env.E2E_PLATFORM_EMAIL ?? 'e2e-consola@gestiahr.test'
export const PLATFORM_PASSWORD = process.env.E2E_PLATFORM_PASSWORD ?? ''
/** Bitácora de la API con los correos (MAIL_MAILER=log): de ahí se leen los enlaces de invitación. */
export const MAIL_LOG = process.env.E2E_MAIL_LOG ?? resolve(__dirname, '../../api/storage/logs/laravel.log')

/** Dirección de otra organización con el mismo esquema que la base (demo.localhost:3000 → <slug>.localhost:3000). */
export function tenantUrl(slug: string, path = '/'): string {
  const base = new URL(process.env.E2E_BASE_URL ?? 'http://demo.localhost:3000')
  base.hostname = base.hostname.replace(/^[^.]+/, slug)
  return new URL(path, base).toString()
}

/** Consola de plataforma: el dominio base, sin subdominio de organización. */
export function consoleUrl(path = '/'): string {
  const base = new URL(process.env.E2E_CONSOLE_URL ?? tenantUrl('x').replace('//x.', '//'))
  return new URL(path, base).toString()
}

/** Clave fuerte generada en la corrida (nunca en el repositorio). */
export function generatedPassword(): string {
  return `Qa2-${Math.random().toString(36).slice(2, 10)}-${Date.now().toString(36)}!A9`
}

/** TOTP (RFC 6238, SHA-1, 30 s, 6 dígitos) del secreto en base32 que entrega la API al activar el segundo factor. */
export function totp(secret: string, at = Date.now()): string {
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

/** El secreto TOTP se guarda fuera del repositorio (carpeta temporal) para repetir el recorrido sobre la misma base. */
function secretStore(key: string): string {
  return join(tmpdir(), `gestia-e2e-totp-${key.replace(/[^a-z0-9.@-]/gi, '_')}`)
}

/**
 * Código TOTP que la API todavía no vio: la API rechaza repetir el código de una ventana ya usada (anti reuso),
 * así que si este secreto ya se usó en la ventana actual se espera a la siguiente.
 */
export async function freshTotp(secret: string): Promise<string> {
  // La última ventana usada se guarda en disco: los specs pueden correr en procesos distintos.
  const mark = join(tmpdir(), `gestia-e2e-totp-step-${createHmac('sha1', 'step').update(secret).digest('hex').slice(0, 16)}`)
  const last = existsSync(mark) ? Number(readFileSync(mark, 'utf8')) : -1
  if (Math.floor(Date.now() / 30000) <= last) await new Promise((r) => setTimeout(r, (last + 1) * 30000 - Date.now() + 500))
  const now = Date.now()
  writeFileSync(mark, String(Math.floor(now / 30000)))
  return totp(secret, now)
}

export type Call = { status: number; body: { data?: unknown; meta?: unknown; error?: { code?: string; message?: string } }; text: string }

/** Llama a la API desde la página, con la sesión y el XSRF del navegador (igual que src/lib/api.ts). */
export async function call(page: Page, method: string, path: string, body?: unknown, opts: { prefix?: string; xsrfCookie?: string; form?: { field: string; name: string; content: string; extra: Record<string, string> } } = {}): Promise<Call> {
  const { prefix = '/api/v1', xsrfCookie = 'XSRF-TOKEN', form } = opts
  return page.evaluate(async ({ method, path, body, form, prefix, xsrfCookie }) => {
    const xsrf = decodeURIComponent((document.cookie.split('; ').find((c) => c.startsWith(`${xsrfCookie}=`)) ?? '').split('=')[1] ?? '')
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
    const r = await fetch(`${prefix}${path}`, { method, headers, body: payload, credentials: 'same-origin' })
    const text = await r.text()
    let parsed = {}
    try { parsed = text ? JSON.parse(text) : {} } catch { parsed = {} }
    return { status: r.status, body: parsed, text }
  }, { method, path, body, form, prefix, xsrfCookie })
}

/** Completa el segundo factor de la aplicación si la página quedó en /verificacion (activación o código). */
export async function completeAppMfa(page: Page, key: string): Promise<void> {
  if (!new URL(page.url()).pathname.startsWith('/verificacion')) return
  const store = secretStore(`${new URL(page.url()).host}-${key}`)
  const started = await call(page, 'POST', '/me/mfa/totp')
  if (started.status === 200) {
    const secret = (started.body.data as { secret: string }).secret
    writeFileSync(store, secret)
    expect((await call(page, 'POST', '/me/mfa/totp/confirm', { code: await freshTotp(secret) })).status).toBe(200)
  } else {
    const secret = process.env.E2E_TOTP_SECRET ?? (existsSync(store) ? readFileSync(store, 'utf8').trim() : '')
    expect(secret, 'El segundo factor ya está activo: indica E2E_TOTP_SECRET o vuelve a sembrar la base.').not.toBe('')
    expect((await call(page, 'POST', '/auth/mfa/verify', { code: await freshTotp(secret) })).status).toBe(200)
  }
  await page.goto(new URL('/', page.url()).toString())
}

/** Ingreso con correo y clave por la pantalla, con el segundo factor si el rol lo exige. */
export async function signInWithEmail(page: Page, email: string, password: string, origin?: string): Promise<void> {
  await page.goto(origin ? new URL('/ingresar', origin).toString() : '/ingresar')
  await page.locator('#l-email').fill(email)
  await page.locator('#l-password').fill(password)
  await page.locator('form button[type="submit"]').first().click()
  await page.waitForURL((u) => u.pathname === '/' || u.pathname.startsWith('/verificacion'))
  await completeAppMfa(page, email)
  await expect(page.locator('#contenido')).toBeVisible()
}

/** Ingreso con documento de identidad y clave por la pantalla (pestaña «Documento»). */
export async function signInWithDocument(page: Page, docType: string, docNumber: string, password: string): Promise<void> {
  await page.goto('/ingresar')
  await page.getByRole('tab').nth(1).click()
  await page.locator('#l-doctype').selectOption(docType)
  await page.locator('#l-docnum').fill(docNumber)
  await page.locator('#l-password').fill(password)
  await page.locator('form button[type="submit"]').first().click()
  await page.waitForURL((u) => u.pathname === '/' || u.pathname.startsWith('/verificacion') || u.pathname.startsWith('/mi'))
  await completeAppMfa(page, `${docType}-${docNumber}`)
}

/** Consola de plataforma: ingreso con correo, clave y segundo factor (activación la primera vez). */
export async function signInConsole(page: Page): Promise<void> {
  await page.goto(consoleUrl('/plataforma/ingresar'))
  await page.locator('#c-email').fill(PLATFORM_EMAIL)
  await page.locator('#c-pass').fill(PLATFORM_PASSWORD)
  const store = secretStore(`console-${PLATFORM_EMAIL}`)
  const enrolled = page.waitForResponse((r) => r.url().endsWith('/api/v1/platform/auth/mfa/totp') && r.request().method() === 'POST', { timeout: 15_000 }).catch(() => null)
  await page.locator('form button[type="submit"]').first().click()
  await expect(page.locator('#c-code')).toBeVisible()
  const response = await enrolled
  let secret = ''
  if (response && response.ok()) {
    secret = ((await response.json()) as { data: { secret: string } }).data.secret
    writeFileSync(store, secret)
  } else {
    secret = process.env.E2E_PLATFORM_TOTP_SECRET ?? (existsSync(store) ? readFileSync(store, 'utf8').trim() : '')
  }
  expect(secret, 'La consola ya tiene segundo factor: indica E2E_PLATFORM_TOTP_SECRET o vuelve a crear el usuario.').not.toBe('')
  await page.locator('#c-code').fill(await freshTotp(secret))
  await page.locator('#c-code').press('Enter')
  await page.waitForURL((u) => u.pathname === '/plataforma')
}

/** Tamaño actual de la bitácora de correo: marca desde dónde buscar un correo nuevo. */
export function mailLogOffset(): number {
  return existsSync(MAIL_LOG) ? readFileSync(MAIL_LOG).length : 0
}

/** Primer valor que coincide con la expresión en un correo a esa dirección escrito después de la marca. */
export async function mailedMatch(to: string, pattern: RegExp, since: number, timeout = 150_000): Promise<string> {
  const started = Date.now()
  while (Date.now() - started < timeout) {
    if (existsSync(MAIL_LOG)) {
      const log = readFileSync(MAIL_LOG).subarray(since).toString('utf8')
      const at = log.indexOf(`To: ${to}`)
      if (at >= 0) {
        const found = log.slice(at).replace(/=\r?\n/g, '').match(pattern)
        if (found) return found[1] ?? found[0]
      }
    }
    await new Promise((r) => setTimeout(r, 1000))
  }
  throw new Error(`No llegó a ${to} un correo que coincida con ${pattern} (bitácora ${MAIL_LOG}).`)
}

/** Último enlace enviado por correo a esa dirección (MAIL_MAILER=log) que contiene la ruta indicada. */
export async function mailedLink(to: string, path: string, timeout = 30_000): Promise<string> {
  const started = Date.now()
  const pattern = new RegExp(`https?://[^\\s"'<>]+${path.replace(/[.*+?^${}()|[\]\\/]/g, '\\$&')}[^\\s"'<>]*`, 'g')
  while (Date.now() - started < timeout) {
    if (existsSync(MAIL_LOG)) {
      const log = readFileSync(MAIL_LOG, 'utf8')
      const at = log.lastIndexOf(`To: ${to}`)
      if (at >= 0) {
        const links = log.slice(at).replace(/=\r?\n/g, '').replace(/=3D/g, '=').match(pattern)
        if (links && links.length > 0) return links[0].replace(/&amp;/g, '&')
      }
    }
    await new Promise((r) => setTimeout(r, 500))
  }
  throw new Error(`No llegó a ${to} un correo con ${path} (bitácora ${MAIL_LOG}).`)
}

/**
 * El recorrido guiado del rol se abre solo en el primer ingreso y tapa la pantalla: se salta si aparece (espera
 * unos segundos a que cargue; si no aparece, sigue).
 */
export async function skipTour(page: Page, timeout = 5_000): Promise<void> {
  const skip = page.getByRole('button', { name: 'Saltar', exact: true })
  try {
    await skip.waitFor({ state: 'visible', timeout })
    await skip.click()
    await skip.waitFor({ state: 'hidden', timeout })
  } catch {
    // Sin recorrido pendiente para este usuario.
  }
}
