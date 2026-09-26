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
