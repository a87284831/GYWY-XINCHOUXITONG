// 维保纯逻辑测试 — 对照 app.js:2507-2527, 2886-2932, 2830-2834, 3025-3034
import { describe, it, expect } from 'vitest'
import {
  maintToday, maintAddDays, maintStatusOf, maintOverlapsYear, maintFmtMoney,
  filterLedger, priceAutoCalc, MAINT_STATUS_LABELS, MAINT_STATUS_TAG, MAINT_STATUS_OPTS,
} from './maintLogic'

describe('maintToday / maintAddDays', () => {
  it('today 格式 YYYY-MM-DD', () => {
    expect(maintToday(new Date(2026, 8, 26))).toBe('2026-09-26')
    expect(maintToday(new Date(2026, 0, 5))).toBe('2026-01-05')
  })
  it('addDays 跨月（复刻旧版 toISOString 在 UTC+8 回退一天的时区行为）', () => {
    expect(maintAddDays('2026-09-26', 30)).toBe('2026-10-25')
    expect(maintAddDays('2026-12-20', 30)).toBe('2027-01-18')
  })
})

describe('maintStatusOf（6 档分级：normal/d90/d60/d30/expired/missing）', () => {
  const t = '2026-09-26'
  it('无到期日期 → missing', () => {
    expect(maintStatusOf(null, t)).toBe('missing')
    expect(maintStatusOf('', t)).toBe('missing')
  })
  it('早于今天 → expired', () => {
    expect(maintStatusOf('2026-09-25', t)).toBe('expired')
  })
  it('30 天内 → d30（旧版 addDays 时区行为下阈值实为 +29 天）', () => {
    expect(maintStatusOf('2026-09-26', t)).toBe('d30')
    expect(maintStatusOf('2026-10-25', t)).toBe('d30')
  })
  it('31~60 天 → d60；61~90 天 → d90', () => {
    expect(maintStatusOf('2026-10-26', t)).toBe('d60')
    expect(maintStatusOf('2026-11-24', t)).toBe('d60')
    expect(maintStatusOf('2026-11-25', t)).toBe('d90')
    expect(maintStatusOf('2026-12-24', t)).toBe('d90')
  })
  it('超过 90 天 → normal', () => {
    expect(maintStatusOf('2026-12-25', t)).toBe('normal')
  })
})

describe('状态文案与标签样式映射', () => {
  it('6 档全覆盖', () => {
    for (const k of MAINT_STATUS_OPTS) {
      expect(MAINT_STATUS_LABELS[k]).toBeTruthy()
      expect(MAINT_STATUS_TAG[k]).toMatch(/^tag /)
    }
    expect(MAINT_STATUS_OPTS).toEqual(['normal', 'd90', 'd60', 'd30', 'expired', 'missing'])
  })
})

describe('maintOverlapsYear（合同期间与年份有交集）', () => {
  it('边界判定', () => {
    expect(maintOverlapsYear('2025-01-01', '2025-12-31', '2026')).toBe(false)
    expect(maintOverlapsYear('2025-06-01', '2026-03-01', '2026')).toBe(true)
    expect(maintOverlapsYear('2026-12-01', '2027-05-01', '2026')).toBe(true)
  })
})

describe('maintFmtMoney（千分位整数）', () => {
  it('格式化', () => {
    expect(maintFmtMoney(1234567)).toBe('1,234,567')
    expect(maintFmtMoney('8800')).toBe('8,800')
    expect(maintFmtMoney(null)).toBe('0')
  })
})

describe('filterLedger（台账筛选+排序，含 focus 聚焦）', () => {
  const rows = [
    { id: 1, project_name: '盈科广场', party: '华安消防', remark: '', end_date: '2026-10-01', start_date: '2025-10-01', amount: '8800', elevator_count: 0, status: 'd30' },
    { id: 2, project_name: '绿地中心', party: '奥的斯', remark: '含年检', end_date: '2027-01-01', start_date: '2026-01-01', amount: 12000, elevator_count: 6, status: 'normal' },
    { id: 3, project_name: ' 盈科广场二期 ', party: '华安消防', remark: '', end_date: '2026-12-15', start_date: '2026-01-01', amount: 5000, elevator_count: 0 },
  ]
  it('关键词匹配 项目+签约方+备注', () => {
    expect(filterLedger(rows, { kw: '奥的斯' }, 'elevator').map((r) => r.id)).toEqual([2])
    expect(filterLedger(rows, { kw: '年检' }, 'elevator').map((r) => r.id)).toEqual([2])
    expect(filterLedger(rows, { kw: '盈科' }, 'elevator').map((r) => r.id)).toEqual([1, 3])
  })
  it('项目下拉精确匹配', () => {
    expect(filterLedger(rows, { project: '绿地中心' }, 'elevator').map((r) => r.id)).toEqual([2])
  })
  it('状态快捷筛选：优先用行内 status，缺失时按到期日现算', () => {
    expect(filterLedger(rows, { status: 'd30' }, 'elevator').map((r) => r.id)).toEqual([1])
    expect(filterLedger(rows, { status: 'normal' }, 'elevator').map((r) => r.id)).toEqual([2])
  })
  it('到期日期区间 / 签约方 / 金额上下限 / 台数上下限', () => {
    expect(filterLedger(rows, { efrom: '2026-12-01' }, 'elevator').map((r) => r.id)).toEqual([2, 3])
    expect(filterLedger(rows, { party: '华安' }, 'elevator').map((r) => r.id)).toEqual([1, 3])
    expect(filterLedger(rows, { amin: 6000 }, 'elevator').map((r) => r.id)).toEqual([1, 2])
    expect(filterLedger(rows, { emin: 1, emax: 5 }, 'elevator')).toEqual([])
  })
  it('年份取交集（overlapsYear）', () => {
    expect(filterLedger(rows, { year: '2026' }, 'elevator').map((r) => r.id)).toEqual([1, 2, 3])
    expect(filterLedger(rows, { year: '2027' }, 'elevator').map((r) => r.id)).toEqual([2])
  })
  it('排序：字段与方向', () => {
    const asc = filterLedger(rows, { sortField: 'amount' }, 'elevator', { dir: 'asc' })
    expect(asc.map((r) => r.id)).toEqual([3, 1, 2])
    const desc = filterLedger(rows, { sortField: 'amount' }, 'elevator', { dir: 'desc' })
    expect(desc.map((r) => r.id)).toEqual([2, 1, 3])
  })
  it('focus.id 聚焦时忽略其他筛选', () => {
    expect(filterLedger(rows, { kw: '绿地' }, 'elevator', null, { type: 'elevator', id: 1 }).map((r) => r.id)).toEqual([1])
  })
  it('focus.projectFilter 按项目名精确过滤', () => {
    expect(filterLedger(rows, {}, 'elevator', null, { type: 'elevator', projectFilter: '绿地中心' }).map((r) => r.id)).toEqual([2])
  })
})

describe('priceAutoCalc（单价自动计算联动）', () => {
  it('电梯：金额÷台数，保留2位；台数为0 → 空串', () => {
    expect(priceAutoCalc(12000, 6, true)).toBe('2000.00')
    expect(priceAutoCalc(100, 0, true)).toBe('')
  })
  it('消防：金额÷面积；面积为0 → 空串', () => {
    expect(priceAutoCalc(1000, 500, false)).toBe('2.00')
    expect(priceAutoCalc(1000, 0, false)).toBe('')
  })
})
