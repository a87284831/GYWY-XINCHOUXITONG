// 系统设置页组纯逻辑 — 复刻 settingsPerm（app.js:3843-3851 角色图标/色）、pageSalarySettings（app.js:3249-3746 符号库/公式/个税级距）
export function roleIcon(id, name) {
  const map = { admin: '超', project: '项', staff: '员', viewer: '只' }
  if (map[id]) return map[id]
  return (name || '角').slice(0, 1)
}
export function roleColor(id) {
  const map = { admin: '#2B7CF6', project: '#23A355', staff: '#F58B1F', viewer: '#8A94A6' }
  return map[id] || '#9EACEA'
}

/* ---- 薪酬设置：公式 ---- */
export const VAR_CN = {
  base_pay: '应发基本工资', perf_pay: '应发绩效工资', sick_pay: '病假工资', night: '夜班话费补贴', meal: '餐补',
  title_sub: '其他补贴', reward: '月度奖励', welfare: '已发福利', punish: '月度扣罚', miss_d: '缺卡扣款',
  late_d: '迟到早退扣款', other_d: '其他扣款', uniform_d: '工装扣款', gross: '应发合计', soc_total: '五险一金合计',
  actual_tax: '本月个税', pen: '养老保险', med: '医疗保险', une: '失业保险', house: '住房公积金', big: '大病',
  spec_total: '附加扣除合计',
}
export function cnFormula(f) { return String(f || '').replace(/[a-zA-Z_]\w*/g, (m) => VAR_CN[m] || m) }
// 中文→英文逆映射（保存公式时用，后端 Expr 只支持英文标识符）
const VAR_EN = Object.fromEntries(Object.entries(VAR_CN).map(([en, cn]) => [cn, en]))
export function enFormula(f) { return String(f || '').replace(/[\u4e00-\u9fa5]+/g, (m) => VAR_EN[m] || m) }
export const GROSS_DEFAULT = '应发基本工资 + 应发绩效工资 + 病假工资 + 夜班话费补贴 + 餐补 + 其他补贴 + 月度奖励 + 已发福利 - 月度扣罚 - 缺卡扣款 - 迟到早退扣款 - 其他扣款 - 工装扣款'
export const NET_DEFAULT = '应发合计 - 五险一金合计 - 本月个税 - 已发福利'
export const BUILTIN_VARS = ['应发基本工资', '应发绩效工资', '病假工资', '夜班话费补贴', '餐补', '其他补贴', '月度奖励', '已发福利', '月度扣罚', '缺卡扣款', '迟到早退扣款', '其他扣款', '工装扣款', '应发合计', '五险一金合计', '本月个税', '养老保险', '医疗保险', '失业保险', '住房公积金', '大病', '附加扣除合计']

/* ---- 薪酬设置：个税税率级距 ---- */
export const STD_TAX_BRACKETS = [
  [36000, 0.03, 0], [144000, 0.10, 2520], [300000, 0.20, 16920],
  [420000, 0.25, 31920], [660000, 0.30, 52920], [960000, 0.35, 85920],
  [99999999999, 0.45, 181920],
]
export function taxRatePct(rate) { return Math.round(Number(rate) * 10000) / 100 }
// 在末档前插入新档（纯函数）— 复刻 taxAddBracket（app.js:3502）
export function taxAddBracket(arr) {
  const a = arr.map((b) => [Number(b[0]), Number(b[1]), Number(b[2])])
  const n = a.length
  const prevCap = n >= 2 ? a[n - 2][0] : 36000
  const newCap = Math.max(Math.round(prevCap * 2), prevCap + 36000)
  const prevRate = n >= 1 ? a[n - 1][1] : 0.03
  a.splice(Math.max(n - 1, 0), 0, [newCap, Math.min(Number(prevRate) + 0.05, 0.45), 0])
  return a
}
// 保存前规整：过滤非法税率并按上限排序 — 复刻 saveAllSalarySettings（app.js:3546-3548）
export function taxNormalize(brackets) {
  const out = brackets.map((x) => [Number(x[0]), Number(x[1]), Number(x[2])])
  return out.filter((x) => x[1] > 0 && x[1] <= 1).sort((a, b) => a[0] - b[0])
}

