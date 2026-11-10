import { Suspense } from 'react'
import { InboxScreen } from '@/modules/workflow/InboxScreen'

export default function PendientesPage() {
  return (
    <Suspense>
      <InboxScreen />
    </Suspense>
  )
}
