import { EmploymentScreen } from '@/modules/people/EmploymentScreen'

export default async function ColaboradorPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params
  return <EmploymentScreen id={id} />
}
