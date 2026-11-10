import { AuthShell } from '@/modules/identity/AuthShell'
import { LoginForm } from '@/modules/identity/LoginForm'

export default function IngresarPage() {
  return (
    <AuthShell>
      <LoginForm />
    </AuthShell>
  )
}
