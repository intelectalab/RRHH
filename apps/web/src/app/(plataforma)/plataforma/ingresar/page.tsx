import { AuthShell } from '@/modules/identity/AuthShell'
import { ConsoleLogin } from '@/modules/platform/ConsoleLogin'

export default function ConsolaIngresarPage() {
  return (
    <AuthShell>
      <ConsoleLogin />
    </AuthShell>
  )
}
