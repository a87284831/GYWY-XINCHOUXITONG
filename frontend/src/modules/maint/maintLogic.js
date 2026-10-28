// 维保模块纯逻辑 — 复刻旧版 app.js:2507-2527, 2886-2932, 3090-3104, 2830-2834, 3025-3034
export function maintToday(now) {
  const d = now || new Date()
  return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0')
}

export function maintAddDays(dstr, n) {
  const d = new Date(dstr + 'T00:00:00')
  d.setDate(d.getDate() + n)
  return d.toISOString().slice(0, 10)
}

// 合同状态分级（与后端 MaintenanceService::contractStatus 同口径）：
// 无到期日=missing；早于今天=expired；30/60/90 天内（含第 N 天）=d30/d60/d90；其余 normal
export function maintStatusOf(endDate, today) {
  const t = today || maintToday()
  if (!endDate) return 'missing'
  if (endDate < t) return 'expired'
  if (endDate <= maintAddDays(t, 30)) return 'd30'
  if (endDate <= maintAddDays(t, 60)) return 'd60'
  if (endDate <= maintAddDays(t, 90)) return 'd90'
  return 'normal'
}

export const MAINT_STATUS_LABELS = {
  normal: '正常在保', d90: '90天内到期', d60: '60天内到期',
  d30: '30天内到期', expired: '已过期', missing: '无到期日',
}
export const MAINT_STATUS_TAG = {
  normal: 'tag green', d90: 'tag blue', d60: 'tag orange',
  d30: 'tag red', expired: 'tag red', missing: 'tag gray',
}
// 状态快捷筛选选项（台账/报表共用）
export const MAINT_STATUS_OPTS = ['normal', 'd90', 'd60', 'd30', 'expired', 'missing']

export function maintOverlapsYear(start, end, year) {
  return start <= year + '-12-31' && end >= year + '-01-01'
}

export function maintFmtMoney(n) {
  return (Number(n) || 0).toLocaleString('zh-CN')
}

// 台账筛选+排序 — 复刻 maintApplyFilter（focus 聚焦时忽略常规筛选）
export function filterLedger(rows, f, type, sort, focus) {
  let list = rows.slice()
  if (focus && focus.type === type && focus.id != null) {
    list = list.filter((r) => r.id === focus.id)
  } else if (focus && focus.type === type && focus.projectFilter) {
    list = list.filter((r) => r.project_name === focus.projectFilter)
  } else {
    if (f.kw) list = list.filter((r) => (r.project_name + r.party + r.remark).toLowerCase().includes(f.kw))
    if (f.project) list = list.filter((r) => r.project_name === f.project)
    if (f.status) list = list.filter((r) => (r.status || maintStatusOf(r.end_date)) === f.status)
    if (f.efrom) list = list.filter((r) => r.end_date >= f.efrom)
    if (f.eto) list = list.filter((r) => r.end_date <= f.eto)
    if (f.party) list = list.filter((r) => (r.party || '').toLowerCase().includes(f.party))
    if (f.amin != null) list = list.filter((r) => (Number(r.amount) || 0) >= f.amin)
    if (f.amax != null) list = list.filter((r) => (Number(r.amount) || 0) <= f.amax)
    if (type === 'elevator') {
      if (f.emin != null) list = list.filter((r) => (Number(r.elevator_count) || 0) >= f.emin)
      if (f.emax != null) list = list.filter((r) => (Number(r.elevator_count) || 0) <= f.emax)
    }
    if (f.year) list = list.filter((r) => maintOverlapsYear(r.start_date, r.end_date, f.year))
  }
  const dir = sort && sort.dir === 'desc' ? -1 : 1
  const field = f.sortField
  if (field) {
    list.sort((a, b) => {
      let va = a[field], vb = b[field]
      if (field === 'amount' || field === 'elevator_count') { va = Number(va) || 0; vb = Number(vb) || 0 }
      if (va < vb) return -1 * dir
      if (va > vb) return 1 * dir
      return 0
    })
  }
  return list
}

// 单价自动计算 — 复刻 maintContractEdit 联动（金额÷台数/面积，2 位小数；除数为 0 → 空串）
export function priceAutoCalc(amount, count, isElev) {
  const amt = Number(amount) || 0
  const n = Number(count) || 0
  if (isElev) return n > 0 ? (amt / n).toFixed(2) : ''
  return n > 0 ? (amt / n).toFixed(2) : ''
}
