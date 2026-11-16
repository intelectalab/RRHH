'use client'

import { useEffect, useState } from 'react'
import { api } from '@/lib/api'
import { useT } from '@/lib/useT'
import { Modal } from '@/ui/components/Modal'
import { SkeletonBlock } from '@/ui/components/Skeleton'

/**
 * ESS-3 · Visor dentro de la aplicación: el archivo se pide a la API (que registra la apertura) y se muestra
 * en un marco; también se puede descargar. Los formatos que el navegador no muestra solo se descargan.
 */
export function FileViewer({ url, title, onClose }: { url: string; title: string; onClose: () => void }) {
  const { t } = useT()
  const [file, setFile] = useState<{ href: string; name: string; type: string } | null>(null)
  const [failed, setFailed] = useState(false)

  useEffect(() => {
    let href: string | null = null
    let cancelled = false
    api.download(url).then(({ blob, filename }) => {
      if (cancelled) return
      href = URL.createObjectURL(blob)
      setFile({ href, name: filename, type: blob.type })
    }).catch(() => { if (!cancelled) setFailed(true) })
    return () => {
      cancelled = true
      if (href) URL.revokeObjectURL(href)
    }
  }, [url])

  const viewable = file !== null && (file.type === 'application/pdf' || file.type.startsWith('image/'))

  return (
    <Modal title={title} onClose={onClose} size="l" footer={
      <>
        <button type="button" className="btn btn-sec" onClick={onClose}>{t('common.close')}</button>
        {file && <a className="btn btn-primary" href={file.href} download={file.name}>{t('portal.download')}</a>}
      </>
    }>
      {failed ? (
        <p className="text-secondary" role="alert">{t('common.load_error')}</p>
      ) : !file ? (
        <SkeletonBlock height={320} />
      ) : viewable ? (
        file.type.startsWith('image/')
          // eslint-disable-next-line @next/next/no-img-element
          ? <img src={file.href} alt={title} className="portal-viewer-img" />
          : <iframe src={file.href} title={title} className="portal-viewer" />
      ) : (
        <p className="text-secondary">{t('portal.viewer_download_only')}</p>
      )}
    </Modal>
  )
}
