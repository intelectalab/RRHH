import { AuthShell } from '@/modules/identity/AuthShell'
import { SignInEntry } from '@/modules/identity/QuickUnlock'

export default function IngresarPage() {
  return (
    <AuthShell>
      <SignInEntry />
    </AuthShell>
  )
}
