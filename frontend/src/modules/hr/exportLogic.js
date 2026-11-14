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

/**
 * 导出项与数据范围的唯一收口：页面所有「哪一项可用 / 参数取哪个项目」都从这里取，
 * 避免拆分卡片后各卡片各写一套判断（曾因只写在筛选区联动里而漏掉）。
 *
 * - scope=project 时：项目维度一律锁定为本项目，不出现全部项目选项；
 *   全公司类导出项（管理人员/案场/总部）不可用，与后端 403 保持一致。
 * - scope=all：项目可任选，全公司类导出项仅 admin 角色可用。
 */
export function exportCapabilities({
  isAdmin = false,
  isProjectScope = false,
  userProject = '',
  projects = [],
  project = '',
} = {}) {
  const forced = isProjectScope ? String(userProject || '') : ''
  const salaryProjects = isProjectScope ? (forced ? [forced] : []) : projects.filter((p) => p !== '物业总部')
  const attendanceProjects = isProjectScope ? (forced ? [forced] : []) : projects.slice()
  return {
    isAdmin: !!isAdmin,
    isProjectScope: !!isProjectScope,
    forcedProject: forced,
    scopeLabel: isProjectScope ? `仅本项目（${forced || '未绑定项目'}）` : '全部项目',
    salaryProjects,
    attendanceProjects,
    // 选中的项目在项目账号下必须被本项目覆盖，页面与请求参数都以此为准
    project: forced || project || salaryProjects[0] || '',
    defaultAttendanceProject: forced || 'all',
    projectTypes: visibleProjectExportTypes(isAdmin),
    canExportCompanyWide: !!isAdmin && !isProjectScope,
  }
}

export function projectExportTarget(type, ym, project, isAdmin = true, isProjectScope = false, userProject = '') {
  const conf = PROJECT_EXPORT_TYPES.find((t) => t.value === type)
  if (!conf) throw new Error('导出人员类型错误')
  if (conf.adminOnly && !isAdmin) throw new Error('管理人员工资仅总部可导出，无权限')
  // 项目账号不能通过传参越权：以账号绑定项目覆盖页面选择
  const resolved = isProjectScope ? String(userProject || '') : String(project || '')
  if (!resolved) throw new Error('项目账号未绑定项目，无法导出')
  const endpoints = {
    staff: '/api/export/project',
    case: '/api/export/project-case',
    manager: '/api/export/project-managers',
  }
  const p = encodeURIComponent(resolved)
  return {
    url: `${endpoints[type]}?ym=${ym}&project=${p}`,
    filename: `${resolved}_${conf.filePart}_${ym}.xlsx`,
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
  if (isProjectScope && !scope) throw new Error('项目账号未绑定项目，无法导出')
  const params = new URLSearchParams({ ym, project: scope, person_type: personType, mode })
  const scopePart = scope === 'all' ? '全部项目' : scope
  return {
    previewUrl: `/api/export/attendance?${params.toString()}&dry_run=1`,
    url: `/api/export/attendance?${params.toString()}`,
    filename: `${conf.prefix}_${scopePart}_${type.label}_${ym}.xlsx`,
  }
}

