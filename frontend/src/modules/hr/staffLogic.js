// 人员/预算纯逻辑（复刻旧版 app.js 的 sfDeriveStatus/sfDeriveCategory/staffDeduct 归一化/budgetSyncAnnual/敏感字段打码）

export function todayStr() {
  const t = new Date()
  return `${t.getFullYear()}-${String(t.getMonth() + 1).padStart(2, '0')}-${String(t.getDate()).padStart(2, '0')}`
}

// 复刻 sfDeriveStatus：离职日期≤今天→离职；有转正日期→未到试用/已到正式；无转正且入职在未来→试用；否则正式
export function deriveStaffStatus(v, today) {
  const h = v.hire_date || '', r = v.regular_date || '', d = v.resign_date || ''
  if (d && d <= today) return '离职'
  if (r) return r > today ? '试用' : '正式'
  if (h && h > today) return '试用'
  return '正式'
}

// 复刻 sfDeriveCategory：黑名单优先 → 离职日期≤今天→离职 → 在职
export function deriveStaffCategory(v, today) {
  if (v.blacklist) return '黑名单'
  const d = v.resign_date || ''
  if (d && d <= today) return '离职'
  return '在职'
}

// 复刻 staffDeduct 的 sd 归一化：数组原样；对象映射 {项: 值|{amount,from_ym}} → 数组；空 → []
export function normalizeSpecialDeductions(sd) {
  if (Array.isArray(sd)) return sd
  if (sd && typeof sd === 'object') {
    return Object.keys(sd).map((k) => ({
      item: k,
      amount: typeof sd[k] === 'object' && sd[k] !== null ? sd[k].amount : sd[k],
      from_ym: typeof sd[k] === 'object' && sd[k] !== null ? sd[k].from_ym || '' : '',
    }))
  }
  return []
}

// 复刻 budgetSyncAnnual：年度总预算 = 各月之和（四舍五入 2 位）
export function budgetAnnualFromMonths(months) {
  const sum = Object.values(months || {}).reduce((s, v) => s + (Number(v) || 0), 0)
  return Math.round(sum * 100) / 100
}

// 复刻 loadStaff 行内打码正则
export function maskBankCard(v) {
  return String(v || '').replace(/^(\d{4})\d+(\d{4})$/, '$1****$2')
}
export function maskIdCard(v) {
  return String(v || '').replace(/^(.{4}).+(.{4})$/, '$1**********$2')
}
