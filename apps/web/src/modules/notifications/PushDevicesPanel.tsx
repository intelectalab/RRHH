'use client'

import { useCallback, useEffect, useState } from 'react'
import { api, ApiRequestError } from '@/lib/api'
import { formatDate } from '@/lib/format'
import { useT } from '@/lib/useT'
import { pushSupported, subscriptionBody, vapidKeyToBytes } from './push'

type Device = { id: string; platform: string; label: string | null; last_seen_at: string | null; created_at: string }

/**
 * NOT-4 · Aceptar o rechazar push desde las preferencias: activar este dispositivo pide permiso al
 * navegador y lo registra; quitarlo es la baja. Lo que llega por push solo invita a abrir la aplicación.
 */
export function PushDevicesPanel({ onChange, toast }: { onChange: () => void; toast: (text: string, kind?: 'ok' | 'error') => void }) {
  const { t, locale } = useT()
  const [key, setKey] = useState<string | null>(null)
  const [devices, setDevices] = useState<Device[]>([])
  const [busy, setBusy] = useState(false)

  const load = useCallback(async () => {
    try {
      const [cfg, list] = await Promise.all([
        api.get<{ data: { available: boolean; public_key: string | null } }>('/push/config'),
        api.get<{ data: Device[] }>('/push-devices'),
      ])
      setKey(cfg.data.public_key)
      setDevices(list.data)
    } catch {
      setDevices([])
    }
  }, [])
  useEffect(() => { void load() }, [load])

  const fail = (e: unknown) => toast(e instanceof ApiRequestError ? e.message : t('push.error'), 'error')

  async function enable() {
    if (!key) return
    setBusy(true)
    try {
      if ((await Notification.requestPermission()) !== 'granted') {
        toast(t('push.denied'), 'error')
        return
      }
      const reg = await navigator.serviceWorker.ready
      const sub = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: vapidKeyToBytes(key) })
      const body = subscriptionBody(sub.toJSON(), navigator.userAgent.includes('Mobile') ? t('push.this_phone') : t('push.this_browser'))
      if (!body) throw new Error('subscription')
      await api.post('/push-devices', body)
      toast(t('push.enabled'))
      await load()
      onChange()
    } catch (e) {
      fail(e)
    } finally {
      setBusy(false)
    }
  }

  async function remove(id: string) {
    try {
      await api.post(`/push-devices/${id}/revoke`)
      toast(t('push.removed'))
      await load()
      onChange()
    } catch (e) {
      fail(e)
    }
  }

  const supported = pushSupported()

  return (
    <section className="bloque-plano">
      <div className="cab"><h2>{t('push.title')}</h2></div>
      <p className="sub2">{t('push.text')}</p>
      {!supported || !key ? (
        <p className="sub2">{t(supported ? 'push.unavailable' : 'push.unsupported')}</p>
      ) : (
        <button type="button" className="btn btn-sec btn-sm" disabled={busy} onClick={() => void enable()}>{t('push.enable')}</button>
      )}
      {devices.length > 0 && (
        <ul className="lista-simple" aria-label={t('push.devices')}>
          {devices.map((d) => (
            <li key={d.id} className="d-flex justify-content-between align-items-center gap-2">
              <span>{d.label ?? t('push.this_browser')}<span className="sub2"> · {t('push.since', { date: formatDate(locale, d.created_at.slice(0, 10)) })}</span></span>
              <button type="button" className="btn btn-ter btn-sm" onClick={() => void remove(d.id)}>{t('push.remove')}</button>
            </li>
          ))}
        </ul>
      )}
    </section>
  )
}
