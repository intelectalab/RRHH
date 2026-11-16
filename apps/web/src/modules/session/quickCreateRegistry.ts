'use client'

import { useEffect, useRef } from 'react'

/**
 * UI-8 · Creación rápida. Cada módulo registra aquí lo que se puede crear: la opción solo aparece si el
 * módulo está contratado y el usuario tiene el permiso de crear (el servidor lo vuelve a validar al guardar).
 * Cada opción abre el formulario real de creación: la pantalla dueña lo abre al recibir `?crear=<id>`.
 */
export type QuickCreateOption = {
  id: string
  key: string
  href: string
  module: string
  permission: string
  /** true: la ruta ya es el formulario (no hace falta abrir un panel). */
  page?: boolean
}

export const QUICK_CREATE: QuickCreateOption[] = [
  { id: 'employee', key: 'quick.employee', href: '/personas/colaboradores/nuevo', module: 'core', permission: 'people.employments.create', page: true },
  { id: 'position_request', key: 'quick.position_request', href: '/organizacion/solicitudes-de-posicion', module: 'core', permission: 'org.position_requests.create' },
  { id: 'vacation_request', key: 'quick.vacation_request', href: '/tiempo/vacaciones', module: 'time_off', permission: 'time.vacation_requests.create' },
  { id: 'absence', key: 'quick.absence', href: '/tiempo/ausencias', module: 'time_off', permission: 'time.absences.create' },
  { id: 'document_request', key: 'quick.document_request', href: '/documentos/solicitudes', module: 'core', permission: 'documents.documents.request' },
  { id: 'task', key: 'quick.task', href: '/tareas', module: 'base', permission: 'workflow.tasks.create' },
]

export const QUICK_CREATE_PARAM = 'crear'
export const QUICK_CREATE_EVENT = 'gestia:quick-create'

/** Opciones visibles para la sesión: módulo activo (no los de solo lectura) y permiso de crear. */
export function quickCreateOptions(modules: string[], can: (p: string) => boolean): QuickCreateOption[] {
  return QUICK_CREATE.filter((o) => modules.includes(o.module) && can(o.permission))
}

/** Dirección que abre el formulario de la opción (se puede compartir). */
export function quickCreateHref(o: QuickCreateOption): string {
  return o.page ? o.href : `${o.href}?${QUICK_CREATE_PARAM}=${o.id}`
}

/**
 * La pantalla dueña de una opción abre su formulario cuando llega con `?crear=<id>` o cuando, estando ya en
 * ella, el usuario elige la opción en «+ Crear». `ready` permite esperar a que carguen los catálogos.
 */
export function useQuickCreateRequest(id: string, open: () => void, ready = true): void {
  const openRef = useRef(open)
  openRef.current = open

  useEffect(() => {
    if (!ready) return
    try {
      const url = new URL(window.location.href)
      if (url.searchParams.get(QUICK_CREATE_PARAM) === id) {
        url.searchParams.delete(QUICK_CREATE_PARAM)
        window.history.replaceState(window.history.state, '', `${url.pathname}${url.search}${url.hash}`)
        openRef.current()
      }
    } catch { /* sin URL utilizable: no se abre nada */ }
    const onRequest = (e: Event) => {
      if ((e as CustomEvent<string>).detail === id) openRef.current()
    }
    window.addEventListener(QUICK_CREATE_EVENT, onRequest)
    return () => window.removeEventListener(QUICK_CREATE_EVENT, onRequest)
  }, [id, ready])
}
