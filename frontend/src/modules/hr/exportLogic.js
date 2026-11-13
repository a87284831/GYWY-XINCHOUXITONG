// 分项目工资导出：项目 + 人员类型 统一入口（2026-10-06 交互整合，问题6）
// 三个接口均已上线，本层只做下拉选项、权限可见性与 URL/文件名拼装：
//   staff   → /api/export/project            （基层员工工资表）
//   case    → /api/export/project-case       （案场人员工资表；项目账号限本项目由后端强制）
//   manager → /api/export/project-managers   （管理人员工资表；仅总部 admin 可见/可调）
export const PROJECT_EXPORT_TYPES = [
  { value: 'staff', label: '基层员工工资表', filePart: '工资表' },
  { value: 'case', label: '案场人员工资表', filePart: '案场人员工资表' },
  { value: 'manager', label: '管理人员工资表', filePart: '管理人员工资表', adminOnly: true },
]

export function visibleProjectExportTypes(isAdmin) {
  return PROJECT_EXPORT_TYPES.filter((t) => !t.adminOnly || !!isAdmin)
}

export function projectExportTarget(type, ym, project, isAdmin = true) {
  const conf = PROJECT_EXPORT_TYPES.find((t) => t.value === type)
  if (!conf) throw new Error('导出人员类型错误')
  if (conf.adminOnly && !isAdmin) throw new Error('管理人员工资仅总部可导出，无权限')
  const endpoints = {
    staff: '/api/export/project',
    case: '/api/export/project-case',
    manager: '/api/export/project-managers',
  }
  const p = encodeURIComponent(project || '')
  return {
    url: `${endpoints[type]}?ym=${ym}&project=${p}`,
    filename: `${project}_${conf.filePart}_${ym}.xlsx`,
  }
}

// 数据导出 → 考勤导出：项目/人员类型作为筛选条件组合使用（不是独立导出项）
export const ATTENDANCE_EXPORT_TYPES = [
  { value: 'all', label: '全部人员' },
  { value: 'manager', label: '管理人员' },
  { value: 'case', label: '案场人员' },
]

// merged = 全部项目合并一张表；bundle = 全量数据包（每项目一张 Sheet，项目固定全部项目）
export const ATTENDANCE_EXPORT_MODES = {
  merged: { prefix: '考勤导出' },
  bundle: { prefix: '考勤全量数据包' },
}

export function attendanceExportTarget({ ym, project = 'all', personType = 'all', mode = 'merged', isProjectScope = false }) {
  const type = ATTENDANCE_EXPORT_TYPES.find((t) => t.value === personType)
  if (!type) throw new Error('人员类型错误')
  const conf = ATTENDANCE_EXPORT_MODES[mode]
  if (!conf) throw new Error('打包方式错误')
  // 全量数据包：总部固定全部项目；项目账号仍只能导本项目
  const scope = mode === 'bundle' ? (isProjectScope ? project : 'all') : (project || 'all')
  const params = new URLSearchParams({ ym, project: scope, person_type: personType, mode })
  const scopePart = scope === 'all' ? '全部项目' : scope
  return {
    previewUrl: `/api/export/attendance?${params.toString()}&dry_run=1`,
    url: `/api/export/attendance?${params.toString()}`,
    filename: `${conf.prefix}_${scopePart}_${type.label}_${ym}.xlsx`,
  }
}

