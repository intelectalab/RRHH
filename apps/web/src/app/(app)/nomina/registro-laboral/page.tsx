import { RegistryProfileScreen } from '@/modules/payroll/DeclarationComplianceScreens'

export default async function RegistroLaboralPage({ searchParams }: { searchParams: Promise<{ employment_id?: string }> }) {
  const { employment_id: employmentId } = await searchParams
  return <RegistryProfileScreen employmentId={employmentId ?? null} />
}
