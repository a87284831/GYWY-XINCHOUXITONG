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

// 在保状态：早于今天=expired；今天起 30 天内（含第 30 天）=soon；其余 normal
export function maintStatusOf(endDate, today) {
  const t = today || maintToday()
  const thr = maintAddDays(t, 30)
  if (!endDate) return 'normal'
  if (endDate < t) return 'expired'
  if (endDate <= thr) return 'soon'
  return 'normal'
}

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

// 导出行构造 — 复刻 maintExportLedger（app.js:3097-3103）
export function buildLedgerExportRows(rows, isElev, statusOf) {
  return rows.map((r) => {
    const o = { '项目名称': r.project_name, '签约方': r.party, '签约金额': Number(r.amount) || 0, '签订日期': r.sign_date, '生效开始': r.start_date, '到期日期': r.end_date }
    if (isElev) { o['电梯台数'] = Number(r.elevator_count) || 0; o['每台单价'] = Number(r.price_per_unit) || 0 }
    else { o['建筑面积(㎡)'] = Number(r.building_area_sqm) || 0; o['每㎡单价'] = Number(r.price_per_sqm) || 0 }
    o['状态'] = statusOf(r.end_date)
    o['备注'] = r.remark || ''
    return o
  })
}

// CSV 生成 — 复刻 maintDownloadCsv（BOM + 引号双写转义）
export function buildCsv(filename, rows) {
  void filename
  if (!rows.length) return ''
  const keys = Object.keys(rows[0])
  const bom = '\uFEFF'
  const csv = bom + keys.join(',') + '\n' + rows.map((r) => keys.map((k) => `"${String(r[k] ?? '').replace(/"/g, '""')}"`).join(',')).join('\n')
  return csv
}

// 单价自动计算 — 复刻 maintContractEdit 联动（金额÷台数/面积，2 位小数；除数为 0 → 空串）
export function priceAutoCalc(amount, count, isElev) {
  const amt = Number(amount) || 0
  const n = Number(count) || 0
  if (isElev) return n > 0 ? (amt / n).toFixed(2) : ''
  return n > 0 ? (amt / n).toFixed(2) : ''
}
