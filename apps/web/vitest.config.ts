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
  },
  resolve: {
    alias: {
      '@': resolve(__dirname, 'src'),
      '@ui': resolve(__dirname, 'src/ui'),
      '@contracts': contractsPath,
    },
  },
})

