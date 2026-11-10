import { Suspense } from 'react'
import { AuthShell } from '@/modules/identity/AuthShell'
import { ResetPasswordForm } from '@/modules/identity/ResetPasswordForm'

export default function RestablecerClavePage() {
  return (
    <AuthShell>
      <Suspense>
        <ResetPasswordForm />
      </Suspense>
    </AuthShell>
  )
}
