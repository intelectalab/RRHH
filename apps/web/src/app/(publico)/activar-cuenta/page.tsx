import { Suspense } from 'react'
import { AuthShell } from '@/modules/identity/AuthShell'
import { ResetPasswordForm } from '@/modules/identity/ResetPasswordForm'

export default function ActivarCuentaPage() {
  return (
    <AuthShell>
      <Suspense>
        <ResetPasswordForm mode="activate" />
      </Suspense>
    </AuthShell>
  )
}
