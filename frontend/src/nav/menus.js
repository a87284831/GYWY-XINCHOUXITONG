// 导航与页面元数据（复刻旧版 app.js 的 ALL_MENUS/APPS/HOME_META/PAGE_TITLES 与相关判定逻辑）
import router from '@/router'

export const APPS = [
  { key: 'hr', name: '人力资源', icon: '👥', color: '#2563eb' },
  { key: 'contract', name: '合同管理', icon: '📄', color: '#e74c3c' },
  { key: 'admin', name: '行政管理', icon: '🏢', color: '#8b5cf6' },
  { key: 'oa', name: 'OA审批', icon: '✅', color: '#3b82f6' },
  { key: 'property', name: '房产管理', icon: '🏠', color: '#1e40af' },
  { key: 'maintain', name: '工程维保', icon: '🔧', color: '#f59e0b' },
  { key: 'security', name: '安保管理', icon: '🛡️', color: '#16a34a' },
  { key: 'cleaning', name: '保洁绿化', icon: '🧹', color: '#14b8a6' },
  { key: 'purchase', name: '采购管理', icon: '📦', color: '#6366f1' },
  { key: 'finance', name: '财务管理', icon: '💰', color: '#0d9488' },
  { key: 'report', name: '报表中心', icon: '📊', color: '#0ea5e9' },
  { key: 'notice', name: '公告通知', icon: '📢', color: '#ef4444' },
  { key: 'setting', name: '系统设置', icon: '⚙️', color: '#64748b' },
]

export const HOME_BUILT = new Set(['hr', 'maintain', 'report', 'setting', 'oa', 'purchase', 'finance'])
export const HOME_META = {
  hr: { desc: '薪资核算 · 考勤管理 · 人员档案 · 预算管理 · 绩效考核', go: '进入人力资源 →' },
  maintain: { desc: '消防 / 电梯维保报表、维保台账与签约方维护', go: '进入工程维保 →' },
  report: { desc: '人力资源 · 薪酬 · 考勤三类分析报表总览', go: '进入报表中心 →' },
  setting: { desc: '账号权限 · 数据备份 · 工资规则 · 字段设置', go: '进入系统设置 →' },
  oa: { desc: '录用 / 转正 / 离职审批与入职办理，流程可配置', go: '进入审批中心 →' },
  purchase: { desc: '月度采购计划 · 填报 · 审批确认 · 预算执行 · 汇总导出', go: '进入采购系统 →' },
  finance: { desc: '应收费用台账 · 付款记录 · 汇总分析 · 驾驶舱', go: '进入财务管理 →' },
}

export const PAGE_TITLES = {
  home: '工作台主页',
  reportHome: '报表总览', hrReport: '人力资源报表', salaryReport: '薪酬报表', attReport: '考勤报表',
  fireReport: '消防维保报表', elevReport: '电梯维保报表',
  summary: '薪资数据汇总展示', payroll: '薪资核算与人工微调',
  taxMode: '个税扣除模式设置',
  attendance: '考勤管理', export: '多模式数据导出', org: '组织架构',
  staff: '人员档案管理', adjust: '调薪记录与追溯', budget: '项目薪资预算管理',
  maintFire: '消防维保台账', maintElev: '电梯维保台账', maintPartners: '签约方维护',
  perfCreate: '发起绩效考核', perfApprove: '待我审批', perfReport: '待我填报',
  perfMine: '我的考核', perfRecords: '考核记录管理',
  approvalCenter: '审批中心', purchaseEntry: '月度采购系统',
  purchaseDashboard: '数据驾驶舱', purchaseFill: '采购填报',
  purchaseOverview: '填报进度确认', purchaseCustoms: '清单外审核',
  purchaseBudget: '预算管理', purchaseSummary: '采购汇总查看',
  purchaseExportImport: '导出报价 / 导入存档', purchaseProducts: '标准商品库',
  purchaseWindows: '填报窗口设置', purchaseOplogs: '操作日志',
  purchaseMyItems: '我的填报记录',
  financeDashboard: '财务数据驾驶舱', financeLedger: '应收费用台账',
  financePayments: '付款记录', financeSummary: '汇总分析',
  financeImport: '历史数据导入',
  rules: '工资计算规则设置', symbols: '考勤符号库设置',
  users: '系统设置', logs: '系统设置', backup: '系统设置', settings: '系统设置',
}

