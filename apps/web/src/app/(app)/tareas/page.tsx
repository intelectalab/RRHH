import { Suspense } from 'react'
import { TasksScreen } from '@/modules/workflow/TasksScreen'

export default function TareasPage() {
  return (
    <Suspense>
      <TasksScreen />
    </Suspense>
  )
}
