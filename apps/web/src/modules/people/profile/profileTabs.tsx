import type { ReactNode } from 'react'
import { BankAccountsSection } from '../BankAccountsSection'
import { DependentsSection } from '../DependentsSection'
import { InsuranceSection } from '../InsuranceSection'
import { PensionHealthSection } from '../PensionHealthSection'
import { VacationsTab } from '@/modules/vacations/VacationsTab'
import { CompensationTab } from './CompensationTab'
import { ContractsTab } from './ContractsTab'
import { HistoryTab } from './HistoryTab'
import { InfoTab } from './InfoTab'
import { SummaryTab } from './SummaryTab'
import type { ProfileTabContext } from './types'

export type ProfileTabRender = (ctx: ProfileTabContext & { goTo: (id: string) => void }) => ReactNode

/**
 * COL-3 · Registro de pestañas de la ficha 360. Cada módulo agrega la suya con una línea: aparece solo si
 * el módulo está contratado y el usuario tiene el permiso (el backend valida lo mismo en cada endpoint).
 * El orden del arreglo es el orden en pantalla.
 */
export type ProfileTabDef = {
  id: string
  /** Clave de messages/*.json con el nombre de la pestaña. */
  label: string
  /** Código del módulo contratado (tenant_modules). */
  module: string
  /** Permiso que habilita la pestaña (null: basta ver la ficha). */
  permission: string | null
  render: ProfileTabRender
}

export const PROFILE_TABS: ProfileTabDef[] = [
  { id: 'summary', label: 'ficha.tab_summary', module: 'core', permission: null, render: (c) => <SummaryTab {...c} onHistory={() => c.goTo('history')} /> },
  { id: 'info', label: 'ficha.tab_info', module: 'core', permission: 'people.people.view', render: (c) => <InfoTab {...c} /> },
  { id: 'history', label: 'ficha.tab_history', module: 'core', permission: 'people.assignments.view', render: (c) => <HistoryTab {...c} /> },
  // CON-2 · CON-3: contrato, renovaciones y adendas.
  { id: 'contracts', label: 'ficha.tab_contracts', module: 'core', permission: 'contracts.contracts.view', render: (c) => <ContractsTab {...c} /> },
  // HIS-3: sueldo y componentes con vigencia (solo con el permiso de campo de sueldo).
  { id: 'compensation', label: 'ficha.tab_compensation', module: 'core', permission: 'people.employments.salary.view', render: (c) => <CompensationTab {...c} /> },
  // COL-6 · COL-7 · COL-8: datos que consume la planilla.
  { id: 'dependents', label: 'ficha.tab_dependents', module: 'core', permission: 'people.dependents.view', render: (c) => <DependentsSection employmentId={c.header.id} personId={c.header.person_id} onToast={c.toast} /> },
  { id: 'social_security', label: 'ficha.tab_social_security', module: 'core', permission: 'people.employments.view', render: (c) => (
    <>
      <PensionHealthSection employmentId={c.header.id} personId={c.header.person_id} onToast={c.toast} />
      <InsuranceSection employmentId={c.header.id} personId={c.header.person_id} legalEntityId={c.header.legal_entity_id} onToast={c.toast} />
    </>
  ) },
  { id: 'payment', label: 'ficha.tab_payment', module: 'core', permission: 'people.employments.view', render: (c) => <BankAccountsSection employmentId={c.header.id} personId={c.header.person_id} onToast={c.toast} /> },
  // VAC-2: saldo, periodos y movimientos de vacaciones (los calcula el servidor).
  { id: 'vacations', label: 'ficha.tab_vacations', module: 'time_off', permission: 'time.vacation_balances.view', render: (c) => <VacationsTab {...c} /> },
]

/** Pestañas visibles para la sesión: módulo contratado y permiso. */
export function visibleTabs(tabs: ProfileTabDef[], modules: string[], can: (p: string) => boolean): ProfileTabDef[] {
  return tabs.filter((tab) => modules.includes(tab.module) && (tab.permission === null || can(tab.permission)))
}
