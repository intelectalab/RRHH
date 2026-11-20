'use client'

import Link from 'next/link'
import { useEffect, useMemo, useState } from 'react'
import { PRODUCT_COLOR, employeeLimit, suggestPlan, type Catalog, type Quote } from '@/lib/catalog'
import { fill, href, type Content, type Locale } from '@/lib/i18n'
import { formatMoney } from '@/lib/money'

type Props = { lang: Locale; catalog: Catalog; t: Content }

/**
 * WEB-4 · Precios: ciclo mensual o anual, productos individuales, buscador de plan y selector «Añadir productos» con
 * subtotal. El subtotal, el impuesto y los requisitos los calcula la API (/public/quote); el navegador solo muestra.
 */
export function PricingClient({ lang, catalog, t }: Props) {
  const p = t.pricing
  const [cycle, setCycle] = useState<'monthly' | 'annual'>('monthly')
  const [tab, setTab] = useState<'plans' | 'products'>('plans')
  const [plan, setPlan] = useState<string>(catalog.plans[0]?.code ?? '')
  const [employees, setEmployees] = useState(10)
  const [chosen, setChosen] = useState<string[]>([])
  const [finderSize, setFinderSize] = useState(10)
  const [finderPayroll, setFinderPayroll] = useState(true)
  const [quote, setQuote] = useState<Quote | null>(null)
  const [state, setState] = useState<'idle' | 'loading' | 'error'>('idle')

  const published = useMemo(() => new Map(catalog.modules.map((m) => [m.code, m.published])), [catalog])
  const products = catalog.products.filter((x) => !x.is_suite)

  useEffect(() => {
    const wanted = new URLSearchParams(window.location.search).get('plan')
    if (wanted && catalog.plans.some((x) => x.code === wanted)) setPlan(wanted)
  }, [catalog])

  useEffect(() => {
    if (plan === '' || employees < 1) return
    const query = new URLSearchParams({ plan, employees: String(employees), cycle })
    if (chosen.length > 0) query.set('addons', chosen.join(','))
    const controller = new AbortController()
    setState('loading')
    fetch(`/api/quote?${query.toString()}`, { signal: controller.signal, headers: { 'Accept-Language': lang } })
      .then(async (res) => {
        if (!res.ok) throw new Error(String(res.status))
        const body = (await res.json()) as { data: Quote }
        setQuote(body.data)
        setState('idle')
      })
      .catch((e: unknown) => {
        if ((e as Error).name !== 'AbortError') setState('error')
      })
    return () => controller.abort()
  }, [plan, employees, cycle, chosen, lang])

  const suggestion = suggestPlan(catalog, finderSize, finderPayroll ? ['payroll'] : [])
  const selectedPlan = catalog.plans.find((x) => x.code === plan)
  const toggle = (code: string) => setChosen((prev) => (prev.includes(code) ? prev.filter((c) => c !== code) : [...prev, code]))
  const addonSoon = (modules: string[]) => modules.some((m) => !published.get(m))
  const signup = new URLSearchParams({ plan, ciclo: cycle, colaboradores: String(employees) })
  if (quote) {
    const paid = quote.lines.filter((l) => l.kind === 'addon').map((l) => l.code)
    if (paid.length > 0) signup.set('complementos', paid.join(','))
    if (quote.waitlist.length > 0) signup.set('aviso', quote.waitlist.join(','))
  }

  return (
    <>
      <section className="sec-sm" id="planes">
        <div className="wrap">
          <div className="seg" role="group" aria-label={p.cycle_label}>
            <button type="button" aria-pressed={cycle === 'monthly'} onClick={() => setCycle('monthly')}>{p.monthly}</button>
            <button type="button" aria-pressed={cycle === 'annual'} onClick={() => setCycle('annual')}>{p.annual}</button>
          </div>
          <div className="tabs" role="tablist">
            <a role="tab" href="#planes" aria-selected={tab === 'plans'} className={tab === 'plans' ? 'on' : ''} onClick={(e) => { e.preventDefault(); setTab('plans') }}>{p.tab_plans}</a>
            <a role="tab" href="#planes" aria-selected={tab === 'products'} className={tab === 'products' ? 'on' : ''} onClick={(e) => { e.preventDefault(); setTab('products') }}>{p.tab_products}</a>
          </div>
          {tab === 'plans' ? (
            <div className="plans">
              {catalog.plans.length === 0 && <p className="note">{p.no_plans}</p>}
              {catalog.plans.map((x) => {
                const limit = employeeLimit(x)
                const priced = x.price !== null && x.billing_cycle === cycle
                return (
                  <div key={x.code} className={`plan${x.code === plan ? ' sel' : ''}`} id={`plan-${x.code}`}>
                    <h3>{x.name}</h3>
                    {x.description && <div className="for">{x.description}</div>}
                    <div className="price">
                      {priced && x.price ? formatMoney(x.price, lang) : t.price.on_request}{' '}
                      {priced && <small>{cycle === 'annual' ? t.price.per_year : t.price.per_month}</small>}
                    </div>
                    {x.price && !priced && <div className="lim">{p.cycle_unavailable}</div>}
                    {limit && <div className="lim">{limit.unlimited || limit.value === null ? p.limit_unlimited : fill(p.limit_up_to, { n: limit.value })}</div>}
                    <div className="chips-s">
                      {products.filter((pr) => pr.modules.some((m) => x.modules.includes(m.code))).map((pr) => (
                        <Link key={pr.code} className="sigchip" href={href(lang, `/${pr.slug}`)} style={{ ['--c' as string]: PRODUCT_COLOR[pr.acronym] }}>{pr.acronym}</Link>
                      ))}
                    </div>
                    <a className={x.code === plan ? 'btn btn-primary' : 'btn btn-line'} href="#armar" onClick={() => setPlan(x.code)}>
                      {x.code === plan ? p.selected : fill(p.choose_plan, { plan: x.name })}
                    </a>
                  </div>
                )
              })}
            </div>
          ) : (
            <div className="box">
              <p className="lead">{p.products_lead}</p>
              {catalog.addons.map((a) => (
                <div key={a.code} className={`addon${addonSoon(a.modules) ? ' dis' : ''}`}>
                  <span><b>{a.name}</b></span>
                  <span>
                    {addonSoon(a.modules) ? t.status.coming_soon : a.price ? formatMoney(a.price, lang) : t.price.on_request}{' '}
                    {!addonSoon(a.modules) && a.price && <small>{p.per_employee_month}</small>}
                  </span>
                </div>
              ))}
            </div>
          )}
          <p className="note">{t.price.tax_note}</p>
        </div>
      </section>

      <section className="alt sec-sm" id="elegir">
        <div className="wrap two">
          <div>
            <h2 className="h2">{p.finder_title}</h2>
            <p className="lead">{p.finder_lead}</p>
          </div>
          <div className="box form finder picker">
            <label className="f">
              {p.finder_employees}
              <input className="in" type="number" min={1} value={finderSize} onChange={(e) => setFinderSize(Math.max(1, Number(e.target.value) || 1))} />
            </label>
            <label className="f">
              {p.finder_payroll}
              <select className="in" value={finderPayroll ? '1' : '0'} onChange={(e) => setFinderPayroll(e.target.value === '1')}>
                <option value="1">{p.finder_yes}</option>
                <option value="0">{p.finder_no}</option>
              </select>
            </label>
            <p className="finder-r" role="status">{suggestion ? fill(p.finder_result, { plan: suggestion.name }) : p.finder_none}</p>
            {suggestion && <a className="btn btn-line" href="#armar" onClick={() => { setPlan(suggestion.code); setEmployees(finderSize) }}>{fill(p.choose_plan, { plan: suggestion.name })}</a>}
          </div>
        </div>
      </section>

      <section className="sec-sm" id="armar">
        <div className="wrap two">
          <div>
            <h2 className="h2">{p.addons_title}</h2>
            <p className="lead">{p.addons_lead}</p>
            <div className="picker">
              <label>
                {p.tab_plans}
                <select value={plan} onChange={(e) => setPlan(e.target.value)}>
                  {catalog.plans.map((x) => <option key={x.code} value={x.code}>{x.name}</option>)}
                </select>
              </label>
              <label>
                {p.employees}
                <input type="number" min={1} value={employees} onChange={(e) => setEmployees(Math.max(1, Number(e.target.value) || 1))} />
              </label>
              {catalog.addons.filter((a) => !selectedPlan || !a.modules.every((m) => selectedPlan.modules.includes(m))).map((a) => (
                <div key={a.code} className="row">
                  {addonSoon(a.modules) ? (
                    <>
                      <span>{a.name} {' · '} {t.status.coming_soon}</span>
                      <button type="button" className="btn btn-line" aria-pressed={chosen.includes(a.code)} onClick={() => toggle(a.code)}>
                        {chosen.includes(a.code) ? p.notify_on : p.notify_me}
                      </button>
                    </>
                  ) : (
                    <label>
                      <input type="checkbox" checked={chosen.includes(a.code)} onChange={() => toggle(a.code)} />
                      {a.name}
                      {a.price && <small>{formatMoney(a.price, lang)} {p.per_employee_month}</small>}
                    </label>
                  )}
                </div>
              ))}
            </div>
          </div>
          <div className="sum" aria-live="polite">
            {state === 'loading' && <span>{p.calculating}</span>}
            {state === 'error' && <span>{p.quote_error}</span>}
            {quote && state === 'idle' && (
              <>
                {quote.lines.map((l) => (
                  <div key={`${l.kind}-${l.code}`} className="ln"><span>{l.name}</span><span>{l.amount ? formatMoney(l.amount, lang) : t.price.on_request}</span></div>
                ))}
                <div className="ln"><b>{p.subtotal}</b><b>{quote.subtotal ? formatMoney(quote.subtotal, lang) : t.price.on_request}</b></div>
                <div className="ln">
                  <span>{quote.tax.rate_pct ? fill(p.tax, { rate: quote.tax.rate_pct }) : p.tax_pending}</span>
                  <span>{quote.tax.amount ? formatMoney(quote.tax.amount, lang) : ''}</span>
                </div>
                {quote.total && <div className="ln tot"><span>{p.total}</span><span>{formatMoney(quote.total, lang)}</span></div>}
                {quote.notices.some((n) => n.code !== 'tax_pending') && <ul className="notices">{quote.notices.filter((n) => n.code !== 'tax_pending').map((n, i) => <li key={`${n.code}-${i}`}>{n.message}</li>)}</ul>}
              </>
            )}
            {selectedPlan && <Link className="btn btn-primary" href={`${href(lang, '/registro')}?${signup.toString()}`}>{fill(p.continue, { plan: selectedPlan.name })}</Link>}
          </div>
        </div>
      </section>
    </>
  )
}
