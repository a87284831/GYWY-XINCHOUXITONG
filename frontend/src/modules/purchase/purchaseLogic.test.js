import { describe, it, expect } from 'vitest'
import { prevMonth, sumRows, deltaInfo, rateText, topWindow, cumAmount, signedMoney, qs } from './purchaseLogic'

describe('prevMonth', () => {
  it('同年回退与跨年回退', () => {
    expect(prevMonth('2026-09')).toBe('2026-08')
    expect(prevMonth('2026-01')).toBe('2025-12')
  })
})

describe('sumRows（compare/yoy 汇总）', () => {
  it('求和 current/prev/diff', () => {
    const rows = [
      { current: 3, prev: 1, diff: 2 },
      { current: 2, prev: 4, diff: -2 },
    ]
    expect(sumRows(rows)).toEqual({ current: 5, prev: 5, diff: 0 })
  })
  it('空行安全', () => {
    expect(sumRows([])).toEqual({ current: 0, prev: 0, diff: 0 })
  })
})

describe('signedMoney / deltaInfo（红涨绿跌 KPI 文案）', () => {
  it('signedMoney 千分位带符号', () => {
    expect(signedMoney(1234.5)).toBe('+1,234.50')
    expect(signedMoney(-1234.5)).toBe('-1,234.50')
    expect(signedMoney(0)).toBe('±0.00')
  })
  it('deltaInfo 上月无数据', () => {
    expect(deltaInfo(5, 0)).toEqual({ text: '上月无数据', up: null })
    expect(deltaInfo(5, null)).toEqual({ text: '上月无数据', up: null })
  })
  it('deltaInfo 涨跌平', () => {
    expect(deltaInfo(5, 3)).toEqual({ text: '较上月 +2.00 元', up: true })
    expect(deltaInfo(3, 5)).toEqual({ text: '较上月 -2.00 元', up: false })
    expect(deltaInfo(3, 3)).toEqual({ text: '较上月 ±0.00 元', up: null })
  })
})

describe('rateText / topWindow', () => {
  it('rateText null → —，数值保留 1 位 %', () => {
    expect(rateText(null)).toBe('—')
    expect(rateText(85.3)).toBe('85.3%')
    expect(rateText(12.25)).toBe('12.3%')
  })
  it('topWindow 区间文案', () => {
    expect(topWindow({ start: '2026-07', end: '2026-09' })).toBe('2026-07 ~ 2026-09')
  })
})

describe('cumAmount（年度累计线）', () => {
  it('逐月累计并舍入', () => {
    const rows = [{ month: '2026-01', amount: 0.1 }, { month: '2026-02', amount: 0.2 }]
    expect(cumAmount(rows)).toEqual([
      { month: '2026-01', amount: 0.1, cum: 0.1 },
      { month: '2026-02', amount: 0.2, cum: 0.3 },
    ])
  })
})

describe('qs（query 拼接）', () => {
  it('拼接并跳过空值', () => {
    expect(qs({ month: '2026-09', year: undefined, item: null, kw: '' })).toBe('?month=2026-09')
    expect(qs({ item: 'A4纸', year: 2026 })).toBe('?item=A4%E7%BA%B8&year=2026')
  })
  it('无有效参数返回空串', () => {
    expect(qs({})).toBe('')
    expect(qs(null)).toBe('')
  })
})
