import { describe, it, expect } from 'vitest'
import {
  isQuarterEndMonth, isHalfYearEndMonth, periodKeys, coefEntryLabel,
  perfDetailCols, perfDetailCell,
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

describe('perfDetailCols（工资表横向逐月绩效列）', () => {
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
  it('有 perf_detail 的行生成 Q1 三列，列头与 Excel 导出一致', () => {
    const cols = perfDetailCols([row])
    expect(cols).toHaveLength(3)
    expect(cols.map((c) => c.key)).toEqual(['Q1|2026-01', 'Q1|2026-02', 'Q1|2026-03'])
    expect(cols[0].label).toBe('Q1·1月绩效')
    expect(cols[2].label).toBe('Q1·3月绩效')
  })
  it('无明细/缺系数错误的行不生成列', () => {
    expect(perfDetailCols([{ name: '李四', perf_pay: 500 }])).toEqual([])
    expect(perfDetailCols([{ perf_detail: null }])).toEqual([])
    expect(perfDetailCols([{ perf_detail: { error: 'missing_coef', period: '2026-Q1' } }])).toEqual([])
  })
  it('半年度末同时追加 H1 列；同月 Q 在前 H 在后（与导出排序一致）', () => {
    const r = {
      ...row,
      perf_detail: {
        ...row.perf_detail,
        half_year: { period: '2026-H1', ratio: 0.05, coef: 1.0, months: [
          { ym: '2026-02', perf_att: 22, base: 1000, amount: 1000 },
          { ym: '2026-03', perf_att: 22, base: 1000, amount: 1000 },
          { ym: '2026-04', perf_att: 22, base: 1000, amount: 1000 },
          { ym: '2026-05', perf_att: 22, base: 1000, amount: 1000 },
          { ym: '2026-06', perf_att: 22, base: 1000, amount: 1000 },
        ] },
      },
    }
    const cols = perfDetailCols([r])
    // Q1: 1/2/3月 + H1: 2/3/4/5/6月（同月 Q 去重在前、H 独立键）
    expect(cols.map((c) => c.key)).toEqual([
      'Q1|2026-01', 'Q1|2026-02', 'H1|2026-02', 'Q1|2026-03', 'H1|2026-03',
      'H1|2026-04', 'H1|2026-05', 'H1|2026-06',
    ])
    expect(cols[3].label).toBe('Q1·3月绩效')
    expect(cols[5].label).toBe('H1·4月绩效')
  })
  it('多行月份并集去重（有人缺某月明细也生成完整列）', () => {
    const r2 = {
      name: '李四',
      perf_detail: { period: '2026-Q1', months: [
        { ym: '2026-01', amount: 800 }, { ym: '2026-02', amount: 800 }, { ym: '2026-03', amount: 800 },
      ] },
    }
    const cols = perfDetailCols([row, r2])
    expect(cols).toHaveLength(3) // 同键合并，不重复
  })
})

describe('perfDetailCell（横向明细单元格金额）', () => {
  const row = {
    perf_detail: {
      period: '2026-Q1', coef: 1.0, ratio: 0.95,
      months: [
        { ym: '2026-01', amount: 1000 },
        { ym: '2026-02', amount: 900 },
        { ym: '2026-03', amount: 800 },
      ],
    },
  }
  it('按键取对应月份金额', () => {
    expect(perfDetailCell(row, 'Q1|2026-01')).toBe(1000)
    expect(perfDetailCell(row, 'Q1|2026-03')).toBe(800)
  })
  it('无该月明细返回 null（页面显示空）', () => {
    expect(perfDetailCell({ perf_pay: 100 }, 'Q1|2026-01')).toBeNull()
    expect(perfDetailCell(row, 'Q1|2026-12')).toBeNull()
    expect(perfDetailCell({}, 'Q1|2026-01')).toBeNull()
  })
  it('缺系数错误行返回 null', () => {
    expect(perfDetailCell({ perf_detail: { error: 'missing_coef' } }, 'Q1|2026-01')).toBeNull()
  })
  it('H 键从 half_year.months 取值，无 half_year 时返回 null', () => {
    const r = { ...row, perf_detail: { ...row.perf_detail, half_year: { period: '2026-H1', months: [{ ym: '2026-02', amount: 50 }] } } }
    expect(perfDetailCell(r, 'H1|2026-02')).toBe(50)
    expect(perfDetailCell(row, 'H1|2026-02')).toBeNull()
  })
})
