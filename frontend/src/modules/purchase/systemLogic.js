// 采购系统设置/导出导入/我的填报记录 纯逻辑——复刻旧 bundle：
// SystemWindows-6IyQWznm.js / ExportImport-BaYMbdsW.js / MyItems-CqDP3Efw.js

import { moneyOrDash } from '@/utils/format'

// 窗口「当前」状态（旧版客户端按本地今日计算）：
// status=1 且今日在[start,end]→open；今日<start→future（不看 status）；否则 closed
export function windowState(w, today) {
  if (w.status == 1 && today >= w.start_date && today <= w.end_date) return 'open'
  if (today < w.start_date) return 'future'
  return 'closed'
}

// 导出文件名（逐字复刻）
export function exportMonthName(month) {
  return '广盈物业_' + month + '_采购计划统计表.xlsx'
}
export function exportYearName(year) {
  return '广盈物业_' + year + '年度采购明细汇总.xlsx'
}

// 超预算项目一键退回的原因模板
export function overBudgetReason(actual, budget) {
  return '该月采购金额 ¥' + moneyOrDash(actual) + ' 超过预算 ¥' + moneyOrDash(budget) +
    '，请在带价格的条目上调整数量或删除后重新提交'
}

// 批量导入结果计数
export function batchSummary(files) {
  const success = files.filter((f) => f.ok).length
  return { success, fail: files.length - success }
}

// 我的填报记录：年份列表（数据年份 + 当前年，去重降序）
export function myYearList(currentYear, rows) {
  const years = [currentYear]
  rows.forEach((r) => {
    const y = Number(r.month.slice(0, 4))
    if (!years.includes(y)) years.push(y)
  })
  return years.sort((a, b) => b - a)
}

// 月份卡片角标：退回 > 已出价 > 已确认 > 填报中 > 未填报
export function monthCardTag(card) {
  if (card.returned_count > 0) return { text: card.returned_count + ' 条被退回', type: 'danger' }
  if (card.imported) return { text: '已出价', type: 'success' }
  if (card.confirmed_count > 0) return { text: '已确认', type: 'primary' }
  if (card.item_count > 0) return { text: '填报中', type: 'warning' }
  return { text: '未填报', type: 'info' }
}
