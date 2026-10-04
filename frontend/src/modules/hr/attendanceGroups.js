// 考勤下拉框的跨项目虚拟选项：哨兵值必须与后端 PayrollCalculator::ATT_VIRTUAL_GROUPS 完全一致。
// 下载 = 全公司该类人员一张表（按项目分组）；上传 = 按人合并回各自项目考勤块。
export const VIRTUAL_ATT_GROUPS = [
  { value: '__managers__', label: '管理人员' },
  { value: '__case__', label: '案场人员' },
]

export function isVirtualAttGroup(value) {
  return VIRTUAL_ATT_GROUPS.some((g) => g.value === value)
}

export function virtualGroupLabel(value) {
  return VIRTUAL_ATT_GROUPS.find((g) => g.value === value)?.label || ''
}

/** 下拉显示名：虚拟选项用中文组名，真实项目用项目名 */
export function attProjectLabel(value) {
  return virtualGroupLabel(value) || value
}

/**
 * 查看/锁定/导出三个按钮的统一启用口径（2026-10-04 权限一致性修复）：
 * - 虚拟汇总选项：没有单一考勤块，始终禁用
 * - 具体项目当月无考勤块（未上传）：禁用，上传成功后才放行
 * - 已上传：返回空串表示放行
 * 返回非空串为禁用原因（同时用作按钮 title）。
 */
export function attBlockedAction({ isVirtual, hasData }) {
  if (isVirtual) return '汇总选项无单一考勤块，请选择具体项目后操作'
  if (!hasData) return '该项目本月尚未上传考勤数据，请先上传考勤表'
  return ''
}

/** 从 /api/attendance/status 的 projects 数组中取单项目状态；未命中按无数据处理 */
export function pickProjectStatus(list, project) {
  const found = Array.isArray(list) ? list.find((p) => p.project === project) : null
  return found || { has_data: false, locked: false }
}
