// 绩效导出（独立模块）前端参数与文案：纯函数，便于单测。
export const PERF_CATS = [
  { value: 'staff', label: '基层员工' },
  { value: 'manager', label: '管理人员' },
  { value: 'case', label: '案场人员' },
  { value: 'hq', label: '总部人员' },
]

export const PERF_METHODS = [
  { value: 'follow', label: '跟随人员配置（pay_rules）' },
  { value: 'monthly', label: '统一按月度绩效法' },
  { value: 'quarter', label: '统一按季度绩效法' },
  { value: 'installment', label: '统一按分期兑现绩效法' },
]

export const PERF_MODES = [
  { value: 'book', label: '一个工作簿（类别 Sheet + 汇总 + 说明）' },
  { value: 'summary', label: '仅汇总表' },
  { value: 'split', label: '按类别分别打包（zip）' },
]

export const DEFAULT_CATS = ['staff', 'manager', 'case', 'hq']

/** 当前月及之前最近的季度末月（1/4/7/10 月），返回 YYYY-MM */
export function latestQuarterEndMonth(now = new Date()) {
  const year = now.getFullYear()
  const month = now.getMonth() + 1
  const quarterEnds = [10, 7, 4, 1]
  const hit = quarterEnds.find((m) => m <= month)
  if (hit) return `${year}-${String(hit).padStart(2, '0')}`
  return `${year - 1}-10`
}

export function isQuarterEndMonth(ym) {
  if (!/^\d{4}-(0[1-9]|1[0-2])$/.test(String(ym || ''))) return false
  return [1, 4, 7, 10].includes(Number(String(ym).slice(5, 7)))
}

/** 所选发放法 / 类别是否需要季度末月才能导出 */
export function needsQuarterEnd(method, cats) {
  if (method === 'quarter' || method === 'installment') return true
  if (method === 'follow') {
    return (cats || []).some((c) => c === 'manager' || c === 'hq')
  }
  return false
}

export function toggleCat(list, key) {
  const current = Array.isArray(list) ? list : []
  return current.includes(key) ? current.filter((x) => x !== key) : [...current, key]
}

export function catLabel(value) {
  return (PERF_CATS.find((c) => c.value === value) || {}).label || value
}

export function methodLabel(value) {
  return (PERF_METHODS.find((m) => m.value === value) || {}).label || value
}

export function methodHint(method) {
  return {
    follow: '基层/案场=月度；管理=专员/主管月度、经理季度；总部=按档位（当前三档均为季度）。',
    monthly: '全部人员按当月出勤 × 月系数导出，季度列不出现。',
    quarter: '全部人员按季度末月结算，季度中月份不发放；需季度系数。',
    installment: '需季度系数、职级季度比例与半年度比例；7 月/1 月追加半年度部分。',
  }[method] || ''
}

/** 预览接口地址 */
export function perfPreviewUrl({ ym, cats, project = '', method = 'follow' }) {
  return `/api/export/performance-detail/preview?${perfExportQuery({ ym, cats, project, method })}`
}

/** 导出参数（project 为空/全部项目时不传） */
export function perfExportQuery({ ym, cats, project = '', method = 'follow', mode = 'book' }) {
  const params = new URLSearchParams()
  params.set('ym', ym)
  params.set('cats', (cats || []).join(','))
  params.set('method', method)
  params.set('mode', mode)
  if (project && project !== 'all') params.set('project', project)
  return params.toString()
}

/** 导出地址与下载兜底文件名 */
export function perfExportTarget({ ym, cats, project = '', method = 'follow', mode = 'book' }) {
  const query = perfExportQuery({ ym, cats, project, method, mode })
  const filename = mode === 'split'
    ? `绩效导出_${ym}_分类.zip`
    : mode === 'summary'
      ? `绩效导出_${ym}_仅汇总.xlsx`
      : `绩效导出_${ym}.xlsx`
  return { url: `/api/export/performance-detail?${query}`, filename }
}
