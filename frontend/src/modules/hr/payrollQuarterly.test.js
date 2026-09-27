import { describe, it, expect } from 'vitest'
import {
  isQuarterEndMonth, isHalfYearEndMonth, periodKeys, coefEntryLabel,
  perfDetailSubRows, exportPerfDetailCols,
} from './payrollLogic'

describe('isQuarterEndMonth（季度末月判断）', () => {
  it('4/7/10/1 月为季度末', () => {
    expect(isQuarterEndMonth('2026-04')).toBe(true)
    expect(isQuarterEndMonth('2026-07')).toBe(true)
    expect(isQuarterEndMonth('2026-10')).toBe(true)
    expect(isQuarterEndMonth('2027-01')).toBe(true)
  })
  it('其他月份不是季度末', () => {
    expect(isQuarterEndMonth('2026-03')).toBe(false)
    expect(isQuarterEndMonth('2026-05')).toBe(false)
    expect(isQuarterEndMonth('2026-12')).toBe(false)
  })
})

describe('periodKeys（周期标识，1月归上年）', () => {
  it('4月 → 当年Q1，无半年度', () => {
    expect(periodKeys('2026-04')).toEqual({ q: '2026-Q1', h: null })
  })
  it('7月 → 当年Q2 + H1', () => {
    expect(periodKeys('2026-07')).toEqual({ q: '2026-Q2', h: '2026-H1' })
  })
  it('10月 → 当年Q3', () => {
    expect(periodKeys('2026-10')).toEqual({ q: '2026-Q3', h: null })
  })
  it('1月 → 上年Q4 + 上年H2', () => {
    expect(periodKeys('2027-01')).toEqual({ q: '2026-Q4', h: '2026-H2' })
  })
  it('非季度末月 → 空', () => {
    expect(periodKeys('2026-05')).toEqual({ q: null, h: null })
  })
})

describe('coefEntryLabel（录入按钮文案）', () => {
  it('纯季度末月只显示季度', () => {
    expect(coefEntryLabel('2026-04')).toBe('📊 录入 2026-Q1 季度系数')
  })
  it('半年度末月显示季度+半年度', () => {
    expect(coefEntryLabel('2026-07')).toBe('📊 录入 2026-Q2 季度 + 2026-H1 半年度系数')
  })
  it('非季度末月返回空串（按钮不显示）', () => {
    expect(coefEntryLabel('2026-05')).toBe('')
  })
})

describe('perfDetailSubRows（工资表逐月绩效子行数据）', () => {
  const row = {
    name: '张三', perf_pay: 2850,
    perf_detail: {
      period: '2026-Q1', type: 'quarterly', ratio: 0.95, coef: 1.0,
      months: [
        { ym: '2026-01', perf_att: 22, base: 1000, amount: 1000 },
        { ym: '2026-02', perf_att: 22, base: 1000, amount: 1000 },
        { ym: '2026-03', perf_att: 22, base: 1000, amount: 1000 },
      ],
    },
  }
  it('有 perf_detail 时生成逐月子行', () => {
    const subs = perfDetailSubRows(row)
    expect(subs).toHaveLength(3)
    expect(subs[0].label).toBe('└─ 1月绩效')
    expect(subs[0].amount).toBe(1000)
    expect(subs[2].label).toBe('└─ 3月绩效')
  })
  it('无 perf_detail 时返回空数组', () => {
    expect(perfDetailSubRows({ name: '李四', perf_pay: 500 })).toEqual([])
    expect(perfDetailSubRows({ name: '王五', perf_detail: null })).toEqual([])
  })
  it('缺系数错误行不生成子行', () => {
    expect(perfDetailSubRows({ perf_detail: { error: 'missing_coef', period: '2026-Q1' } })).toEqual([])
  })
  it('半年度末同时含 half_year 明细时追加半年度子行', () => {
    const r = {
      ...row,
      perf_detail: {
        ...row.perf_detail,
        half_year: { period: '2026-H1', ratio: 0.05, coef: 1.0, months: [
          { ym: '2026-01', perf_att: 22, base: 1000, amount: 1000 },
          { ym: '2026-02', perf_att: 22, base: 1000, amount: 1000 },
        ] },
      },
    }
    const subs = perfDetailSubRows(r)
    expect(subs).toHaveLength(5)
    expect(subs[3].label).toBe('└─ [H1] 1月绩效')
  })
})

describe('exportPerfDetailCols（导出追加逐月绩效列）', () => {
  it('季度末有明细的行返回各月金额', () => {
    const row = {
      perf_detail: {
        months: [
          { ym: '2026-01', amount: 1000 },
          { ym: '2026-02', amount: 900 },
          { ym: '2026-03', amount: 800 },
        ],
      },
    }
    expect(exportPerfDetailCols(row)).toEqual({ '2026-01': 1000, '2026-02': 900, '2026-03': 800 })
  })
  it('无明细返回空对象', () => {
    expect(exportPerfDetailCols({ perf_pay: 100 })).toEqual({})
  })
  it('缺系数错误行返回空对象', () => {
    expect(exportPerfDetailCols({ perf_detail: { error: 'missing_coef' } })).toEqual({})
  })
})
