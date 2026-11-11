import type { ReactNode } from 'react'
import { ConsoleShell } from '@/modules/platform/ConsoleShell'

export default function ConsolaLayout({ children }: { children: ReactNode }) {
  return <ConsoleShell>{children}</ConsoleShell>
}
