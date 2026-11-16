'use client'

import { useEffect, useState } from 'react'

/** Evento que el navegador emite cuando la aplicación se puede instalar (no está en los tipos del DOM). */
type InstallPromptEvent = Event & { prompt: () => Promise<void>; userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }> }

/**
 * UI-9 · Registra el service worker que guarda el armazón. Solo en producción: en desarrollo la caché
 * escondería los cambios recientes.
 */
export function registerServiceWorker(env: string | undefined = process.env.NODE_ENV): boolean {
  if (env !== 'production' || typeof navigator === 'undefined' || !('serviceWorker' in navigator)) return false
  void navigator.serviceWorker.register('/sw.js', { scope: '/' }).catch(() => { /* sin service worker la app sigue funcionando en línea */ })
  return true
}

/** Estado de la conexión del dispositivo, para avisar en el armazón. */
export function useOnline(): boolean {
  const [online, setOnline] = useState(true)
  useEffect(() => {
    setOnline(navigator.onLine)
    const up = () => setOnline(true)
    const down = () => setOnline(false)
    window.addEventListener('online', up)
    window.addEventListener('offline', down)
    return () => { window.removeEventListener('online', up); window.removeEventListener('offline', down) }
  }, [])
  return online
}

/** Ofrece instalar la aplicación cuando el navegador lo permite; `install` es null si no corresponde. */
export function useInstallPrompt(): { install: (() => Promise<boolean>) | null } {
  const [event, setEvent] = useState<InstallPromptEvent | null>(null)
  useEffect(() => {
    const onPrompt = (e: Event) => { e.preventDefault(); setEvent(e as InstallPromptEvent) }
    const onInstalled = () => setEvent(null)
    window.addEventListener('beforeinstallprompt', onPrompt)
    window.addEventListener('appinstalled', onInstalled)
    return () => { window.removeEventListener('beforeinstallprompt', onPrompt); window.removeEventListener('appinstalled', onInstalled) }
  }, [])
  if (!event) return { install: null }
  return {
    install: async () => {
      await event.prompt()
      const choice = await event.userChoice
      setEvent(null)
      return choice.outcome === 'accepted'
    },
  }
}
