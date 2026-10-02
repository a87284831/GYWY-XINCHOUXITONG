import { describe, it, expect } from 'vitest'
import { lineTotals, budgetCounts, unboundSummary, aliasList } from './summaryLogic'

describe('lineTotals（矩阵按条线合计）', () => {
  it('逐条线求和并跳过 null 单元', () => {
    const matrix = [
      { lines: { 环境: { amount: 100.1, cnt: 2 }, 绿化: null, 工程: { amount: 50, cnt: 1 } } },
      { lines: { 环境: { amount: 0.4, cnt: 1 }, 工程: { amount: 25.6, cnt: 3 } } },
    ]
    expect(lineTotals(matrix, ['环境', '绿化', '工程'])).toEqual({ 环境: 100.5, 绿化: 0, 工程: 75.6 })
  })
  it('空矩阵安全', () => {
    expect(lineTotals([], ['环境'])).toEqual({ 环境: 0 })
    expect(lineTotals(null, null)).toEqual({})
  })
})

describe('budgetCounts', () => {
  it('超支/预算内计数', () => {
    expect(budgetCounts([{ over: true }, { over: false }, { over: true }])).toEqual({ over: 2, within: 1 })
    expect(budgetCounts([])).toEqual({ over: 0, within: 0 })
  })
})

describe('unboundSummary', () => {
  it('汇总行数/种类/可绑定/缺失行', () => {
    const u = {
      total_rows: 10,
      total_amount: 1234.5,
      data: [
        { item_name: 'A', rows: 4, bindable: true },
        { item_name: 'B', rows: 6, bindable: false },
      ],
    }
    expect(unboundSummary(u)).toEqual({ rows: 10, kinds: 2, amount: 1234.5, bindableKinds: 1, missingRows: 6 })
  })
  it('空数据安全', () => {
    expect(unboundSummary(null)).toEqual({ rows: 0, kinds: 0, amount: null, bindableKinds: 0, missingRows: 0 })
  })
})

describe('aliasList', () => {
  it('竖线拆分并过滤空段', () => {
    expect(aliasList('A4|复印纸|')).toEqual(['A4', '复印纸'])
    expect(aliasList('')).toEqual([])
    expect(aliasList(null)).toEqual([])
  })
})
