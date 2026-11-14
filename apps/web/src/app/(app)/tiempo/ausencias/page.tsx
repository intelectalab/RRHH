import { Suspense } from 'react'
import { AbsencesScreen } from '@/modules/time/AbsencesScreen'

export default function AusenciasPage() {
  return (
    <Suspense>
      <AbsencesScreen />
    </Suspense>
  )
}
