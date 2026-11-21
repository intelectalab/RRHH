import { expect, test, type Page } from 'playwright/test'
import { call, EMAIL, generatedPassword, PASSWORD, signInWithDocument, signInWithEmail } from './support'

/**
 * QA-2 · Ingreso con documento y sueldos ocultos (criterio de aceptación: un rol sin permiso de sueldos no los ve
 * en pantalla, API ni exportación). El administrador demo crea un rol de consulta sin el permiso de campo
 * people.employments.salary.view y un usuario sin correo, solo con DNI; el usuario activa su cuenta con el código
 * de activación, ingresa con documento y clave, y en ningún canal aparece el sueldo que el administrador sí ve.
 */
type Money = { amount: string; currency: string }

/** Formas en que un monto puede aparecer en pantalla o en un archivo: 3500.00, 3,500.00, 3.500,00, 3 500… */
function moneyPatterns(amount: string): RegExp {
  const [int] = amount.split('.')
  const grouped = int.replace(/\B(?=(\d{3})+(?!\d))/g, '[.,\\s\\u00a0\\u202f]?')
  return new RegExp(`(^|[^\\d])${grouped}([.,]\\d{2})?([^\\d]|$)`)
}

async function bodyText(page: Page): Promise<string> {
  return (await page.locator('body').innerText()).replace(/\s+/g, ' ')
}

