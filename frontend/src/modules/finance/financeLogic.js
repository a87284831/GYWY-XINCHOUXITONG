// 财务模块纯逻辑（复刻 public/finance/index.html 的数据处理与请求拼参）
import { money } from '@/utils/format'

export const fmtMoney = money

export function moneyCell(v) {
  const n = Number(v || 0)
  return n ? money(n) : '—'
}

export function round2(v) {
  return Math.round((Number(v) || 0) * 100) / 100
}

export function cumVals(vals) {
  let c = 0
  return vals.map((v) => {
    c += v
    return round2(c)
  })
}

export function trendData(monthlyTrend) {
  const months = Object.keys(monthlyTrend || {})
  const vals = months.map((m) => round2(monthlyTrend[m] || 0))
  return { months, vals, cumVals: cumVals(vals) }
}

export function roseData(catTotal, cats) {
  return Object.keys(catTotal || {})
    .map((k) => ({ name: (cats || {})[k] || k, value: round2(catTotal[k]) }))
    .filter((x) => x.value > 0)
}

export function rankData(items, field) {
  return (items || [])
    .map((x) => ({ name: x.name, value: round2(x[field] || 0) }))
    .filter((x) => x.value > 0)
}

export function topN(data, n) {
  return (data || []).slice().sort((a, b) => b.value - a.value).slice(0, n)
}

export function histData(history) {
  return Object.keys(history || {}).map((y) => ({ name: y, value: round2(history[y] || 0) }))
}

export function heatData(heatmap) {
  const months = Object.keys((heatmap && heatmap[0] && heatmap[0].values) || {})
  const projects = (heatmap || []).map((x) => x.name)
  const points = []
  let max = 0
  ;(heatmap || []).forEach((p, i) => {
    months.forEach((m, j) => {
      const v = round2((p.values || {})[m] || 0)
      if (v > 0) {
        points.push([j, i, v])
        if (v > max) max = v
      }
    })
  })
  return { months, projects, points, max }
}

export const SANKEY_PALETTE = ['#6366f1', '#06b6d4', '#8b5cf6', '#f43f5e', '#14b8a6', '#f97316', '#0ea5e9', '#a855f7']

export function sankeyData(d) {
  const types = d.payment_types || {}
  const typeTotal = d.payment_type_total || {}
  const typePaid = d.payment_type_paid || {}
  const nodes = [
    { name: '已确认数据', itemStyle: { color: '#3b82f6' } },
    { name: '已支付', itemStyle: { color: '#10b981' } },
    { name: '未支付', itemStyle: { color: '#f59e0b' } },
  ]
  const links = []
  let ci = 0
  Object.keys(types).forEach((k) => {
    const t = types[k]
    const cTotal = round2(typeTotal[k] || 0)
    const cPaid = round2(typePaid[k] || 0)
    const cUnpaid = Math.max(round2(cTotal - cPaid), 0)
    if (!cTotal) return
    nodes.push({ name: t, itemStyle: { color: SANKEY_PALETTE[ci++ % SANKEY_PALETTE.length] } })
    links.push({ source: '已确认数据', target: t, value: cTotal })
    if (cPaid > 0) links.push({ source: t, target: '已支付', value: cPaid })
    if (cUnpaid > 0) links.push({ source: t, target: '未支付', value: cUnpaid })
  })
  return { nodes, links }
}

export function ledgerMonthSum(values) {
  return (values || []).reduce((s, v) => s + (Number(v || 0) || 0), 0)
}

export function toAmount(v) {
  const n = Number(v)
  return v !== '' && !isNaN(n) ? n : 0
}

export function ledgerSaveItems(cells) {
  return (cells || []).map((c) => ({ category: c.category, amount: toAmount(c.value) }))
}

export function defaultProjId(projects) {
  const list = projects || []
  let def = list.length ? list[0].id : 0
  for (const p of list) {
    if (p.id != 17) {
      def = p.id
      break
    }
  }
  return def
}

export function summaryTotals(a) {
  const perCat = {}
  Object.keys(a.categories || {}).forEach((k) => {
    let s = 0
    ;(a.months || []).forEach((m) => {
      s += ((a.grid || {})[m] || {})[k] || 0
    })
    perCat[k] = s
  })
  return perCat
}

export function payGroupByProject(cells) {
  const byProj = {}
  ;(cells || []).forEach((c) => {
    if (!byProj[c.project_id]) byProj[c.project_id] = []
    byProj[c.project_id].push({ type: c.type, amount: toAmount(c.value) })
  })
  return byProj
}

export function qs(params) {
  const sp = new URLSearchParams()
  Object.keys(params || {}).forEach((k) => {
    const v = params[k]
    if (v !== undefined && v !== null && v !== '') sp.append(k, v)
  })
  const s = sp.toString()
  return s ? '?' + s : ''
}

export function defaultYears() {
  const y = []
  for (let i = 2026; i >= 2020; i--) y.push(i)
  return y
}
