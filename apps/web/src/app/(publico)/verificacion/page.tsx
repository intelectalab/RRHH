import { AuthShell } from '@/modules/identity/AuthShell'
import { MfaChallenge } from '@/modules/identity/MfaChallenge'

export default function VerificacionPage() {
  return (
    <AuthShell>
      <MfaChallenge />
    </AuthShell>
  )
}
