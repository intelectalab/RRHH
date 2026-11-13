'use client'

import { useCallback, useEffect, useState } from 'react'
import { api } from '@/lib/api'
import { formErrors } from '@/lib/errors'
import { formatDate, localToday } from '@/lib/format'
import { useT } from '@/lib/useT'
import { useSession } from '@/modules/session/SessionProvider'
import { Card } from '@/ui/components/Card'
import { Modal } from '@/ui/components/Modal'
import { Notice } from '@/ui/components/Notice'
import type { PeopleSectionProps } from './sectionProps'

type Pension = {
  id: string
  pension_system: 'afp' | 'onp' | 'none' | 'other'
  pension_fund_code: string | null
  pension_fund_name: string | null
  commission_scheme: 'flow' | 'mixed' | null
  affiliate_code?: string
  affiliate_code_masked: string | null
  valid_from: string
  valid_to: string | null
}
type Health = { id: string; health_scheme: string; health_provider_id: string | null; health_provider_name: string | null; health_plan_code: string | null; valid_from: string; valid_to: string | null }
type Fund = { code: string; name: string; kind: 'private' | 'public' }
type Provider = { id: string; name: string; kind: string }

type PensionForm = { pension_system: string; pension_fund_code: string; commission_scheme: string; affiliate_code: string; valid_from: string; reason: string }
type HealthForm = { health_scheme: string; health_provider_id: string; health_plan_code: string; valid_from: string; reason: string }

/**
 * COL-7 · Régimen pensionario y de salud con vigencia: muestra lo vigente y su historia; un cambio se
 * registra desde una fecha efectiva (no se edita lo anterior). Las tasas no viven aquí: las calcula planilla.
 */
