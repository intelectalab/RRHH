import { Suspense } from 'react'
import { VacationRequestsScreen } from '@/modules/time/VacationRequestsScreen'

export default function VacacionesPage() {
  return (
    <Suspense>
      <VacationRequestsScreen />
    </Suspense>
  )
}
