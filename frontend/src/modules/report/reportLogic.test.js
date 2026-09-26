// 报表纯逻辑测试 — 对照 app.js:2236-2243（rDelta/rMoney）
import { describe, it, expect } from 'vitest'
import { deltaInfo, rMoney } from './reportLogic'

describe('deltaInfo（KPI 卡片"较上期"）', () => {
  it('null/undefined → null（不显示）', () => {
    expect(deltaInfo(null)).toBe(null)
    expect(deltaInfo(undefined)).toBe(null)
  })
  it('上升 → 绿色 ↑，取绝对值', () => {
    expect(deltaInfo(5)).toEqual({ arrow: '↑', color: '#16a34a', value: 5 })
    expect(deltaInfo(0.5).arrow).toBe('↑')
  })
  it('下降 → 红色 ↓', () => {
    expect(deltaInfo(-3)).toEqual({ arrow: '↓', color: '#dc2626', value: 3 })
  })
  it('持平 → 灰色 —', () => {
    expect(deltaInfo(0)).toEqual({ arrow: '—', color: '#94a3b8', value: 0 })
  })
})

describe('rMoney（¥ + 千分位 2 位小数）', () => {
  it('基础格式', () => {
    expect(rMoney(1234567.891)).toBe('¥1,234,567.89')
    expect(rMoney(0)).toBe('¥0.00')
    expect(rMoney('80')).toBe('¥80.00')
  })
  it('非数字 → ¥0.00', () => {
    expect(rMoney(null)).toBe('¥0.00')
    expect(rMoney('abc')).toBe('¥0.00')
  })
})
