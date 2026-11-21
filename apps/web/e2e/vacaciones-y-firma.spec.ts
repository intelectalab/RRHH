import { expect, test, type Browser, type Page } from 'playwright/test'
import { call, completeAppMfa, EMAIL, generatedPassword, mailedMatch, mailLogOffset, PASSWORD, signInWithEmail } from './support'

/**
 * QA-2 · Vacaciones de punta a punta y firma electrónica por la bandeja, con tres personas de la demo:
 * el administrador prepara las cuentas y el documento; el colaborador pide vacaciones y firma desde su bandeja con el
 * código que le llega por correo; RR.HH. aprueba desde la bandeja de aprobaciones. Al final se verifica el estado de
 * la solicitud, el saldo descontado y la firma con su evidencia. Las claves se generan en la corrida.
 */
type Role = { id: string; code?: string | null; system_role_code?: string | null; name: string }

/** Activa una cuenta con documento y código (sin correo de invitación) y deja la sesión abierta. */
async function activate(browser: Browser, docNumber: string, code: string): Promise<Page> {
  const context = await browser.newContext()
  const page = await context.newPage()
  const password = generatedPassword()
  await page.goto('/activar-cuenta')
  await page.locator('#a-doctype').selectOption('dni')
  await page.locator('#a-docnum').fill(docNumber)
  await page.locator('#a-code').fill(code)
  await page.locator('#a-password').fill(password)
  await page.locator('#a-confirm').fill(password)
  await page.locator('form button[type="submit"]').first().click()
  await page.waitForURL((u) => !u.pathname.startsWith('/activar-cuenta'))
  await completeAppMfa(page, `dni-${docNumber}`)
  return page
}

/** Lunes al menos 40 días después de hoy y después de las vacaciones que ya tiene (sin cruces). */
function futureMonday(after: string[] = []): Date {
  const d = new Date()
  d.setUTCDate(d.getUTCDate() + 40)
  for (const end of after) {
    const e = new Date(`${end}T00:00:00Z`)
    e.setUTCDate(e.getUTCDate() + 3)
    if (e > d) d.setTime(e.getTime())
  }
  while (d.getUTCDay() !== 1) d.setUTCDate(d.getUTCDate() + 1)
  return d
}
const iso = (d: Date) => d.toISOString().slice(0, 10)

