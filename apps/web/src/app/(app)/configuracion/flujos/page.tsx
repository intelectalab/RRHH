import { Suspense } from 'react'
import { FlowsScreen } from '@/modules/workflow/FlowsScreen'

export default function FlujosPage() {
  return (
    <Suspense>
      <FlowsScreen />
    </Suspense>
  )
}
