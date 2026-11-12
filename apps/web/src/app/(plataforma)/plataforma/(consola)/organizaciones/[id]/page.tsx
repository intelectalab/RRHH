import { ConsoleTenantDetail } from '@/modules/platform/ConsoleTenantDetail'

export default async function ConsolaOrganizacionPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params
  return <ConsoleTenantDetail id={id} />
}
