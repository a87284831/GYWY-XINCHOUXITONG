import { describe, it, expect } from 'vitest'
import {
  deriveStaffStatus, deriveStaffCategory, normalizeSpecialDeductions,
  budgetAnnualFromMonths, maskBankCard, maskIdCard,
} from './staffLogic'

describe('deriveStaffStatus（复刻 sfDeriveStatus）', () => {
  const today = '2026-09-26'
  it('离职日期≤今天 → 离职', () => {
    expect(deriveStaffStatus({ hire_date: '2020-01-01', regular_date: '2020-04-01', resign_date: '2026-09-26' }, today)).toBe('离职')
    expect(deriveStaffStatus({ hire_date: '2020-01-01', resign_date: '2026-01-01' }, today)).toBe('离职')
  })
  it('有转正日期：未到→试用，已到→正式', () => {
    expect(deriveStaffStatus({ regular_date: '2026-10-01' }, today)).toBe('试用')
    expect(deriveStaffStatus({ regular_date: '2026-09-26' }, today)).toBe('正式')
  })
  it('无转正日期：入职日期在未来→试用，否则正式', () => {
    expect(deriveStaffStatus({ hire_date: '2026-10-01' }, today)).toBe('试用')
    expect(deriveStaffStatus({ hire_date: '2026-01-01' }, today)).toBe('正式')
    expect(deriveStaffStatus({}, today)).toBe('正式')
  })
  it('离职日期在未来不触发离职，按转正规则', () => {
    expect(deriveStaffStatus({ resign_date: '2026-12-31' }, today)).toBe('正式')
  })
})

describe('deriveStaffCategory（复刻 sfDeriveCategory）', () => {
  const today = '2026-09-26'
  it('黑名单优先', () => {
    expect(deriveStaffCategory({ blacklist: true }, today)).toBe('黑名单')
    expect(deriveStaffCategory({ blacklist: true, resign_date: '2020-01-01' }, today)).toBe('黑名单')
  })
  it('有离职日期≤今天→离职，否则在职', () => {
    expect(deriveStaffCategory({ resign_date: '2026-09-26' }, today)).toBe('离职')
    expect(deriveStaffCategory({}, today)).toBe('在职')
    expect(deriveStaffCategory({ resign_date: '2026-12-31' }, today)).toBe('在职')
  })
})

describe('normalizeSpecialDeductions（复刻 staffDeduct 的 sd 归一化）', () => {
  it('数组原样返回', () => {
    const arr = [{ item: '子女教育', amount: 2000, from_ym: '2026-01' }]
    expect(normalizeSpecialDeductions(arr)).toEqual(arr)
  })
  it('对象映射 → 数组（值可为数字或 {amount, from_ym}）', () => {
    const sd = { 子女教育: 2000, 赡养老人: { amount: 3000, from_ym: '2026-03' } }
    expect(normalizeSpecialDeductions(sd)).toEqual([
      { item: '子女教育', amount: 2000, from_ym: '' },
      { item: '赡养老人', amount: 3000, from_ym: '2026-03' },
    ])
  })
  it('空值 → 空数组', () => {
    expect(normalizeSpecialDeductions(null)).toEqual([])
    expect(normalizeSpecialDeductions(undefined)).toEqual([])
  })
})

describe('budgetAnnualFromMonths（复刻 budgetSyncAnnual/budgetSave 年度=各月之和）', () => {
  it('求和并四舍五入到 2 位', () => {
    expect(budgetAnnualFromMonths({ 1: 100.1, 2: 200.205 })).toBe(300.31)
    expect(budgetAnnualFromMonths({})).toBe(0)
  })
})

describe('敏感字段打码（复刻 loadStaff 行内正则）', () => {
  it('银行卡号 4+4 保留', () => {
    expect(maskBankCard('6222021234567890123')).toBe('6222****0123')
    expect(maskBankCard('')).toBe('')
    expect(maskBankCard('123')).toBe('123') // 不匹配正则原样返回
  })
  it('证件号码 4+4 保留', () => {
    expect(maskIdCard('370123199001011234')).toBe('3701**********1234')
    expect(maskIdCard('')).toBe('')
  })
})
