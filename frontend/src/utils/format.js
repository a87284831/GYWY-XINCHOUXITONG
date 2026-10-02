// 数字格式（复刻 app.js money/pct：千分位 2 位小数、百分比 2 位）
export function money(x) {
  const n = Number(x || 0)
  return n.toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

export function pct(x) {
  return (Number(x || 0) * 100).toFixed(2) + '%'
}

/** 空值占位符：null/undefined/'' → '—'；其余原样返回（0 不转换） */
export function fmtEmpty(v) {
  return v === null || v === undefined || v === '' ? '—' : v
}

/** 金额空值守卫：null/undefined/'' → '—'；其余走 money（真实 0 → '0.00'） */
export function moneyOrDash(v) {
  return v === null || v === undefined || v === '' ? '—' : money(v)
}

/** 百分比空值守卫：null/undefined/'' → '—'；其余走 pct（真实 0 → '0.00%'） */
export function pctOrDash(v) {
  return v === null || v === undefined || v === '' ? '—' : pct(v)
}
