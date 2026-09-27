import { describe, it, expect } from 'vitest'
import {
  windowState,
  exportMonthName,
  exportYearName,
  overBudgetReason,
  batchSummary,
  myYearList,
  monthCardTag,
} from './systemLogic'

describe('systemLogic', () => {
  it('windowState: status=1 且今日在[start,end]→open；今日<start→future（不论 status）；否则 closed', () => {
    const w = { start_date: '2026-09-20', end_date: '2026-09-23', status: 1 }
    expect(windowState(w, '2026-09-19')).toBe('future')
    expect(windowState(w, '2026-09-20')).toBe('open') // 边界含首日
    expect(windowState(w, '2026-09-23')).toBe('open') // 边界含末日
    expect(windowState(w, '2026-09-24')).toBe('closed')
    // 停用窗口落在区间内也不是进行中
    expect(windowState({ ...w, status: 0 }, '2026-09-21')).toBe('closed')
    // 未开始判定不看 status
    expect(windowState({ ...w, status: 0 }, '2026-09-01')).toBe('future')
  })

  it('导出文件名逐字复刻', () => {
    expect(exportMonthName('2026-09')).toBe('广盈物业_2026-09_采购计划统计表.xlsx')
    expect(exportYearName(2026)).toBe('广盈物业_2026年度采购明细汇总.xlsx')
  })

  it('overBudgetReason: 退回原因模板（金额千分位 2 位）', () => {
    expect(overBudgetReason(1234.5, 1000)).toBe(
      '该月采购金额 ¥1,234.50 超过预算 ¥1,000.00，请在带价格的条目上调整数量或删除后重新提交'
    )
  })

  it('batchSummary: 成功/失败计数', () => {
    expect(batchSummary([{ ok: true }, { ok: false }, { ok: true }])).toEqual({ success: 2, fail: 1 })
    expect(batchSummary([])).toEqual({ success: 0, fail: 0 })
  })

  it('myYearList: 数据行年份并入当前年、去重降序', () => {
    const rows = [{ month: '2025-11' }, { month: '2024-03' }, { month: '2025-01' }]
    expect(myYearList(2026, rows)).toEqual([2026, 2025, 2024])
  })

  it('monthCardTag: 退回>已出价>已确认>填报中>未填报 优先级', () => {
    expect(monthCardTag({ returned_count: 1 })).toMatchObject({ text: '1 条被退回', type: 'danger' })
    expect(monthCardTag({ returned_count: 0, imported: true })).toMatchObject({ text: '已出价', type: 'success' })
    expect(monthCardTag({ returned_count: 0, imported: false, confirmed_count: 2 })).toMatchObject({ text: '已确认', type: 'primary' })
    expect(monthCardTag({ returned_count: 0, imported: false, confirmed_count: 0, item_count: 3 })).toMatchObject({ text: '填报中', type: 'warning' })
    expect(monthCardTag({ returned_count: 0, imported: false, confirmed_count: 0, item_count: 0 })).toMatchObject({ text: '未填报', type: 'info' })
  })
})
