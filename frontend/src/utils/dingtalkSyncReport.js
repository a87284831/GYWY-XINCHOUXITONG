// 钉钉同步结果弹窗的展示模型（纯函数，便于单测）：
// 把后端 report 转成 标题/口径/统计行/警告行；字段异常时同步仍算成功，只是橙色警告。

export function syncReportModel(rep) {
  const r = rep || {}
  const items = Array.isArray(r.roster_field_error_items) ? r.roster_field_error_items : []
  const warningCount = Number(r.roster_field_errors ?? items.length) || 0
  const missing = Number(r.roster_missing ?? 0) || 0

  const rows = [
    ['钉钉部门数', r.dingtalk_depts ?? 0],
    ['钉钉在职人员', r.dingtalk_users ?? 0],
    ['钉钉离职人员', r.dingtalk_dismissed ?? 0],
    ['新增人员', r.new ?? 0],
    ['更新人员', r.updated ?? 0],
    ['标记离职', r.offboard ?? 0],
    ['新增离职', r.offboard_new ?? 0],
    ['花名册同步', r.roster ?? 0],
    ['花名册字段异常', warningCount],
    ['花名册缺失', missing],
  ]

  const shown = items.slice(0, 10)
  const truncated = items.length > shown.length ? items.length - shown.length : 0

  return {
    hasWarning: warningCount > 0 || missing > 0,
    title: warningCount > 0
      ? `同步完成（${warningCount} 项花名册字段异常，已跳过保留原值）`
      : '同步完成',
    rows,
    warningLines: shown.map((i) => `· ${i.name}：${i.detail}`),
    warningTruncated: truncated,
    missingCount: missing,
  }
}