test('vacaciones de punta a punta y firma electrónica por la bandeja', async ({ page, browser }) => {
  test.skip(PASSWORD === '', 'Falta E2E_ADMIN_PASSWORD (la misma DEMO_ADMIN_PASSWORD con la que se sembró la demo).')
  // El código de firma sale por el despacho periódico del outbox (programador: cada minuto).
  test.setTimeout(300_000)
  const stamp = Date.now().toString(36)

  // 1 · Administrador: colaborador activo con saldo, con jefe directo, y ninguno de los dos con usuario todavía.
  await signInWithEmail(page, EMAIL, PASSWORD)
  const balances = await call(page, 'GET', '/vacation-balances?per_page=100')
  expect(balances.status, balances.text).toBe(200)
  const users = await call(page, 'GET', '/users?per_page=100')
  const linked = new Set((users.body.data as { person_id?: string | null }[]).map((u) => u.person_id).filter(Boolean))
  type Emp = { id: string; person_id: string; person_name: string; manager_name: string | null; status: string }
  const all = (await call(page, 'GET', '/employments?filter[status]=active&per_page=100')).body.data as Emp[]
  const norm = (n: string | null) => (n ?? '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/\s+/g, ' ').trim().toLowerCase()
  let employee: Emp | null = null
  let manager: Emp | null = null
  for (const b of balances.body.data as { employment_id: string; status: string; available_days: string }[]) {
    if (b.status !== 'active' || Number(b.available_days) < 10) continue
    const e = (await call(page, 'GET', `/employments/${b.employment_id}`)).body.data as Emp
    const boss = all.find((m) => m.id !== e.id && e.manager_name && norm(m.person_name) === norm(e.manager_name))
    if (!linked.has(e.person_id) && boss && !linked.has(boss.person_id)) {
      employee = e
      manager = boss
      break
    }
  }
  expect(employee, 'La demo debe tener un colaborador activo con 10 días disponibles y un jefe directo, ambos sin usuario.').not.toBeNull()
  const { id: employmentId, person_id: personId } = employee as Emp
  const roles = (await call(page, 'GET', '/roles?per_page=100')).body.data as Role[]
  const roleId = (code: string) => roles.find((r) => (r.system_role_code ?? r.code) === code)?.id
  expect(roleId('empleado')).toBeTruthy()
  expect(roleId('rrhh')).toBeTruthy()
  expect(roleId('manager')).toBeTruthy()

  const empEmail = `colaborador-${stamp}@qa2.gestiahr.test`
  const empDni = String(41000000 + Math.floor(Math.random() * 999999))
  const empUser = await call(page, 'POST', '/users', { display_name: `Colaborador QA2 ${stamp}`, email: empEmail, document_type: 'dni', document_number: empDni, person_id: personId, role_ids: [roleId('empleado')] })
  expect(empUser.status, empUser.text).toBe(201)
  const empUserId = (empUser.body.data as { id: string }).id
  const hrDni = String(42000000 + Math.floor(Math.random() * 999999))
  const hrUser = await call(page, 'POST', '/users', { display_name: `RRHH QA2 ${stamp}`, document_type: 'dni', document_number: hrDni, role_ids: [roleId('rrhh')] })
  expect(hrUser.status, hrUser.text).toBe(201)
  const bossDni = String(43000000 + Math.floor(Math.random() * 999999))
  const bossUser = await call(page, 'POST', '/users', { display_name: `Jefe QA2 ${stamp}`, document_type: 'dni', document_number: bossDni, person_id: (manager as Emp).person_id, role_ids: [roleId('manager')] })
  expect(bossUser.status, bossUser.text).toBe(201)
  const code = async (userId: string) => ((await call(page, 'POST', `/users/${userId}/activation-code`, { channel: 'in_app' })).body.data as { code: string }).code
  const empCode = await code(empUserId)
  const hrCode = await code((hrUser.body.data as { id: string }).id)
  const bossCode = await code((bossUser.body.data as { id: string }).id)

  // Documento del legajo del colaborador, enviado a su firma electrónica.
  const categories = (await call(page, 'GET', '/document-categories')).body.data as { id: string }[]
  const uploaded = await call(page, 'POST', '/documents', undefined, { form: { field: 'file', name: `politica-${stamp}.pdf`, content: '%PDF-1.4\n% politica interna QA2\n%%EOF\n', extra: { category_id: categories[0].id, title: `Política interna QA2 ${stamp}`, employment_id: employmentId, visible_to_employee: 'true' } } })
  expect(uploaded.status, uploaded.text).toBe(201)
  const documentId = (uploaded.body.data as { id: string }).id
  const signature = await call(page, 'POST', '/signature-requests', { document_id: documentId, title: `Política interna QA2 ${stamp}`, signers: [{ user_id: empUserId }], send: true })
  expect(signature.status, signature.text).toBe(201)
  const signatureId = (signature.body.data as { id: string }).id

  // El jefe activa su cuenta antes de la solicitud: el flujo salta a los aprobadores sin cuenta activa.
  const boss = await activate(browser, bossDni, bossCode)

  // 2 · Colaborador: activa su cuenta, ve su saldo y pide vacaciones por la pantalla.
  const emp = await activate(browser, empDni, empCode)
  const before = await call(emp, 'GET', `/vacation-balances/${employmentId}`)
  expect(before.status, before.text).toBe(200)
  const availableBefore = Number((before.body.data as { available_days: string }).available_days)
  const existing = (await call(emp, 'GET', '/vacation-requests?per_page=100')).body.data as { ends_on: string; status: string }[]
  const start = futureMonday(existing.filter((r) => !['cancelled', 'rejected'].includes(r.status)).map((r) => r.ends_on))
  const end = new Date(start)
  end.setUTCDate(end.getUTCDate() + 6)
  await emp.goto('/tiempo/vacaciones')
  await emp.getByRole('button', { name: 'Solicitar vacaciones' }).click()
  // Con una sola relación laboral propia, el formulario la preselecciona al cargar.
  await expect(emp.locator('#vr-employment_id')).toHaveValue(employmentId)
  await emp.locator('#vr-starts_on').fill(iso(start))
  await emp.locator('#vr-ends_on').fill(iso(end))
  await emp.locator('#vr-notes').fill(`QA2 ${stamp}`)
  const created = emp.waitForResponse((r) => r.url().endsWith('/api/v1/vacation-requests') && r.request().method() === 'POST')
  await emp.locator('button[form="vacaciones"]').click()
  const createdResponse = await created
  expect(createdResponse.status(), await createdResponse.text()).toBe(201)
  const requestId = ((await createdResponse.json()) as { data: { id: string; days: string | number } }).data.id
  await emp.getByRole('button', { name: 'Enviar', exact: true }).click()
  await expect.poll(async () => ((await call(emp, 'GET', `/vacation-requests/${requestId}`)).body.data as { status: string }).status).toBe('pending')

  // 3 · Aprobación en dos niveles desde la bandeja: primero el jefe directo, luego RR.HH.
  const who = new RegExp(`Colaborador QA2 ${stamp}|${(employee as Emp).person_name}`)
  const approveFromInbox = async (approver: Page) => {
    await approver.goto('/aprobaciones')
    await expect(approver.locator('#contenido').getByText(who).first()).toBeVisible({ timeout: 15_000 })
    const approveButton = approver.getByRole('button', { name: 'Aprobar', exact: true })
    // Se toma la fila más interna que tiene al solicitante y el botón Aprobar.
    const card = approver.locator('#contenido *').filter({ hasText: who }).filter({ has: approveButton }).last()
    await card.locator(approveButton).first().click()
  }
  const flow = async () => ((await call(page, 'GET', '/approval-requests?per_page=50')).body.data as { subject_id: string; current_step: number; status: string }[]).find((r) => r.subject_id === requestId)
  await expect.poll(async () => (await flow())?.current_step).toBe(1)
  await approveFromInbox(boss)
  await expect.poll(async () => (await flow())?.current_step, { timeout: 20_000 }).toBe(2)
  expect(((await call(emp, 'GET', `/vacation-requests/${requestId}`)).body.data as { status: string }).status).not.toBe('approved')
  const hr = await activate(browser, hrDni, hrCode)
  await approveFromInbox(hr)
  await expect.poll(async () => ((await call(emp, 'GET', `/vacation-requests/${requestId}`)).body.data as { status: string }).status, { timeout: 20_000 }).toBe('approved')

  // 4 · El colaborador ve su solicitud aprobada y el saldo con los días descontados.
  const request = (await call(emp, 'GET', `/vacation-requests/${requestId}`)).body.data as { days?: string | number; requested_days?: string | number }
  const days = Number(request.days ?? request.requested_days ?? 7)
  await expect.poll(async () => Number(((await call(emp, 'GET', `/vacation-balances/${employmentId}`)).body.data as { available_days: string }).available_days)).toBe(availableBefore - days)
  await emp.goto('/tiempo/vacaciones')
  await expect(emp.getByText('Aprobada (programada)').filter({ visible: true }).first()).toBeVisible()

  // 5 · Firma por la bandeja: abre el documento, pide el código (llega por correo) y firma.
  await emp.goto(`/mi/firmas?id=${signatureId}`)
  const title = emp.getByRole('button', { name: `Política interna QA2 ${stamp}` })
  if (await title.count()) await title.first().click()
  await emp.getByRole('button', { name: 'Ver documento' }).click()
  // El visor se abre encima del panel; se cierra para seguir con el código.
  const viewer = emp.locator('.modal[role="dialog"]')
  await expect(viewer).toBeVisible()
  await viewer.getByRole('button', { name: 'Cerrar', exact: true }).last().click()
  await expect(viewer).toHaveCount(0)
  const offset = mailLogOffset()
  await emp.getByRole('button', { name: 'Enviarme el código' }).click()
  const otp = await mailedMatch(empEmail, /\b(\d{6})\b/, offset)
  await emp.locator('#sig-code-input').fill(otp)
  await emp.getByRole('button', { name: 'Firmar', exact: true }).click()
  await expect(emp.getByText('Documento firmado.').first()).toBeVisible()

  // 6 · Verificación: la solicitud de firma queda firmada y con evidencia para RR.HH./administración.
  await expect.poll(async () => ((await call(page, 'GET', `/signature-requests/${signatureId}`)).body.data as { status: string }).status).toBe('completed')
  const evidence = await call(page, 'GET', `/signature-requests/${signatureId}/evidence`)
  expect(evidence.status, evidence.text).toBe(200)
  expect(evidence.text).toContain(empUserId.slice(0, 8))

  await emp.context().close()
  await hr.context().close()
  await boss.context().close()
})
