'use client'

import { useId, useState, type ReactNode } from 'react'
import { donutArcs, heatLevel, linePath, niceMax, type Series } from '../chartMath'

/**
 * UI-12 · Gráficos del sistema en SVG, sin librerías: barras, líneas, dona y mapa de calor. Usan el tema
 * (--chart-*, --heat-*), así se leen en claro y oscuro; los números van en tinta de texto, nunca en el color
 * de la serie. Todos tienen leyenda o etiquetas directas y una alternativa en tabla («Ver como tabla») para
 * lectores de pantalla, daltonismo e impresión. El detalle de cada marca sale al pasar el puntero o al
 * enfocarla con el teclado.
 */

export type { Series }

type Common = {
  title: string
  /** Texto de la tabla alternativa: encabezado de la primera columna y etiqueta del botón. */
  labels: { category: string; showTable: string; hideTable: string }
  format?: (n: number) => string
}

const color = (i: number) => `var(--chart-${(i % 6) + 1})`
const fmt = (f: Common['format'], n: number) => (f ? f(n) : new Intl.NumberFormat().format(n))

function Frame({ title, labels, table, legend, children }: { title: string; labels: Common['labels']; table: ReactNode; legend?: ReactNode; children: ReactNode }) {
  const [showTable, setShowTable] = useState(false)
  const id = useId()
  return (
    <figure className="chart" aria-labelledby={`${id}-t`}>
      <figcaption id={`${id}-t`} className="chart-t">
        <span>{title}</span>
        <button type="button" className="btn btn-ter btn-sm" aria-expanded={showTable} aria-controls={`${id}-tb`} onClick={() => setShowTable((v) => !v)}>
          {showTable ? labels.hideTable : labels.showTable}
        </button>
      </figcaption>
      {legend}
      <div aria-hidden={showTable ? 'true' : undefined} hidden={showTable}>{children}</div>
      <div id={`${id}-tb`} hidden={!showTable} className="tabla-wrap">{table}</div>
    </figure>
  )
}

function Legend({ series }: { series: Series[] }) {
  if (series.length < 2) return null
  return (
    <ul className="chart-leyenda">
      {series.map((s, i) => <li key={s.name}><span className="chart-muestra" style={{ background: color(i) }} aria-hidden="true" />{s.name}</li>)}
    </ul>
  )
}

function SeriesTable({ categories, series, labels, format }: { categories: string[]; series: Series[]; labels: Common['labels']; format?: Common['format'] }) {
  return (
    <table className="table">
      <thead><tr><th scope="col">{labels.category}</th>{series.map((s) => <th key={s.name} scope="col">{s.name}</th>)}</tr></thead>
      <tbody>
        {categories.map((c, ci) => (
          <tr key={c}><th scope="row">{c}</th>{series.map((s) => <td key={s.name}>{fmt(format, s.values[ci] ?? 0)}</td>)}</tr>
        ))}
      </tbody>
    </table>
  )
}

const W = 560
const H = 220
const PAD = { l: 44, r: 12, t: 12, b: 36 }

function Axis({ max, format }: { max: number; format?: Common['format'] }) {
  const ticks = [0, 0.25, 0.5, 0.75, 1].map((f) => f * max)
  const y = (v: number) => PAD.t + (H - PAD.t - PAD.b) * (1 - v / max)
  return (
    <g className="chart-eje">
      {ticks.map((v) => (
        <g key={v}>
          <line x1={PAD.l} x2={W - PAD.r} y1={y(v)} y2={y(v)} stroke="var(--chart-grid)" />
          <text x={PAD.l - 6} y={y(v)} textAnchor="end" dominantBaseline="middle">{fmt(format, Math.round(v))}</text>
        </g>
      ))}
    </g>
  )
}

/** Barras agrupadas por categoría (una serie por color). */
export function BarChart({ title, categories, series, labels, format }: Common & { categories: string[]; series: Series[] }) {
  const max = niceMax(series.flatMap((s) => s.values))
  const band = (W - PAD.l - PAD.r) / Math.max(1, categories.length)
  const gap = 2
  const bw = Math.max(4, Math.min(28, (band * 0.7) / Math.max(1, series.length) - gap))
  const h = (v: number) => (H - PAD.t - PAD.b) * (v / max)
  return (
    <Frame title={title} labels={labels} legend={<Legend series={series} />} table={<SeriesTable categories={categories} series={series} labels={labels} format={format} />}>
      <svg viewBox={`0 0 ${W} ${H}`} className="chart-svg" role="img" aria-label={title}>
        <Axis max={max} format={format} />
        {categories.map((c, ci) => {
          const x0 = PAD.l + band * ci + (band - (bw + gap) * series.length) / 2
          return (
            <g key={c}>
              {series.map((s, si) => {
                const v = s.values[ci] ?? 0
                return (
                  <rect key={s.name} className="chart-marca" tabIndex={0} x={x0 + si * (bw + gap)} y={H - PAD.b - h(v)} width={bw} height={Math.max(0, h(v))} rx={3} fill={color(si)}>
                    <title>{`${c} · ${s.name}: ${fmt(format, v)}`}</title>
                  </rect>
                )
              })}
              <text x={PAD.l + band * ci + band / 2} y={H - PAD.b + 16} textAnchor="middle" className="chart-cat">{c.length > 14 ? `${c.slice(0, 13)}…` : c}</text>
            </g>
          )
        })}
      </svg>
    </Frame>
  )
}

