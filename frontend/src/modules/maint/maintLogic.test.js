// 维保纯逻辑测试 — 对照 app.js:2507-2527, 2886-2932, 3090-3104, 2830-2834, 3025-3034
import { describe, it, expect } from 'vitest'
import {
  maintToday, maintAddDays, maintStatusOf, maintOverlapsYear, maintFmtMoney,
  filterLedger, buildLedgerExportRows, buildCsv, priceAutoCalc,
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

describe('maintStatusOf（阈值判定，复刻旧版实际 29 天窗口）', () => {
  const t = '2026-09-26'
  it('无到期日期 → normal', () => {
    expect(maintStatusOf(null, t)).toBe('normal')
  })
  it('早于今天 → expired', () => {
    expect(maintStatusOf('2026-09-25', t)).toBe('expired')
  })
  it('今天或 30 天内（旧版阈值实为 +29 天）→ soon', () => {
    expect(maintStatusOf('2026-09-26', t)).toBe('soon')
    expect(maintStatusOf('2026-10-25', t)).toBe('soon')
  })
  it('超过阈值 → normal', () => {
    expect(maintStatusOf('2026-10-26', t)).toBe('normal')
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
    { id: 1, project_name: '盈科广场', party: '华安消防', remark: '', end_date: '2026-10-01', start_date: '2025-10-01', amount: '8800', elevator_count: 0 },
    { id: 2, project_name: '绿地中心', party: '奥的斯', remark: '含年检', end_date: '2027-01-01', start_date: '2026-01-01', amount: 12000, elevator_count: 6 },
    { id: 3, project_name: ' 盈科广场二期 ', party: '华安消防', remark: '', end_date: '2026-12-15', start_date: '2026-01-01', amount: 5000, elevator_count: 0 },
  ]
  it('关键词匹配 项目+签约方+备注', () => {
    expect(filterLedger(rows, { kw: '奥的斯' }, 'elevator').map((r) => r.id)).toEqual([2])
    expect(filterLedger(rows, { kw: '年检' }, 'elevator').map((r) => r.id)).toEqual([2])
    expect(filterLedger(rows, { kw: '盈科' }, 'elevator').map((r) => r.id)).toEqual([1, 3])
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

describe('buildLedgerExportRows（CSV 导出行）', () => {
  const today = '2026-09-26'
  const STATUS_TEXT = { normal: '正常在保', soon: '30天内即将到期', expired: '已过期' }
  const statusOf = (d) => STATUS_TEXT[maintStatusOf(d, today)]
  it('电梯类型含台数/单价列', () => {
    const out = buildLedgerExportRows([{ project_name: 'A', party: 'B', amount: '100', sign_date: '2026-01-01', start_date: '2026-01-02', end_date: '2027-01-01', elevator_count: '4', price_per_unit: '25', remark: 'r' }], true, statusOf)
    expect(Object.keys(out[0])).toEqual(['项目名称', '签约方', '签约金额', '签订日期', '生效开始', '到期日期', '电梯台数', '每台单价', '状态', '备注'])
    expect(out[0]['电梯台数']).toBe(4)
  })
  it('消防类型含面积/每㎡单价列，状态按到期日判定', () => {
    const out = buildLedgerExportRows([{ project_name: 'A', party: 'B', amount: '100', sign_date: '', start_date: '2026-01-02', end_date: '2020-01-01', building_area_sqm: '500', price_per_sqm: '0.2', remark: '' }], false, statusOf)
    expect(out[0]['建筑面积(㎡)']).toBe(500)
    expect(out[0]['状态']).toBe('已过期')
  })
})

describe('buildCsv（BOM + 引号转义）', () => {
  it('首行表头 + 值内引号双写', () => {
    const csv = buildCsv('测试.csv', [{ A: 1, B: '含"引号"' }])
    const lines = csv.split('\n')
    expect(csv.startsWith('\uFEFF')).toBe(true)
    expect(lines[0]).toBe('\uFEFFA,B')
    expect(lines[1]).toBe('"1","含""引号"""')
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
