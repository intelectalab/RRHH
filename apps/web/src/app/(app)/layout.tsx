import type { ReactNode } from 'react'
import { AppShell } from '@/modules/session/AppShell'

export default function AppLayout({ children }: { children: ReactNode }) {
  return <AppShell>{children}</AppShell>
}
