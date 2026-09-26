// 报表纯逻辑 — 复刻旧版 app.js:2236-2243（rDelta/rMoney）
export function deltaInfo(v) {
  if (v === null || v === undefined) return null
  const up = v > 0, down = v < 0
  return { arrow: up ? '↑' : down ? '↓' : '—', color: up ? '#16a34a' : down ? '#dc2626' : '#94a3b8', value: Math.abs(v) }
}

export function rMoney(v) {
  return '¥' + (Number(v) || 0).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}
