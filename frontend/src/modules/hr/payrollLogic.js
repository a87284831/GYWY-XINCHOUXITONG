// hr 页组纯逻辑（复刻旧版 app.js 对应函数，供组件与单测共用）

// 复刻 renderSummary 的合计口径（app.js:698-704）
export function summaryTotals(items) {
  const t = { headcount: 0, gross: 0, net: 0, month_budget: 0, annual_budget: 0, ytd_gross: 0 }
  for (const it of items) {
    t.headcount += it.headcount
    t.gross += it.gross
    t.net += it.net
    t.month_budget += it.month_budget
    t.annual_budget += it.annual_budget
    t.ytd_gross += it.ytd_gross
  }
  t.month_rate = t.month_budget > 0 ? t.gross / t.month_budget : 0
  t.annual_rate = t.annual_budget > 0 ? t.ytd_gross / t.annual_budget : 0
  return t
}

// 微调字段定义（复刻 ADJUST_FIELDS / FIELD_CN，app.js:1184-1189、1263-1268）
export const ADJUST_GROUPS = [
  ['req_att', 'act_att', 'perf_att', 'coef'],
  ['night', 'meal', 'title_sub', 'reward', 'welfare'],
  ['punish', 'late_d', 'miss_d', 'other_d', 'uniform_d'],
  ['pen', 'med', 'une', 'house', 'big'],
  ['spec_rent', 'spec_loan', 'spec_child', 'spec_elder', 'spec_edu', 'spec_baby'],
  ['actual_tax'],
]
export const FIELD_CN = {
  night: '夜班/话费补贴', meal: '餐补', title_sub: '其他补贴', reward: '月度奖励', welfare: '已发福利(计税不发现金)',
  punish: '月度扣罚', late_d: '迟到早退扣款', miss_d: '缺卡扣款', other_d: '其他扣款', uniform_d: '工装扣款',
  pen: '养老保险', med: '医疗保险', une: '失业保险', house: '公积金', big: '大病', coef: '绩效系数',
  req_att: '应出勤', act_att: '实际出勤', perf_att: '绩效计薪出勤', actual_tax: '本月实缴个税',
  spec_rent: '租房租金', spec_loan: '住房贷款利息', spec_child: '子女教育', spec_elder: '赡养老人',
  spec_edu: '继续教育', spec_baby: '婴幼儿照护', remark: '备注',
}
const SPEC_FIELDS = ['spec_rent', 'spec_loan', 'spec_child', 'spec_elder', 'spec_edu', 'spec_baby']

// 复刻 saveAdjust 的差异收集（app.js:1301-1328）：
// - 普通字段：空输入跳过、非数字跳过、与现值差异 >0.001 才计入
// - 专项附加六项：始终全量收集（空按 0），任一项有变化则六项整体回传
// - remark：与现值不同才计入；reason 不入 changes
export function computeAdjustChanges(row, values, remark) {
  const changes = {}
  let specChanged = false
  const specAll = {}
  for (const group of ADJUST_GROUPS) {
    for (const f of group) {
      const raw = values[f]
      if (raw === undefined) continue
      if (SPEC_FIELDS.includes(f)) {
        const sv = raw === '' ? 0 : (parseFloat(raw) || 0)
        specAll[f] = sv
        if (Math.abs(sv - Number(row[f] || 0)) > 0.001) specChanged = true
        continue
      }
      if (raw === '') continue
      const v = parseFloat(raw)
      if (isNaN(v)) continue
      if (Math.abs(v - Number(row[f] || 0)) > 0.001) changes[f] = v
    }
  }
  if (specChanged) Object.assign(changes, specAll)
  if (remark !== undefined && remark !== null && remark !== '' && remark !== (row.remark || '')) changes.remark = remark
  return changes
}

// 合计字段与表格列顺序对应（复刻 payTotalHtml，app.js:1218-1231）
const TOTAL_FIELDS = ['base_pay', 'perf_pay', 'sick_pay', 'night', 'meal', 'title_sub', 'reward', 'welfare',
  'punish', 'late_d', 'miss_d', 'other_d', 'uniform_d', 'gross', 'soc_total', 'spec_total', 'actual_tax', 'net']

export function payTotalRow(rows) {
  const sums = {}
  for (const f of TOTAL_FIELDS) sums[f] = rows.reduce((s, r) => s + (Number(r[f]) || 0), 0)
  return { count: rows.length, sums }
}

// 复刻 payDeptOptions（app.js:1233-1237）
export function payDeptOptions(rows, project) {
  const list = rows.filter((r) => !project || r.project === project)
    .map((r) => r.department || '').filter((d) => d !== '')
  return Array.from(new Set(list))
}

// 复刻 filterPayroll 的行筛选（app.js:1247-1250）
export function filterPayRows(rows, project, dept) {
  return rows.filter((r) => (!project || r.project === project) && (!dept || (r.department || '') === dept))
}

// ---------------- 季度绩效 ----------------

const QUARTER_END_MONTHS = [4, 7, 10, 1]
const HALF_YEAR_END_MONTHS = [7, 1]

