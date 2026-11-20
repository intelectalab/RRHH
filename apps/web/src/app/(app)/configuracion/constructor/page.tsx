import { Suspense } from 'react'
import { FlowBuilderListScreen } from '@/modules/workflow/FlowBuilderScreen'

export default function ConstructorPage() {
  return (
    <Suspense>
      <FlowBuilderListScreen />
    </Suspense>
  )
}
