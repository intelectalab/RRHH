'use client'

import { useId, useRef, useState, type DragEvent } from 'react'
import { useT } from '@/lib/useT'
import { formatBytes, validateFiles, type FileRejection } from '../files'
import { Icon } from './Icon'

/**
 * UI-3 · Cargador de archivos: botón para elegir o arrastrar y soltar, validación inmediata de tipo,
 * tamaño y cantidad, y lista de lo elegido con opción de quitar. El componente no sube nada por sí
 * mismo: entrega los archivos aceptados a `onChange` y la pantalla los envía al servicio de archivos,
 * que vuelve a validar (PLT-7). `uploading` muestra el estado de carga.
 */
export function FileUploader({ label, files, onChange, accept, maxBytes, maxFiles, disabled = false, uploading = false, error, hint }: {
  label: string
  files: File[]
  onChange: (files: File[]) => void
  accept?: string[]
  maxBytes?: number
  maxFiles?: number
  disabled?: boolean
  uploading?: boolean
  error?: string
  hint?: string
}) {
  const { t, locale } = useT()
  const id = useId()
  const inputRef = useRef<HTMLInputElement>(null)
  const [over, setOver] = useState(false)
  const [rejected, setRejected] = useState<FileRejection[]>([])
  const off = disabled || uploading

  function add(list: FileList | null) {
    if (!list) return
    const result = validateFiles(Array.from(list), { accept, maxBytes, maxFiles }, files.length)
    setRejected(result.rejected)
    if (result.accepted.length > 0) onChange([...files, ...result.accepted])
  }

  function onDrop(e: DragEvent<HTMLDivElement>) {
    e.preventDefault()
    setOver(false)
    if (!off) add(e.dataTransfer.files)
  }

  const rules = [
    accept && accept.length > 0 ? t('ui.files_types', { types: accept.join(', ') }) : '',
    maxBytes !== undefined ? t('ui.files_max_size', { size: formatBytes(maxBytes, locale) }) : '',
    maxFiles !== undefined ? t('ui.files_max_count', { n: maxFiles }) : '',
  ].filter(Boolean).join(' · ')

  return (
    <div className="subir">
      <span className="form-label" id={`${id}-l`}>{label}</span>
      <div
        className={`subir-zona${over ? ' sobre' : ''}${off ? ' off' : ''}${error ? ' is-invalid' : ''}`}
        onDragOver={(e) => { e.preventDefault(); if (!off) setOver(true) }}
        onDragLeave={() => setOver(false)}
        onDrop={onDrop}
      >
        <Icon name="upload" size={22} />
        <span>{t('ui.files_drop')}</span>
        <button type="button" className="btn btn-sec btn-sm" onClick={() => inputRef.current?.click()} disabled={off} aria-describedby={`${id}-l ${id}-r`} aria-busy={uploading || undefined}>
          {uploading && <span className="spin" aria-hidden="true" />}
          {t(uploading ? 'ui.files_uploading' : 'ui.files_choose')}
        </button>
        {rules && <span id={`${id}-r`} className="small text-secondary">{rules}</span>}
        <input ref={inputRef} type="file" hidden multiple={maxFiles === undefined || maxFiles > 1} accept={accept?.join(',')} onChange={(e) => { add(e.target.files); e.target.value = '' }} tabIndex={-1} />
      </div>
      {hint && !error && <div className="small text-secondary mt-2">{hint}</div>}
      {error && <div className="invalid-feedback">{error}</div>}
      {rejected.length > 0 && (
        <ul className="subir-rechazos" role="alert">
          {rejected.map((r) => <li key={`${r.name}-${r.reason}`}>{t(`ui.files_rejected_${r.reason}`, { name: r.name })}</li>)}
        </ul>
      )}
      {files.length > 0 && (
        <ul className="subir-lista" aria-label={t('ui.files_selected')}>
          {files.map((f, i) => (
            <li key={`${f.name}-${i}`}>
              <Icon name="file" size={16} />
              <span className="min-w-0"><b>{f.name}</b><span className="sub2">{formatBytes(f.size, locale)}</span></span>
              <button type="button" className="ibtn" disabled={off} onClick={() => onChange(files.filter((_, j) => j !== i))} aria-label={t('ui.files_remove', { name: f.name })}>
                <Icon name="trash" size={16} />
              </button>
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}
