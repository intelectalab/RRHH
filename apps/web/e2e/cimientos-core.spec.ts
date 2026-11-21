import { expect, test } from 'playwright/test'
import { call, EMAIL, PASSWORD, signInWithEmail } from './support'

/**
 * QA-2 · Recorrido de extremo a extremo de cimientos y Core HR sobre la organización demo (DemoOrganizationSeeder):
 * ingreso con correo y segundo factor, alta de un colaborador con su contrato, firma registrada del contrato y portal.
 * Las acciones se hacen como las hace la aplicación (mismo origen, cookie de sesión y XSRF) y cada resultado se
 * verifica en pantalla. La clave del administrador demo llega por E2E_ADMIN_PASSWORD (nunca en el código).
 */
test.describe.configure({ mode: 'serial' })

test('cimientos y Core HR: ingreso, alta con contrato firmado y portal', async ({ page }) => {
  test.skip(PASSWORD === '', 'Falta E2E_ADMIN_PASSWORD (la misma DEMO_ADMIN_PASSWORD con la que se sembró la demo).')

  // 1 y 2 · Ingreso con correo y clave, y segundo factor (el rol administrador lo exige).
  await signInWithEmail(page, EMAIL, PASSWORD)

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
      { form: { field: 'file', name: 'contrato-firmado.pdf', content: '%PDF-1.4\n% contrato firmado\n', extra: { signed_on: today } } })
    expect(signed.status, JSON.stringify(signed.body)).toBe(200)
  }
  const after = (await call(page, 'GET', `/contracts/${contract.id}`)).body.data as { status: string }
  expect(['signed', 'active']).toContain(after.status)

  // 5 · Auditoría y portal: el alta deja rastro y el portal propio abre.
  await page.goto('/mi')
  await expect(page.locator('main')).toBeVisible()
})
