import { ConceptEditor } from '@/modules/payroll/ConceptEditor'

export default async function ConceptoPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params
  return <ConceptEditor id={id} />
}
