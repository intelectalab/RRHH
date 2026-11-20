import { FlowEditorScreen } from '@/modules/workflow/FlowBuilderScreen'

export default async function ConstructorFlujoPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params
  return <FlowEditorScreen id={id} />
}
