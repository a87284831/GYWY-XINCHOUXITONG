// 采购模块纯逻辑（驾驶舱 KPI 汇总与文案；契约见 frontend/docs/purchase-api-contract.md）
import { money } from '@/utils/format'

export function prevMonth(m) {
  const d = new Date(m + '-01T00:00:00')
  d.setMonth(d.getMonth() - 1)
  return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0')
}

export function sumRows(rows) {
  const out = { current: 0, prev: 0, diff: 0 }
  ;(rows || []).forEach((r) => {
    out.current += r.current || 0
    out.prev += r.prev || 0
    out.diff += r.diff || 0
  })
  return out
}

export function signedMoney(d) {
  const n = Number(d) || 0
  if (n > 0) return '+' + money(n)
  if (n < 0) return '-' + money(-n)
  return '±0.00'
}

// 红涨绿跌：up=true 涨（红）、false 跌（绿）、null 平/无数据（灰）
export function deltaInfo(cur, prev) {
  if (!prev) return { text: '上月无数据', up: null }
  const d = (Number(cur) || 0) - (Number(prev) || 0)
  return { text: '较上月 ' + signedMoney(d) + ' 元', up: d > 0 ? true : d < 0 ? false : null }
}

export function rateText(rate) {
  if (rate === null || rate === undefined || rate === '') return '—'
  return Number(rate).toFixed(1) + '%'
}

export function topWindow(top) {
  if (!top || !top.start || !top.end) return ''
  return top.start + ' ~ ' + top.end
}

export function round2(v) {
  return Math.round((Number(v) || 0) * 100) / 100
}

export function cumAmount(rows) {
  let c = 0
  return (rows || []).map((r) => {
    c += r.amount || 0
    return { month: r.month, amount: r.amount || 0, cum: round2(c) }
  })
}

// query string 拼接：跳过 undefined/null/空串；无有效参数返回空串
export function qs(params) {
  const sp = new URLSearchParams()
  Object.keys(params || {}).forEach((k) => {
    const v = params[k]
    if (v !== undefined && v !== null && v !== '') sp.append(k, v)
  })
  const s = sp.toString()
  return s ? '?' + s : ''
}