test('documento y sueldos: un rol sin permiso de sueldos no los ve en pantalla, API ni exportación', async ({ page, browser }) => {
  test.skip(PASSWORD === '', 'Falta E2E_ADMIN_PASSWORD (la misma DEMO_ADMIN_PASSWORD con la que se sembró la demo).')

  // 1 · El administrador ve el sueldo de un colaborador de la demo (control positivo).
  await signInWithEmail(page, EMAIL, PASSWORD)
  const list = await call(page, 'GET', '/employments?status=active&per_page=20')
  expect(list.status, list.text).toBe(200)
  let target: { id: string; salary: Money } | null = null
  for (const e of list.body.data as { id: string }[]) {
    const comp = await call(page, 'GET', `/employments/${e.id}/compensations`)
    const current = (comp.body.data as { base_salary?: Money; valid_to: string | null }[] | undefined)?.find((c) => c.valid_to === null && c.base_salary)
    if (current?.base_salary && Number(current.base_salary.amount) >= 1000) {
      target = { id: e.id, salary: current.base_salary }
      break
    }
  }
  expect(target, 'La demo debe tener un colaborador activo con sueldo vigente.').not.toBeNull()
  const { id, salary } = target as { id: string; salary: Money }
  const shown = moneyPatterns(salary.amount)
  await page.goto(`/personas/colaboradores/${id}?tab=compensation`)
  await expect(page.getByRole('tab', { name: /compensación/i })).toBeVisible()
  await expect.poll(async () => shown.test(await bodyText(page)), { timeout: 15_000 }).toBe(true)

  // 2 · Rol de consulta sin el permiso de campo de sueldos y usuario sin correo, solo con DNI.
  const stamp = Date.now().toString(36)
  const role = await call(page, 'POST', '/roles', {
    name: `Consulta sin sueldos ${stamp}`,
    description: 'QA-2: ve colaboradores y exporta, sin sueldos.',
    scope: 'organization',
    permissions: ['people.employments.view', 'people.people.view', 'people.compensations.view', 'reports.exports.view', 'reports.exports.create'],
  })
  expect(role.status, role.text).toBe(201)
  const roleId = (role.body.data as { id: string }).id
  const dni = String(40000000 + Math.floor(Math.random() * 9999999))
  const user = await call(page, 'POST', '/users', { display_name: `Consulta QA2 ${stamp}`, document_type: 'dni', document_number: dni, role_ids: [roleId] })
  expect(user.status, user.text).toBe(201)
  const userId = (user.body.data as { id: string }).id
  const issued = await call(page, 'POST', `/users/${userId}/activation-code`, { channel: 'in_app' })
  expect(issued.status, issued.text).toBe(200)
  const code = (issued.body.data as { code: string }).code
  expect(code).toMatch(/^\d{8}$/)

  // 3 · Activación de la cuenta con documento y código, y luego ingreso con documento y clave.
  const context = await browser.newContext()
  const viewer = await context.newPage()
  const password = generatedPassword()
  await viewer.goto('/activar-cuenta')
  await viewer.locator('#a-doctype').selectOption('dni')
  await viewer.locator('#a-docnum').fill(dni)
  await viewer.locator('#a-code').fill(code)
  await viewer.locator('#a-password').fill(password)
  await viewer.locator('#a-confirm').fill(password)
  await viewer.locator('form button[type="submit"]').first().click()
  await viewer.waitForURL((u) => !u.pathname.startsWith('/activar-cuenta'))
  expect((await call(viewer, 'POST', '/auth/logout', {})).status).toBe(200)
  await context.clearCookies()
  await signInWithDocument(viewer, 'dni', dni, password)
  const me = await call(viewer, 'GET', '/me')
  expect(me.status, me.text).toBe(200)
  const permissions = (me.body.data as { permissions: string[] }).permissions
  expect(permissions).toContain('people.employments.view')
  expect(permissions).not.toContain('people.employments.salary.view')

  // 4 · Pantalla: la ficha abre, pero sin pestaña de compensación ni el monto en ninguna pestaña.
  await viewer.goto(`/personas/colaboradores/${id}`)
  await expect(viewer.locator('#contenido')).toBeVisible()
  await expect(viewer.getByRole('tab').first()).toBeVisible()
  await expect(viewer.getByRole('tab', { name: /compensación/i })).toHaveCount(0)
  for (const tab of await viewer.getByRole('tab').all()) {
    await tab.click()
    await viewer.waitForLoadState('networkidle')
    expect(shown.test(await bodyText(viewer)), `El sueldo aparece en la pestaña «${await tab.innerText()}».`).toBe(false)
  }
  await viewer.goto(`/personas/colaboradores/${id}?tab=compensation`)
  await viewer.waitForLoadState('networkidle')
  expect(shown.test(await bodyText(viewer))).toBe(false)

  // 5 · API: el campo no viaja (no se enmascara en el cliente).
  const employment = await call(viewer, 'GET', `/employments/${id}`)
  expect(employment.status, employment.text).toBe(200)
  expect(employment.body.data).not.toHaveProperty('base_salary')
  const comps = await call(viewer, 'GET', `/employments/${id}/compensations`)
  expect(comps.status, comps.text).toBe(200)
  for (const c of comps.body.data as Record<string, unknown>[]) {
    expect(c).not.toHaveProperty('base_salary')
    expect(c).not.toHaveProperty('components')
  }
  expect(shown.test(comps.text)).toBe(false)
  expect(shown.test(employment.text)).toBe(false)

  // 6 · Exportación: el listado de colaboradores sale sin el sueldo; las exportaciones con montos se niegan.
  const exported = await call(viewer, 'POST', '/exports', { resource: 'employments', format: 'csv', filters: {} })
  expect(exported.status, exported.text).toBe(201)
  const job = exported.body.data as { id: string; status: string }
  await expect.poll(async () => ((await call(viewer, 'GET', `/exports/${job.id}`)).body.data as { status: string }).status, { timeout: 30_000 }).toBe('completed')
  const file = await call(viewer, 'GET', `/exports/${job.id}/download`)
  expect(file.status).toBe(200)
  expect(file.text.split('\n').length).toBeGreaterThan(1)
  expect(shown.test(file.text), 'El sueldo aparece en la exportación.').toBe(false)
  const ledger = await call(viewer, 'POST', '/exports', { resource: 'fifth_category_ledger', format: 'csv', filters: {} })
  expect([403, 404, 422], ledger.text).toContain(ledger.status)
  expect(ledger.status).not.toBe(201)

  await context.close()
})
