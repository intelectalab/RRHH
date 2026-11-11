import { Suspense } from 'react'
import { AutomationsScreen } from '@/modules/workflow/AutomationsScreen'

export default function AutomatizacionesPage() {
  return (
    <Suspense>
      <AutomationsScreen />
    </Suspense>
  )
}