export function PensionHealthSection({ employmentId, onToast }: PeopleSectionProps) {
  const { t, locale } = useT()
  const { can } = useSession()
  const base = `/employments/${employmentId}`
  const [pension, setPension] = useState<Pension[] | null>(null)
  const [health, setHealth] = useState<Health[] | null>(null)
  const [funds, setFunds] = useState<Fund[]>([])
  const [providers, setProviders] = useState<Provider[]>([])
  const [failed, setFailed] = useState(false)
  const [pForm, setPForm] = useState<PensionForm | null>(null)
  const [hForm, setHForm] = useState<HealthForm | null>(null)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [general, setGeneral] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  const load = useCallback(async () => {
    try {
      const [p, h] = await Promise.all([
        api.get<{ data: Pension[] }>(`${base}/pension-affiliations`),
        api.get<{ data: Health[] }>(`${base}/health-regimes`),
      ])
      setPension(p.data)
      setHealth(h.data)
      setFailed(false)
    } catch {
      setFailed(true)
    }
  }, [base])

  useEffect(() => { void load() }, [load])

  const date = (v: string | null) => (v ? formatDate(locale, v) : '—')
  const editable = can('people.employments.update')

  async function openPension() {
    setErrors({}); setGeneral(null)
    if (funds.length === 0) {
      try { setFunds((await api.get<{ data: Fund[] }>('/pension-funds')).data) } catch { /* el formulario lo indica al guardar */ }
    }
    setPForm({ pension_system: 'afp', pension_fund_code: '', commission_scheme: 'flow', affiliate_code: '', valid_from: localToday(), reason: '' })
  }

  async function openHealth() {
    setErrors({}); setGeneral(null)
    if (providers.length === 0) {
      try { setProviders((await api.get<{ data: Provider[] }>('/health-providers')).data) } catch { /* sin EPS registradas */ }
    }
    setHForm({ health_scheme: 'essalud', health_provider_id: '', health_plan_code: '', valid_from: localToday(), reason: '' })
  }

  async function submit(path: string, body: Record<string, unknown>, done: () => void) {
    setBusy(true); setErrors({}); setGeneral(null)
    try {
      await api.post(path, Object.fromEntries(Object.entries(body).filter(([, v]) => v !== '')))
      done()
      onToast(t('ssec.saved'))
      await load()
    } catch (e) {
      const f = formErrors(e, t)
      setErrors(f.fields); setGeneral(f.general)
    } finally {
      setBusy(false)
    }
  }

  const err = (k: string) => errors[k] && <div className="invalid-feedback d-block" role="alert">{errors[k]}</div>
  const current = pension?.[0]
  const currentHealth = health?.[0]

  return (
    <Card title={t('ssec.title')} context={t('ssec.context')}>
      {failed && <Notice tone="error">{t('common.load_error')}</Notice>}
      <div className="sec-dos">
        <section aria-labelledby="ssec-pension">
          <div className="sec-titulo">
            <h3 id="ssec-pension">{t('ssec.pension')}</h3>
            {editable && <button type="button" className="btn btn-ter btn-sm" onClick={() => void openPension()}>{t('ssec.change')}</button>}
          </div>
          {pension === null && !failed && <span className="esq" style={{ width: 180 }} />}
          {pension !== null && !current && <p className="text-secondary">{t('ssec.pension_empty')}</p>}
          {current && (
            <dl className="ficha">
              <dt>{t('ssec.f_system')}</dt><dd>{t(`ssec.system_${current.pension_system}`)}</dd>
              {current.pension_fund_name && <><dt>{t('ssec.f_fund')}</dt><dd>{current.pension_fund_name}</dd></>}
              {current.commission_scheme && <><dt>{t('ssec.f_scheme')}</dt><dd>{t(`ssec.scheme_${current.commission_scheme}`)}</dd></>}
              {current.affiliate_code_masked && <><dt>{t('ssec.f_affiliate')}</dt><dd>{current.affiliate_code ?? current.affiliate_code_masked}</dd></>}
              <dt>{t('ssec.f_since')}</dt><dd>{date(current.valid_from)}</dd>
            </dl>
          )}
          {pension !== null && pension.length > 1 && (
            <details className="mt-2">
              <summary className="small">{t('ssec.history', { n: pension.length - 1 })}</summary>
              <ul className="sec-historia">
                {pension.slice(1).map((p) => (
                  <li key={p.id} className="small">{t(`ssec.system_${p.pension_system}`)}{p.pension_fund_name ? ` · ${p.pension_fund_name}` : ''}{p.commission_scheme ? ` · ${t(`ssec.scheme_${p.commission_scheme}`)}` : ''} · {date(p.valid_from)} → {date(p.valid_to)}</li>
                ))}
              </ul>
            </details>
          )}
        </section>
        <section aria-labelledby="ssec-health">
          <div className="sec-titulo">
            <h3 id="ssec-health">{t('ssec.health')}</h3>
            {editable && <button type="button" className="btn btn-ter btn-sm" onClick={() => void openHealth()}>{t('ssec.change')}</button>}
          </div>
          {health !== null && !currentHealth && <p className="text-secondary">{t('ssec.health_empty')}</p>}
          {currentHealth && (
            <dl className="ficha">
              <dt>{t('ssec.f_health')}</dt><dd>{t(`ssec.health_${currentHealth.health_scheme}`)}</dd>
              {currentHealth.health_provider_name && <><dt>{t('ssec.f_provider')}</dt><dd>{currentHealth.health_provider_name}</dd></>}
              {currentHealth.health_plan_code && <><dt>{t('ssec.f_plan')}</dt><dd>{currentHealth.health_plan_code}</dd></>}
              <dt>{t('ssec.f_since')}</dt><dd>{date(currentHealth.valid_from)}</dd>
            </dl>
          )}
          {health !== null && health.length > 1 && (
            <details className="mt-2">
              <summary className="small">{t('ssec.history', { n: health.length - 1 })}</summary>
              <ul className="sec-historia">
                {health.slice(1).map((h) => (
                  <li key={h.id} className="small">{t(`ssec.health_${h.health_scheme}`)}{h.health_provider_name ? ` · ${h.health_provider_name}` : ''} · {date(h.valid_from)} → {date(h.valid_to)}</li>
                ))}
              </ul>
            </details>
          )}
        </section>
      </div>

      {pForm && (
        <Modal
          title={t('ssec.pension_title')}
          onClose={() => setPForm(null)}
          footer={(
            <>
              <button type="button" className="btn btn-sec" onClick={() => setPForm(null)}>{t('org.cancel')}</button>
              <button type="button" className="btn btn-primary" disabled={busy} aria-busy={busy}
                onClick={() => void submit(`${base}/pension-affiliations`, { ...pForm, commission_scheme: pForm.pension_system === 'afp' ? pForm.commission_scheme : '', pension_fund_code: pForm.pension_system === 'afp' ? pForm.pension_fund_code : '' }, () => setPForm(null))}>{t('ssec.save')}</button>
            </>
          )}
        >
          {general && <Notice tone="error">{general}</Notice>}
          <label className="form-label" htmlFor="pf-system">{t('ssec.f_system')}</label>
          <select id="pf-system" className="form-select" value={pForm.pension_system} onChange={(e) => setPForm({ ...pForm, pension_system: e.target.value })}>
            {(['afp', 'onp', 'none', 'other'] as const).map((s) => <option key={s} value={s}>{t(`ssec.system_${s}`)}</option>)}
          </select>
          {err('pension_system')}
          {pForm.pension_system === 'afp' && (
            <>
              <label className="form-label mt-3" htmlFor="pf-fund">{t('ssec.f_fund')}</label>
              <select id="pf-fund" className="form-select" value={pForm.pension_fund_code} onChange={(e) => setPForm({ ...pForm, pension_fund_code: e.target.value })}>
                <option value="">{t('ssec.choose')}</option>
                {funds.filter((f) => f.kind === 'private').map((f) => <option key={f.code} value={f.code}>{f.name}</option>)}
              </select>
              {err('pension_fund_code')}
              <label className="form-label mt-3" htmlFor="pf-scheme">{t('ssec.f_scheme')}</label>
              <select id="pf-scheme" className="form-select" value={pForm.commission_scheme} onChange={(e) => setPForm({ ...pForm, commission_scheme: e.target.value })}>
                <option value="flow">{t('ssec.scheme_flow')}</option>
                <option value="mixed">{t('ssec.scheme_mixed')}</option>
              </select>
              {err('commission_scheme')}
              {can('people.people.personal_data.edit') && (
                <>
                  <label className="form-label mt-3" htmlFor="pf-cuspp">{t('ssec.f_affiliate')}</label>
                  <input id="pf-cuspp" className="form-control" value={pForm.affiliate_code} onChange={(e) => setPForm({ ...pForm, affiliate_code: e.target.value })} />
                  {err('affiliate_code')}
                </>
              )}
            </>
          )}
          <label className="form-label mt-3" htmlFor="pf-from">{t('ssec.f_effective')}</label>
          <input id="pf-from" type="date" className="form-control" value={pForm.valid_from} onChange={(e) => setPForm({ ...pForm, valid_from: e.target.value })} />
          {err('valid_from')}
          <p className="small text-secondary mt-2">{t('ssec.effective_hint')}</p>
        </Modal>
      )}

      {hForm && (
        <Modal
          title={t('ssec.health_title')}
          onClose={() => setHForm(null)}
          footer={(
            <>
              <button type="button" className="btn btn-sec" onClick={() => setHForm(null)}>{t('org.cancel')}</button>
              <button type="button" className="btn btn-primary" disabled={busy} aria-busy={busy}
                onClick={() => void submit(`${base}/health-regimes`, hForm.health_scheme === 'eps' ? hForm : { ...hForm, health_provider_id: '', health_plan_code: '' }, () => setHForm(null))}>{t('ssec.save')}</button>
            </>
          )}
        >
          {general && <Notice tone="error">{general}</Notice>}
          <label className="form-label" htmlFor="hf-scheme">{t('ssec.f_health')}</label>
          <select id="hf-scheme" className="form-select" value={hForm.health_scheme} onChange={(e) => setHForm({ ...hForm, health_scheme: e.target.value })}>
            {(['essalud', 'eps', 'sis', 'other'] as const).map((s) => <option key={s} value={s}>{t(`ssec.health_${s}`)}</option>)}
          </select>
          {hForm.health_scheme === 'eps' && (
            <>
              <label className="form-label mt-3" htmlFor="hf-provider">{t('ssec.f_provider')}</label>
              <select id="hf-provider" className="form-select" value={hForm.health_provider_id} onChange={(e) => setHForm({ ...hForm, health_provider_id: e.target.value })}>
                <option value="">{t('ssec.choose')}</option>
                {providers.filter((p) => p.kind === 'eps').map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
              </select>
              {err('health_provider_id')}
              <label className="form-label mt-3" htmlFor="hf-plan">{t('ssec.f_plan')}</label>
              <input id="hf-plan" className="form-control" value={hForm.health_plan_code} onChange={(e) => setHForm({ ...hForm, health_plan_code: e.target.value })} />
              {err('health_plan_code')}
            </>
          )}
          <label className="form-label mt-3" htmlFor="hf-from">{t('ssec.f_effective')}</label>
          <input id="hf-from" type="date" className="form-control" value={hForm.valid_from} onChange={(e) => setHForm({ ...hForm, valid_from: e.target.value })} />
          {err('valid_from')}
        </Modal>
      )}
    </Card>
  )
}
