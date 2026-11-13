import { EmploymentProfileScreen } from '@/modules/people/profile/EmploymentProfileScreen'

export default async function ColaboradorPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params
  return <EmploymentProfileScreen id={id} />
}
