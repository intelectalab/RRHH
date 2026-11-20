/**
 * NOT-4 · Utilidades de push web. La clave VAPID llega en base64url y el navegador la pide en bytes.
 */
export function vapidKeyToBytes(base64url: string): Uint8Array<ArrayBuffer> {
  const padded = (base64url + '='.repeat((4 - (base64url.length % 4)) % 4)).replace(/-/g, '+').replace(/_/g, '/')
  const raw = atob(padded)
  const bytes = new Uint8Array(new ArrayBuffer(raw.length))
  for (let i = 0; i < raw.length; i++) bytes[i] = raw.charCodeAt(i)
  return bytes
}

/** ¿Este navegador puede recibir push? (service worker + PushManager + Notification). */
export function pushSupported(): boolean {
  return typeof window !== 'undefined' && 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window
}

/** Suscripción en el formato que espera POST /push-devices. */
export function subscriptionBody(sub: PushSubscriptionJSON, label: string): { subscription: { endpoint: string; keys: { p256dh: string; auth: string } }; label: string } | null {
  if (!sub.endpoint || !sub.keys?.p256dh || !sub.keys?.auth) return null
  return { subscription: { endpoint: sub.endpoint, keys: { p256dh: sub.keys.p256dh, auth: sub.keys.auth } }, label }
}