export const ALL_MENUS = [
  { top: true, fixed: true, always: true, key: 'home', label: '工作台主页', icon: '🏠' },
  { section: '采购系统', icon: '📦', app: 'purchase', items: [
    { key: 'purchaseDashboard', label: '📊 数据驾驶舱', perm: 'purchase_view' },
    { key: 'purchaseFill', label: '📝 员工填报（代填）', staffLabel: '📝 采购填报', perm: 'purchase_view' },
    { key: 'purchaseOverview', label: '📋 填报进度确认', perm: 'purchase_view', adminOnly: true },
    { key: 'purchaseCustoms', label: '🧾 清单外审核', perm: 'purchase_view', adminOnly: true },
    { key: 'purchaseBudget', label: '💰 预算管理', perm: 'purchase_view', adminOnly: true },
    { key: 'purchaseSummary', label: '📈 采购汇总查看', perm: 'purchase_view', adminOnly: true },
    { key: 'purchaseExportImport', label: '📤 导出报价 / 导入存档', perm: 'purchase_view', adminOnly: true },
    { key: 'purchaseProducts', label: '📦 标准商品库', perm: 'purchase_view', adminOnly: true },
    { key: 'purchaseWindows', label: '📅 填报窗口设置', perm: 'purchase_view', adminOnly: true },
    { key: 'purchaseOplogs', label: '📜 操作日志', perm: 'purchase_view', adminOnly: true },
    { key: 'purchaseMyItems', label: '📋 我的填报记录', perm: 'purchase_view', staffOnly: true },
  ]},
  { section: '审批中心', icon: '✅', app: 'oa', items: [
    { key: 'approvalCenter', label: '📋 审批中心', perm: '' },
  ]},
  { section: '报表中心', icon: '📊', app: 'report', items: [
    { key: 'reportHome', label: '🏠 报表总览', perm: 'hr_report' },
    { key: 'hrReport', label: '👥 人力资源报表', perm: 'hr_report' },
    { key: 'salaryReport', label: '💰 薪酬报表', perm: 'salary_report' },
    { key: 'attReport', label: '📅 考勤报表', perm: 'attendance_report' },
  ]},
  { section: '薪资模块', icon: '💰', app: 'hr', items: [
    { key: 'summary', label: '📊 汇总展示', perm: 'payroll' },
    { key: 'payroll', label: '🧮 薪资核算与微调', perm: 'payroll' },
    { key: 'taxMode', label: '🧮 个税扣除模式设置', perm: 'payroll' },
    { key: 'export', label: '📤 数据导出', perm: 'export' },
  ]},
  { section: '考勤模块', icon: '📅', app: 'hr', items: [
    { key: 'attendance', label: '📅 考勤管理', perm: 'attendance' },
  ]},
  { section: '项目与人事模块', icon: '👥', app: 'hr', items: [
    { key: 'org', label: '🏢 组织架构', perm: 'projects' },
    { key: 'staff', label: '👥 人员档案', perm: 'staff' },
    { key: 'adjust', label: '📝 调薪与记录', perm: 'staff' },
  ]},
  { section: '预算模块', icon: '📊', app: 'hr', items: [
    { key: 'budget', label: '💰 预算管理', perm: 'budget' },
  ]},
  { section: '绩效考核', icon: '🎯', app: 'hr', items: [
    { key: 'perfCreate', label: '📝 发起考核', perm: 'perf' },
    { key: 'perfApprove', label: '✅ 待我审批', perm: 'perf' },
    { key: 'perfReport', label: '✍️ 待我填报', perm: 'perf' },
    { key: 'perfMine', label: '📋 我的考核', perm: 'perf' },
    { key: 'perfRecords', label: '🗂️ 考核记录管理', perm: 'perf_admin' },
  ]},
  { section: '财务管理', icon: '💰', app: 'finance', items: [
    { key: 'financeDashboard', label: '📊 数据驾驶舱', perm: 'finance_view' },
    { key: 'financeLedger', label: '📋 应收费用台账', perm: 'finance_view' },
    { key: 'financePayments', label: '💰 付款记录', perm: 'finance_view' },
    { key: 'financeSummary', label: '📈 汇总分析', perm: 'finance_view' },
    { key: 'financeImport', label: '📤 历史数据导入', perm: 'finance_admin' },
  ]},
  { section: '工程维保模块', icon: '🔧', app: 'maintain', items: [
    { key: 'fireReport', label: '🧯 消防维保报表', perm: 'maint_fire' },
    { key: 'elevReport', label: '🛗 电梯维保报表', perm: 'maint_elev' },
    { key: 'maintFire', label: '📒 消防维保台账', perm: 'maint_fire' },
    { key: 'maintElev', label: '📒 电梯维保台账', perm: 'maint_elev' },
    { key: 'maintPartners', label: '🤝 签约方维护', perm: 'maint_partners' },
  ]},
]

