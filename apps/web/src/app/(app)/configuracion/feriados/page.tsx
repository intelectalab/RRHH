import { Suspense } from 'react'
import { HolidaysScreen } from '@/modules/settings/HolidaysScreen'

export default function FeriadosPage() {
  return (
    <Suspense>
      <HolidaysScreen />
    </Suspense>
  )
}
