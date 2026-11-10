import { Suspense } from 'react'
import { ApprovalsScreen } from '@/modules/workflow/ApprovalsScreen'

export default function AprobacionesPage() {
  return (
    <Suspense>
      <ApprovalsScreen />
    </Suspense>
  )
}
