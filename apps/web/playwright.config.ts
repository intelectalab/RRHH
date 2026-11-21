import { defineConfig } from 'playwright/test'

/**
 * QA-2 · Recorridos de extremo a extremo (make test-e2e). Requieren la API y la web levantadas sobre una base con la
 * organización demo sembrada; se entra por el subdominio de la organización (demo.localhost).
 */
export default defineConfig({
  testDir: './e2e',
  timeout: 120_000,
  // Los recorridos comparten la organización demo y el segundo factor de su administrador: uno a la vez.
  workers: 1,
  fullyParallel: false,
  retries: process.env.CI ? 1 : 0,
  reporter: process.env.CI ? [['list'], ['html', { open: 'never' }]] : 'list',
  use: {
    baseURL: process.env.E2E_BASE_URL ?? 'http://demo.localhost:3000',
    locale: 'es-PE',
    trace: 'retain-on-failure',
  },
  // E2E_CHROMIUM_PATH: usar un Chromium ya instalado (p. ej. un entorno sin descarga de navegadores).
  projects: [{
    name: 'chromium',
    use: { browserName: 'chromium', launchOptions: process.env.E2E_CHROMIUM_PATH ? { executablePath: process.env.E2E_CHROMIUM_PATH } : {} },
  }],
})
