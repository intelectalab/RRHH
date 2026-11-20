import { Suspense } from 'react'
import { MySignaturesScreen } from '@/modules/selfservice/MySignaturesScreen'

export default function MisFirmasPage() {
  return (
    <Suspense>
      <MySignaturesScreen />
    </Suspense>
  )
}
