import { defineConfig } from 'vitest/config'
import react from '@vitejs/plugin-react'
import { resolve } from 'path'
import { existsSync } from 'fs'

const contractsPath = existsSync('/packages/contracts/src')
  ? '/packages/contracts/src'
  : resolve(__dirname, '../../packages/contracts/src')

export default defineConfig({
  plugins: [react()],
  test: {
    environment: 'node',
    globals: true,
    setupFiles: ['./src/test/setup.ts'],
    // QA-2 · Los recorridos de extremo a extremo los corre Playwright (npm run test:e2e), no Vitest.
    exclude: ['**/node_modules/**', 'e2e/**'],
  },
  resolve: {
    alias: {
      '@': resolve(__dirname, 'src'),
      '@ui': resolve(__dirname, 'src/ui'),
      '@contracts': contractsPath,
    },
  },
})