/** Líneas en el tiempo (una por serie) con puntos enfocables. */
export function LineChart({ title, categories, series, labels, format }: Common & { categories: string[]; series: Series[] }) {
  const max = niceMax(series.flatMap((s) => s.values))
  const box = { x: PAD.l, y: PAD.t, w: W - PAD.l - PAD.r, h: H - PAD.t - PAD.b }
  return (
    <Frame title={title} labels={labels} legend={<Legend series={series} />} table={<SeriesTable categories={categories} series={series} labels={labels} format={format} />}>
      <svg viewBox={`0 0 ${W} ${H}`} className="chart-svg" role="img" aria-label={title}>
        <Axis max={max} format={format} />
        {series.map((s, si) => {
          const { d, points } = linePath(s.values, max, box)
          return (
            <g key={s.name}>
              <path d={d} fill="none" stroke={color(si)} strokeWidth={2} strokeLinejoin="round" />
              {points.map((p, i) => (
                <circle key={i} className="chart-marca" tabIndex={0} cx={p.x} cy={p.y} r={4} fill={color(si)} stroke="var(--sup)" strokeWidth={2}>
                  <title>{`${categories[i] ?? ''} · ${s.name}: ${fmt(format, s.values[i] ?? 0)}`}</title>
                </circle>
              ))}
            </g>
          )
        })}
        {categories.map((c, i) => (
          <text key={c} x={box.x + (categories.length < 2 ? box.w / 2 : (box.w * i) / (categories.length - 1))} y={H - PAD.b + 16} textAnchor="middle" className="chart-cat">{c}</text>
        ))}
      </svg>
    </Frame>
  )
}

/** Dona con el total al centro y etiquetas directas por porción. */
export function DonutChart({ title, slices, labels, format, centerLabel }: Common & { slices: Array<{ name: string; value: number }>; centerLabel?: string }) {
  const total = slices.reduce((a, s) => a + s.value, 0)
  const arcs = donutArcs(slices.map((s) => s.value), 90, 60, { cx: 110, cy: 110 })
  return (
    <Frame
      title={title}
      labels={labels}
      table={<SeriesTable categories={slices.map((s) => s.name)} series={[{ name: title, values: slices.map((s) => s.value) }]} labels={labels} format={format} />}
    >
      <div className="chart-dona">
        <svg viewBox="0 0 220 220" className="chart-svg chart-svg-dona" role="img" aria-label={title}>
          {arcs.map((d, i) => (
            <path key={slices[i]?.name ?? i} d={d} className="chart-marca" tabIndex={0} fill={color(i)} stroke="var(--sup)" strokeWidth={2}>
              <title>{`${slices[i]?.name ?? ''}: ${fmt(format, slices[i]?.value ?? 0)}`}</title>
            </path>
          ))}
          <text x={110} y={106} textAnchor="middle" className="chart-total">{fmt(format, total)}</text>
          {centerLabel && <text x={110} y={128} textAnchor="middle" className="chart-cat">{centerLabel}</text>}
        </svg>
        <ul className="chart-leyenda chart-leyenda-col">
          {slices.map((s, i) => (
            <li key={s.name}>
              <span className="chart-muestra" style={{ background: color(i) }} aria-hidden="true" />
              {s.name} · <b>{fmt(format, s.value)}</b>{total > 0 ? ` (${Math.round((s.value / total) * 100)} %)` : ''}
            </li>
          ))}
        </ul>
      </div>
    </Frame>
  )
}

/** Mapa de calor: filas × columnas con una sola gama (claro → oscuro) y el valor escrito en cada celda. */
export function Heatmap({ title, rows, columns, values, labels, format }: Common & { rows: string[]; columns: string[]; values: number[][] }) {
  const max = Math.max(0, ...values.flat())
  return (
    <Frame
      title={title}
      labels={labels}
      table={<SeriesTable categories={rows} series={columns.map((c, ci) => ({ name: c, values: rows.map((_, ri) => values[ri]?.[ci] ?? 0) }))} labels={labels} format={format} />}
    >
      <div className="tabla-wrap">
        <div className="chart-calor" style={{ gridTemplateColumns: `minmax(90px, auto) repeat(${columns.length}, minmax(36px, 1fr))` }} role="img" aria-label={title}>
          <span />
          {columns.map((c) => <span key={c} className="chart-cat">{c}</span>)}
          {rows.map((r, ri) => (
            <div key={r} style={{ display: 'contents' }}>
              <span className="chart-cat chart-calor-fila">{r}</span>
              {columns.map((c, ci) => {
                const v = values[ri]?.[ci] ?? 0
                const level = heatLevel(v, max)
                return <span key={c} className={`chart-celda nivel-${level}`} title={`${r} · ${c}: ${fmt(format, v)}`}>{fmt(format, v)}</span>
              })}
            </div>
          ))}
        </div>
      </div>
    </Frame>
  )
}
