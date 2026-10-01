import { describe, it, expect } from 'vitest'
import { money, pct, fmtEmpty } from './format'

describe('money（千分位 2 位小数）', () => {
  it('正数千分位格式化', () => {
    expect(money(1234.5)).toBe('1,234.50')
  })
  it('0 显示为 0.00', () => {
    expect(money(0)).toBe('0.00')
  })
})

describe('pct（百分比 2 位）', () => {
  it('小数转百分比', () => {
    expect(pct(0.1234)).toBe('12.34%')
  })
})

describe('fmtEmpty（空值占位符）', () => {
  it('null → —', () => {
    expect(fmtEmpty(null)).toBe('—')
  })
  it('undefined → —', () => {
    expect(fmtEmpty(undefined)).toBe('—')
  })
  it('空字符串 → —', () => {
    expect(fmtEmpty('')).toBe('—')
  })
  it('0 原样返回（不转 —）', () => {
    expect(fmtEmpty(0)).toBe(0)
  })
  it('字符串 "0" 原样返回', () => {
    expect(fmtEmpty('0')).toBe('0')
  })
  it('数字 123.4 原样返回', () => {
    expect(fmtEmpty(123.4)).toBe(123.4)
  })
  it('普通字符串原样返回', () => {
    expect(fmtEmpty('abc')).toBe('abc')
  })
})