/* ---- 薪酬设置：公式验证（前端安全求值） — 复刻 evalFormulaSafe（app.js:3628） ---- */
export function evalFormulaSafe(expr, vars) {
  // 支持：数字、中文/英文白名单变量、+ - * / % ( ) , 以及白名单函数 min/max/abs/round/floor/ceil/if
  const tokens = expr.match(/[\u4e00-\u9fa5]+|[a-zA-Z_]\w*|[0-9.]+|[+\-*/%(),]/g) || []
  const rebuilt = tokens.join('')
  if (rebuilt !== expr.replace(/\s/g, '')) throw new Error('含非法字符')
  const allowed = Object.keys(vars)
  const code = tokens.map((t) => {
    if (/^[0-9.]+$/.test(t)) return t
    if (/^[\u4e00-\u9fa5]+$/.test(t) || /^[a-zA-Z_]\w*$/.test(t)) {
      if (!allowed.includes(t)) throw new Error('未知项目: ' + t)
      return '(' + vars[t] + ')'
    }
    return t
  }).join('')
  // 将白名单函数映射到 JS 等价物，与后端 Expr 支持的函数保持一致
  const jsCode = code
    .replace(/\bmin\(/g, 'Math.min(')
    .replace(/\bmax\(/g, 'Math.max(')
    .replace(/\babs\(/g, 'Math.abs(')
    .replace(/\bround\(/g, 'Math.round(')
    .replace(/\bfloor\(/g, 'Math.floor(')
    .replace(/\bceil\(/g, 'Math.ceil(')
    .replace(/\bif\(/g, '_if(')
  return Function('Math', '_if', '"use strict";return (' + jsCode + ')')(Math, (c, t, e) => (c ? t : e))
}

/* ---- 薪酬设置：自定义薪酬项规整 — 复刻 cfSave/saveAllSalarySettings（app.js:3386/3523） ---- */
export function normalizeCustomFields(fields) {
  return (fields || []).filter((f) => f && f.name && f.name.trim()).map((f) => ({
    name: f.name.trim(), type: f.type || 'subsidy', source: f.source || 'fixed',
    enabled: !!f.enabled, default: parseFloat(f.default) || 0,
  }))
}

/* ---- 符号库归类 ---- */
export const SYM_CATEGORIES = ['正常', '事假', '病假', '产假', '年假调休', '缺卡', '旷工', '迟到', '早退', '值班', '公休', '其他']
export const SYM_FORMULA_CN = { personal: '事假', sick: '病假', maternity: '产假', paid: '带薪假', miss: '缺卡', absent: '旷工', late: '迟到', early: '早退' }

/* ---- 设置中心侧栏 tab（app.js:3780-3789） ---- */
export const SETTINGS_TABS = [
  { key: 'perm', label: '👤 权限管理', perm: 'users' },
  { key: 'approvalFlow', label: '✅ 审批权责设置', perm: 'users' },
  { key: 'salarySettings', label: '💰 薪酬设置', perm: 'rules' },
  { key: 'company', label: '🏢 公司信息', perm: 'settings' },
  { key: 'security', label: '🔒 登录安全', perm: 'settings' },
  { key: 'backup', label: '💾 数据备份', perm: 'backup' },
  { key: 'logs', label: '📜 操作日志', perm: 'logs' },
]
export function visibleTabs(tabs, can) { return tabs.filter((t) => can(t.perm)) }

/* ---- 备份文件大小 ---- */
export function fmtSize(bytes) { return (bytes / 1024).toFixed(1) + ' KB' }

/* ---- 薪酬设置：管理/总部绩效发放规则（pay_rules.manager / .hq） ----
 * monthly       月度绩效法
 * quarterly     分期兑现绩效法（三档配 季度比例/半年度比例）
 * quarter_grade 季度绩效法（三档仅选 季度型/月度型，无比例、无半年度）
 * 薪酬档位与后端 CalcRules::PAY_GRADES 逐字一致 */
export const PAY_GRADES = ['专员级', '主管级', '经理级']
const PAY_CYCLES = ['monthly', 'quarterly', 'quarter_grade']

// 后端配置 → 设置页编辑态
export function payRuleToDraft(src) {
  const cycle = PAY_CYCLES.includes(src?.cycle) ? src.cycle : 'monthly'
  const levels = (src && src.levels) || {}
  const ratios = {}
  const modes = {}
  for (const g of PAY_GRADES) {
    ratios[g] = {
      quarter_ratio: Number(levels[g]?.quarter_ratio ?? 0),
      half_year_ratio: Number(levels[g]?.half_year_ratio ?? 0),
    }
    modes[g] = levels[g]?.mode === 'quarter' ? 'quarter' : 'monthly'
  }
  return { cycle, ratios, modes }
}

// 设置页编辑态 → 后端保存结构
export function draftToPayRule(d) {
  if (d.cycle === 'quarterly') {
    const levels = {}
    for (const g of PAY_GRADES) {
      levels[g] = {
        quarter_ratio: parseFloat(d.ratios[g].quarter_ratio) || 0,
        half_year_ratio: parseFloat(d.ratios[g].half_year_ratio) || 0,
      }
    }
    return { cycle: 'quarterly', levels }
  }
  if (d.cycle === 'quarter_grade') {
    const levels = {}
    for (const g of PAY_GRADES) {
      levels[g] = { mode: d.modes[g] === 'quarter' ? 'quarter' : 'monthly' }
    }
    return { cycle: 'quarter_grade', levels }
  }
  return { cycle: 'monthly', ratio: 1.0 }
}