/** 判断 ym 是否季度末月 */
export function isQuarterEndMonth(ym) {
  const m = Number((ym || '').slice(5, 7))
  return QUARTER_END_MONTHS.includes(m)
}

/** 判断 ym 是否半年度末月（7月/1月） */
export function isHalfYearEndMonth(ym) {
  const m = Number((ym || '').slice(5, 7))
  return HALF_YEAR_END_MONTHS.includes(m)
}

/** 根据核算月返回季度/半年度标识（1月归上年 Q4/H2） */
export function periodKeys(ym) {
  const y = Number((ym || '').slice(0, 4))
  const m = Number((ym || '').slice(5, 7))
  const map = {
    4: { q: `${y}-Q1`, h: null },
    7: { q: `${y}-Q2`, h: `${y}-H1` },
    10: { q: `${y}-Q3`, h: null },
    1: { q: `${y - 1}-Q4`, h: `${y - 1}-H2` },
  }
  return map[m] || { q: null, h: null }
}

/** 生成录入按钮文案 */
export function coefEntryLabel(ym) {
  const { q, h } = periodKeys(ym)
  if (!q) return ''
  return h ? `📊 录入 ${q} 季度 + ${h} 半年度系数` : `📊 录入 ${q} 季度系数`
}

/**
 * 工资表横向逐月绩效列（管理/总部，季度末月）：返回 [{key, label}]。
 * key 与 Excel 导出一致："Q1|2026-01" / "H1|2026-04"；按月排序，同月 Q 在前 H 在后。
 */
export function perfDetailCols(rows) {
  const cols = {}
  for (const r of rows || []) {
    const d = r && r.perf_detail
    if (!d || d.error || !Array.isArray(d.months)) continue
    const qTag = String(d.period || '').slice(5) || 'Q'
    for (const m of d.months) cols[`${qTag}|${m.ym}`] = `${qTag}·${Number(m.ym.slice(5, 7))}月绩效`
    const h = d.half_year
    if (h && !h.error && Array.isArray(h.months)) {
      const hTag = String(h.period || '').slice(5) || 'H'
      for (const m of h.months) cols[`${hTag}|${m.ym}`] = `${hTag}·${Number(m.ym.slice(5, 7))}月绩效`
    }
  }
  const byte = (x, y) => (x < y ? -1 : x > y ? 1 : 0) // 字节序（localeCompare 会弱化 ~ | 符号，导致 Q/H 顺序错乱）
  return Object.entries(cols)
    .sort((a, b) => byte(a[0].slice(-7) + (a[0].startsWith('H') ? '~' : '') + a[0], b[0].slice(-7) + (b[0].startsWith('H') ? '~' : '') + b[0]))
    .map(([key, label]) => ({ key, label }))
}

/** 横向明细单元格金额：key = "Q1|2026-01"；无该月明细 → null（页面显示空，与导出一致） */
export function perfDetailCell(row, key) {
  const d = row && row.perf_detail
  if (!d || d.error) return null
  const [tag, ym] = String(key).split('|')
  const src = tag.startsWith('H')
    ? (d.half_year && !d.half_year.error && Array.isArray(d.half_year.months) ? d.half_year.months : [])
    : (Array.isArray(d.months) ? d.months : [])
  const m = src.find((x) => x.ym === ym)
  return m ? m.amount : null
}

// ---------------- 数据缺口防护（Task 4 纯函数） ----------------

/** 判断是否为 0 工资/负工资行（用于表格高亮）。
 * net 缺失 → Number(undefined)=NaN<=0 为 false（避免误伤）；row 为 null/undefined → false（防御性）。 */
export function isZeroPayRow(row) {
  if (row == null) return false
  return Number(row.net) <= 0
}

/**
 * 将 missing 与 warnings 两组合并扁平，按 level 分组。
 * 无 level 键一律归 info（前向兼容旧条目）。danger/info 各自保持原始相对顺序。
 * @returns {{ danger: Array, info: Array }}
 */
export function groupNotices(missing, warnings) {
  const all = [...(missing || []), ...(warnings || [])]
  const danger = []
  const info = []
  for (const e of all) {
    if (e && e.level === 'danger') danger.push(e)
    else info.push(e)
  }
  return { danger, info }
}

/**
 * 生成归档前异常确认文案。
 * 首行：「以下 N 条薪资异常未处理：」；每条一行：「姓名（项目）：reason」（project 为空则只写「姓名：reason」）；
 * 末行固定：「确认仍要归档吗？忽略异常可能导致错误工资发放。」
 */
export function archiveBlockerText(blockers) {
  const list = Array.isArray(blockers) ? blockers : []
  const lines = [`以下 ${list.length} 条薪资异常未处理：`]
  for (const b of list) {
    const name = b && b.name != null ? b.name : ''
    const project = b && b.project != null ? b.project : ''
    const reason = b && b.reason != null ? b.reason : ''
    const head = project ? `${name}（${project}）` : name
    lines.push(`${head}：${reason}`)
  }
  lines.push('确认仍要归档吗？忽略异常可能导致错误工资发放。')
  return lines.join('\n')
}
