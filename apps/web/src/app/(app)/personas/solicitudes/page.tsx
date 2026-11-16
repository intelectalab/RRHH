import { Suspense } from 'react'
import { TicketsAdminScreen } from '@/modules/requests/TicketsAdminScreen'

export default function SolicitudesRrhhPage() {
  return (
    <Suspense>
      <TicketsAdminScreen />
    </Suspense>
  )
}
