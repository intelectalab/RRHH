import { ParametersScreen } from '@/modules/settings/ParametersScreen'

export default async function ParametrosPage({ params }: { params: Promise<{ group: string }> }) {
  const { group } = await params
  return <ParametersScreen group={group} />
}
