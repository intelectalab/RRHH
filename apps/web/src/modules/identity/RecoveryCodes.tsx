'use client'

import { useT } from '@/lib/useT'

/** Códigos de respaldo: se muestran una sola vez, con copiar y descargar. */
export function RecoveryCodes({ codes, onDone, doneLabel }: { codes: string[]; onDone: () => void; doneLabel: string }) {
  const { t } = useT()
  const text = codes.join('\n')

  function download() {
    const url = URL.createObjectURL(new Blob([`${t('mfa.codes_file_title')}\n\n${text}\n`], { type: 'text/plain' }))
    const a = document.createElement('a')
    a.href = url
    a.download = 'aynihr-codigos-respaldo.txt'
    a.click()
    URL.revokeObjectURL(url)
  }

  return (
    <div>
      <div className="nota" role="status">{t('mfa.codes_once')}</div>
      <ul className="codigos" aria-label={t('mfa.codes_title')}>
        {codes.map((c) => <li key={c}><code>{c}</code></li>)}
      </ul>
      <div className="fila-acciones">
        <button type="button" className="btn btn-sec btn-sm" onClick={() => void navigator.clipboard?.writeText(text)}>{t('mfa.copy')}</button>
        <button type="button" className="btn btn-sec btn-sm" onClick={download}>{t('mfa.download')}</button>
        <button type="button" className="btn btn-primary btn-sm" onClick={onDone}>{doneLabel}</button>
      </div>
    </div>
  )
}
