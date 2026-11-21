import { expect, test } from 'playwright/test'
import { call, generatedPassword, mailedLink, PLATFORM_PASSWORD, signInConsole, signInWithEmail, tenantUrl } from './support'

/**
 * QA-2 · Alta de una organización desde la consola de plataforma y primer ingreso de su administrador:
 * la consola crea la organización con su empresa y su administrador invitado; el administrador acepta la invitación
 * del correo, define su clave, activa el segundo factor y entra a su organización (vacía, sin datos de la demo).
 * La clave del usuario de la consola llega por E2E_PLATFORM_PASSWORD (la misma PLATFORM_ADMIN_PASSWORD con la que
 * se creó con `php artisan platform:create-admin`).
 */
test('consola: crear una organización y entrar con su primer administrador', async ({ page, browser }) => {
  test.skip(PLATFORM_PASSWORD === '', 'Falta E2E_PLATFORM_PASSWORD (usuario de la consola creado con platform:create-admin).')
  // La invitación sale por el despacho periódico del outbox (programador: cada minuto).
  test.setTimeout(240_000)

  // 1 · Ingreso a la consola (correo, clave y segundo factor) en el dominio base, fuera de toda organización.
  await signInConsole(page)

  // 2 · Nueva organización por el formulario de la consola.
  const stamp = Date.now().toString(36)
  const slug = `qa2-${stamp}`
  const adminEmail = `admin-${stamp}@qa2.gestiahr.test`
  await page.goto(page.url().replace(/\/plataforma.*$/, '/plataforma/organizaciones'))
  await page.locator('#tn-q').waitFor()
  await page.getByRole('button', { name: /nueva organización/i }).click()
  await page.locator('#nt-name').fill(`Organización QA2 ${stamp}`)
  await page.locator('#nt-slug').fill(slug)
  await page.locator('#nt-legal_name').fill(`Empresa QA2 ${stamp} S.A.C.`)
  await page.locator('#nt-tax_id').fill(`20${String(Date.now()).slice(-9)}`)
  await page.locator('#nt-admin_name').fill('Ana Administradora')
  await page.locator('#nt-admin_email').fill(adminEmail)
  await page.locator('button[form="nt-form"]').click()
  await page.waitForURL(/\/plataforma\/organizaciones\/[0-9a-f-]{36}$/)
  await expect(page.getByText(slug).first()).toBeVisible()

  // La organización recién creada aparece en el listado de la consola.
  const listed = await call(page, 'GET', `/tenants?q=${slug}`, undefined, { prefix: '/api/v1/platform', xsrfCookie: 'CONSOLE-XSRF-TOKEN' })
  expect(listed.status, listed.text).toBe(200)
  expect(JSON.stringify(listed.body.data)).toContain(slug)

  // 3 · El administrador invitado acepta la invitación del correo en el subdominio de su organización.
  const link = await mailedLink(adminEmail, '/activar-cuenta', 150_000)
  const invitation = new URL(link)
  const context = await browser.newContext()
  const admin = await context.newPage()
  await admin.goto(tenantUrl(slug, `/activar-cuenta${invitation.search}`))
  const password = generatedPassword()
  await admin.locator('#r-pass').fill(password)
  await admin.locator('#r-conf').fill(password)
  await admin.locator('form button[type="submit"]').first().click()
  await admin.waitForURL((u) => !u.pathname.startsWith('/activar-cuenta'))

  // 4 · Ingreso con su correo y la clave nueva (más el segundo factor que exige su rol) en su organización.
  await signInWithEmail(admin, adminEmail, password, tenantUrl(slug))
  const me = await call(admin, 'GET', '/me')
  expect(me.status, me.text).toBe(200)
  const session = me.body.data as { tenant: { slug: string }; roles: { code: string }[] }
  expect(session.tenant.slug).toBe(slug)
  expect(session.roles.map((r) => r.code)).toContain('admin_empresa')
  // Organización nueva: no ve a nadie de la demo (aislamiento) y su empresa es la del alta.
  const people = await call(admin, 'GET', '/employments?per_page=5')
  expect(people.status, people.text).toBe(200)
  expect((people.body.data as unknown[]).length).toBe(0)
  const entities = await call(admin, 'GET', '/legal-entities')
  expect(JSON.stringify(entities.body.data)).toContain(`Empresa QA2 ${stamp}`)
  await context.close()
})