// 页面 key → 所属应用模块（复刻 nav() 的自动切换规则）
const PURCHASE_PAGES = ['purchaseDashboard', 'purchaseFill', 'purchaseOverview', 'purchaseCustoms',
  'purchaseBudget', 'purchaseSummary', 'purchaseExportImport', 'purchaseProducts',
  'purchaseWindows', 'purchaseOplogs', 'purchaseMyItems']
const FINANCE_PAGES = ['financeDashboard', 'financeLedger', 'financePayments', 'financeSummary', 'financeImport']
const REPORT_PAGES = ['reportHome', 'hrReport', 'salaryReport', 'attReport']
const MAINT_PAGES = ['fireReport', 'elevReport', 'maintFire', 'maintElev', 'maintPartners']
const HR_PAGES = ['summary', 'payroll', 'taxMode', 'attendance', 'org', 'staff', 'adjust', 'budget', 'export',
  'perfCreate', 'perfApprove', 'perfReport', 'perfMine', 'perfRecords']
const SETTING_PAGES = ['settings', 'users', 'logs', 'backup', 'salarySettings', 'rules', 'symbols']

export function appOfPage(key) {
  if (key === 'home' || !key) return 'home'
  if (PURCHASE_PAGES.includes(key)) return 'purchase'
  if (FINANCE_PAGES.includes(key)) return 'finance'
  if (REPORT_PAGES.includes(key)) return 'report'
  if (MAINT_PAGES.includes(key)) return 'maintain'
  if (HR_PAGES.includes(key)) return 'hr'
  if (SETTING_PAGES.includes(key)) return 'setting'
  return 'oa'
}

// 页面 key → 路由 path（采购页走 /purchase/ 前缀，其余复用旧 key）
const PURCHASE_PATH = {
  purchaseDashboard: '/purchase/dashboard',
  purchaseFill: '/purchase/fill',
  purchaseOverview: '/purchase/overview',
  purchaseCustoms: '/purchase/customs',
  purchaseBudget: '/purchase/budget',
  purchaseSummary: '/purchase/summary',
  purchaseExportImport: '/purchase/export-import',
  purchaseProducts: '/purchase/products',
  purchaseWindows: '/purchase/windows',
  purchaseOplogs: '/purchase/oplogs',
  purchaseMyItems: '/purchase/my-items',
}
export function pagePath(key) {
  if (!key || key === 'home') return '/'
  if (PURCHASE_PATH[key]) return PURCHASE_PATH[key]
  return '/' + key
}

// 路由 path → 页面 key（pagePath 的逆映射）
export function pageKeyOfPath(path) {
  if (path === '/' || !path) return 'home'
  for (const [key, p] of Object.entries(PURCHASE_PATH)) {
    if (p === path) return key
  }
  return path.replace(/^\//, '')
}

// 模块入口可用性（复刻 appEnabled 逻辑）
export function appEnabled(a, auth) {
  if (auth.user && auth.user.role === 'admin') return true
  if (a.key === 'purchase') return auth.can('purchase_view')
  if (a.key === 'finance') return auth.can('finance_view') || auth.can('finance_admin')
  if (a.key === 'report') return auth.can('hr_report') || auth.can('salary_report') || auth.can('attendance_report')
  const md = (auth.appModules || []).find((m) => m.key === a.key)
  return (md && md.perms || []).some((p) => auth.perms.includes(p[0]))
}

// launchApp 路由映射（复刻 app.js:340-354）
export const APP_ROUTE = {
  hr: '/summary',
  report: '/reportHome',
  maintain: '/fireReport',
  oa: '/approvalCenter',
  purchase: '/purchase/dashboard',
  finance: '/financeDashboard',
  setting: '/settings',
}

// 旧版 nav(page) 的对应物：按页面 key 跳转
export function goPage(key) {
  router.push(pagePath(key))
}
