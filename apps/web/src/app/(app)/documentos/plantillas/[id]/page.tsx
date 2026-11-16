import { DocumentTemplateEditor } from '@/modules/documents/DocumentTemplateEditor'

export default async function PlantillaPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params
  return <DocumentTemplateEditor id={id} />
}
