import { describe, it, expect } from 'vitest'
import {
  isQuarterEndMonth, isHalfYearEndMonth, periodKeys, coefEntryLabel,
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
    expect(coefEntryLabel('2026-04')).toBe('录入 2026-Q1 季度系数')
  })
  it('半年度末月显示季度+半年度', () => {
    expect(coefEntryLabel('2026-07')).toBe('录入 2026-Q2 季度 + 2026-H1 半年度系数')
  })
  it('非季度末月返回空串（按钮不显示）', () => {
    expect(coefEntryLabel('2026-05')).toBe('')
  })
})
