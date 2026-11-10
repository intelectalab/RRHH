import { AuthShell } from '@/modules/identity/AuthShell'
import { ForgotPasswordForm } from '@/modules/identity/ForgotPasswordForm'

export default function RecuperarClavePage() {
  return (
    <AuthShell>
      <ForgotPasswordForm />
    </AuthShell>
  )
}
