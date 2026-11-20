'use client'

import { Fragment, useEffect, useState } from 'react'
import { api } from '@/lib/api'
import { formErrors } from '@/lib/errors'
import { useT } from '@/lib/useT'
import { CustomFieldsEditor, useCustomFields, type CustomValues } from '@/modules/customfields/CustomFieldsEditor'
import { customCell } from '@/modules/customfields/customTable'
import { Card } from '@/ui/components/Card'

/**
 * CFG-5 · Datos adicionales de la relación laboral (campos personalizados de «employment»). Se ven y
 * se editan como los nativos; tipo, obligatoriedad y permiso por campo los valida la API.
 */
export function CustomFieldsCard({ employmentId, toast }: { employmentId: string; toast: (text: string, kind?: 'ok' | 'error') => void }) {
  const { t } = useT()
  const custom = useCustomFields('employment')
  const [values, setValues] = useState<CustomValues>({})
  const [draft, setDraft] = useState<CustomValues | null>(null)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [busy, setBusy] = useState(false)

  useEffect(() => {
    if (custom.fields.length === 0) return
    void api.get<{ data: { custom_fields?: CustomValues } }>(`/employments/${employmentId}`).then((r) => setValues(r.data.custom_fields ?? {})).catch(() => setValues({}))
  }, [employmentId, custom.fields.length])

  if (custom.fields.length === 0) return null

  const save = async () => {
    if (!draft) return
    setBusy(true)
    try {
      const r = await api.request<{ data: { custom_fields?: CustomValues } }>(`/employments/${employmentId}`, 'PATCH', { custom_fields: draft })
      setValues(r.data.custom_fields ?? {})
      setDraft(null)
      setErrors({})
      toast(t('cf.saved'))
    } catch (e) {
      const parsed = formErrors(e, t)
      setErrors(parsed.fields)
      if (parsed.general) toast(parsed.general, 'error')
    } finally {
      setBusy(false)
    }
  }

  return (
    <Card
      title={t('cf.more')}
      actions={custom.canEdit && draft === null ? <button type="button" className="btn btn-ter btn-sm" onClick={() => setDraft({ ...values })}>{t('ficha.edit')}</button> : undefined}
    >
      {draft === null ? (
        <dl className="ficha">
          {custom.fields.map((f) => (
            <Fragment key={f.key}><dt>{f.label}</dt><dd>{customCell(f, values[f.key], t)}</dd></Fragment>
          ))}
        </dl>
      ) : (
        <form onSubmit={(e) => { e.preventDefault(); void save() }}>
          <CustomFieldsEditor fields={custom.fields} values={draft} onChange={setDraft} errors={errors} disabled={busy} />
          <div className="d-flex gap-2 justify-content-end mt-3">
            <button type="button" className="btn btn-sec" onClick={() => { setDraft(null); setErrors({}) }}>{t('cf.cancel')}</button>
            <button type="submit" className="btn btn-primary" disabled={busy}>{t('cf.save')}</button>
          </div>
        </form>
      )}
    </Card>
  )
}
