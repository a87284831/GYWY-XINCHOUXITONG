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
