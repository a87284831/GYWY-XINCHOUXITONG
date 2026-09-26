// 绩效纯逻辑测试 — 对照 app.js:5015-5033, 5624-5633, 5093-5097, 5186-5187
import { describe, it, expect } from 'vitest'
import {
  perfNum, perfFmt, perfCalcItem, perfWeightSum, mixFinal,
  perfApId, defaultPeriod, defaultCalcParams,
} from './perfLogic'

describe('perfNum', () => {
  it('空串/null/undefined/非数字 → null', () => {
    expect(perfNum('')).toBe(null)
    expect(perfNum(null)).toBe(null)
    expect(perfNum(undefined)).toBe(null)
    expect(perfNum('abc')).toBe(null)
  })
  it('数字与数字字符串正常转换，0 不算空', () => {
    expect(perfNum('12.5')).toBe(12.5)
    expect(perfNum(0)).toBe(0)
    expect(perfNum('0')).toBe(0)
  })
})

describe('perfFmt', () => {
  it('空值 → "-"', () => {
    expect(perfFmt(null)).toBe('-')
    expect(perfFmt('')).toBe('-')
    expect(perfFmt(undefined)).toBe('-')
  })
  it('去掉多余的 0', () => {
    expect(perfFmt(16)).toBe('16')
    expect(perfFmt(16.5)).toBe('16.5')
    expect(perfFmt(16.25)).toBe('16.25')
    expect(perfFmt('16.00')).toBe('16')
    expect(perfFmt(10.1)).toBe('10.1')
    expect(perfFmt(0)).toBe('0')
  })
})

describe('perfCalcItem（与后端一致的算分预览）', () => {
  it('manual 或无实际值 → null', () => {
    expect(perfCalcItem({ calcType: 'manual', weight: 10, calcParams: {} })).toBe(null)
    expect(perfCalcItem({ calcType: 'ratio', weight: 10, actualValue: '', calcParams: { target: 100 } })).toBe(null)
    expect(perfCalcItem({ calcType: 'ratio', weight: 10, calcParams: { target: 100 } })).toBe(null)
  })
  it('ratio：得分=权重×(实际÷目标)，封顶权重、最低 0', () => {
    expect(perfCalcItem({ calcType: 'ratio', weight: 20, actualValue: 80, calcParams: { target: 100 } })).toBe(16)
    expect(perfCalcItem({ calcType: 'ratio', weight: 20, actualValue: 120, calcParams: { target: 100 } })).toBe(20)
    expect(perfCalcItem({ calcType: 'ratio', weight: 20, actualValue: -5, calcParams: { target: 100 } })).toBe(0)
    expect(perfCalcItem({ calcType: 'ratio', weight: 20, actualValue: 80, calcParams: { target: 0 } })).toBe(0)
  })
  it('ladder：每差一个阶梯单位扣分，低于记0阈值整项 0 分', () => {
    const p = { target: 100, stepUnit: 5, stepDeduct: 2, zeroThreshold: 80 }
    expect(perfCalcItem({ calcType: 'ladder', weight: 10, actualValue: 90, calcParams: p })).toBe(6)
    expect(perfCalcItem({ calcType: 'ladder', weight: 10, actualValue: 100, calcParams: p })).toBe(10)
    expect(perfCalcItem({ calcType: 'ladder', weight: 10, actualValue: 150, calcParams: p })).toBe(10)
    expect(perfCalcItem({ calcType: 'ladder', weight: 10, actualValue: 79, calcParams: p })).toBe(0)
    // 无 zeroThreshold 不触发记 0
    const p2 = { target: 100, stepUnit: 1, stepDeduct: 2 }
    expect(perfCalcItem({ calcType: 'ladder', weight: 10, actualValue: 50, calcParams: p2 })).toBe(0)
    expect(perfCalcItem({ calcType: 'ladder', weight: 10, actualValue: 96, calcParams: p2 })).toBe(2)
  })
  it('count：每少完成 1 个单位扣指定分数', () => {
    const p = { required: 6, deductEach: 1 }
    expect(perfCalcItem({ calcType: 'count', weight: 10, actualValue: 4, calcParams: p })).toBe(8)
    expect(perfCalcItem({ calcType: 'count', weight: 10, actualValue: 6, calcParams: p })).toBe(10)
    expect(perfCalcItem({ calcType: 'count', weight: 10, actualValue: 9, calcParams: p })).toBe(10)
    expect(perfCalcItem({ calcType: 'count', weight: 10, actualValue: 0, calcParams: p })).toBe(4)
    // 超扣下限 0
    expect(perfCalcItem({ calcType: 'count', weight: 3, actualValue: 0, calcParams: { required: 10, deductEach: 2 } })).toBe(0)
  })
})

describe('perfWeightSum', () => {
  it('空草稿 → 0', () => {
    expect(perfWeightSum({})).toBe(0)
    expect(perfWeightSum({ categories: [] })).toBe(0)
  })
  it('跳过未填权重项，浮点合计精确到 2 位', () => {
    const d = { categories: [
      { items: [{ weight: '20' }, { weight: '' }, { weight: 30.5 }] },
      { items: [{ weight: 49.6 }] },
    ] }
    expect(perfWeightSum(d)).toBe(100.1)
    expect(perfWeightSum({ categories: [{ items: [{ weight: 0.1 }, { weight: 0.2 }] }] })).toBe(0.3)
  })
})

describe('mixFinal（审批阶段最终分实时预览，app.js perfMixFinal）', () => {
  const sw = { self: 30, approver: 70 }
  it('自评+考核分都有 → 按占比混合', () => {
    expect(mixFinal(10, 20, sw)).toBe(17)
    expect(mixFinal(85.5, 90, { self: 50, approver: 50 })).toBe(87.75)
  })
  it('自评为空 → 直接取考核人评分', () => {
    expect(mixFinal(null, 20, sw)).toBe(20)
  })
  it('考核人评分为空 → null', () => {
    expect(mixFinal(10, null, sw)).toBe(null)
  })
})

describe('perfApId', () => {
  it('空 → 0；staffId 优先；缺 staffId 用 userId', () => {
    expect(perfApId(null)).toBe(0)
    expect(perfApId({ staffId: '5', userId: 9 })).toBe(5)
    expect(perfApId({ userId: 9 })).toBe(9)
    expect(perfApId({ staffId: 0 })).toBe(0)
  })
})

describe('defaultPeriod（默认本季度起止）', () => {
  it('9月 → Q3: 07-01 ~ 09-30', () => {
    expect(defaultPeriod(new Date(2026, 8, 26))).toEqual({ start: '2026-07-01', end: '2026-09-30' })
  })
  it('1月 → Q1: 01-01 ~ 03-30（复刻旧逻辑非闰年 30 日）', () => {
    expect(defaultPeriod(new Date(2026, 0, 15))).toEqual({ start: '2026-01-01', end: '2026-03-30' })
  })
  it('12月 → Q4: 10-01 ~ 12-31', () => {
    expect(defaultPeriod(new Date(2026, 11, 1))).toEqual({ start: '2026-10-01', end: '2026-12-31' })
  })
})

describe('defaultCalcParams（切换算分方式时的默认参数）', () => {
  it('四类默认参数', () => {
    expect(defaultCalcParams('ratio')).toEqual({ target: 100 })
    expect(defaultCalcParams('ladder')).toEqual({ target: 100, stepUnit: 1, stepDeduct: 5, zeroThreshold: 80 })
    expect(defaultCalcParams('count')).toEqual({ required: 6, deductEach: 1 })
    expect(defaultCalcParams('manual')).toEqual({})
  })
})
