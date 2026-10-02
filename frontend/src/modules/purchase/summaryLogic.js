// 采购汇总/商品库纯逻辑（契约见 frontend/docs/purchase-api-contract.md §3.10、§3.2）
import { round2 } from './purchaseLogic'

// 项目×条线矩阵按条线合计（lines:null 视为 0）
export function lineTotals(matrix, lines) {
  const out = {}
  ;(lines || []).forEach((l) => { out[l] = 0 })
  ;(matrix || []).forEach((r) => {
    ;(lines || []).forEach((l) => {
      const cell = (r.lines || {})[l]
      if (cell) out[l] += cell.amount || 0
    })
  })
  Object.keys(out).forEach((k) => { out[k] = round2(out[k]) })
  return out
}

// 预算执行 KPI：超支/预算内项目数
export function budgetCounts(matrix) {
  let over = 0
  let within = 0
  ;(matrix || []).forEach((r) => {
    if (r.over) over++
    else within++
  })
  return { over, within }
}

// 未绑定商品汇总文案（products/unbound）
export function unboundSummary(u) {
  const groups = (u && u.data) || []
  const bindableKinds = groups.filter((g) => g.bindable).length
  const missingRows = groups.reduce((s, g) => s + (g.bindable ? 0 : g.rows || 0), 0)
  return {
    rows: (u && u.total_rows) || 0,
    kinds: groups.length,
    amount: u && u.total_amount,
    bindableKinds,
    missingRows,
  }
}

// 别名字符串 "a|b|c" → 展示数组
export function aliasList(aliases) {
  return String(aliases || '').split('|').filter((s) => s !== '')
}
