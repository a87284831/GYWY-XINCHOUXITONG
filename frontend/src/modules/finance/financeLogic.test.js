import { describe, it, expect } from 'vitest'
import {
  fmtMoney, moneyCell, round2, cumVals, trendData, roseData, rankData, topN,
  histData, heatData, sankeyData, SANKEY_PALETTE, ledgerMonthSum, toAmount,
  ledgerSaveItems, defaultProjId, summaryTotals, payGroupByProject, qs, defaultYears,
} from './financeLogic'

describe('fmtMoney / moneyCell', () => {
  it('fmtMoney 千分位 2 位小数', () => {
    expect(fmtMoney(1234567.891)).toBe('1,234,567.89')
    expect(fmtMoney(0)).toBe('0.00')
  })
  it('moneyCell 0 显示 —，非 0 千分位', () => {
    expect(moneyCell(0)).toBe('—')
    expect(moneyCell('0')).toBe('—')
    expect(moneyCell(1234.5)).toBe('1,234.50')
    expect(moneyCell(null)).toBe('—')
  })
})

describe('round2 / cumVals', () => {
  it('round2 两位舍入', () => {
    expect(round2(100.004)).toBe(100)
    expect(round2('3.005')).toBe(3.01) // 3.005*100=300.5000…06 → 301
    expect(round2(undefined)).toBe(0)
  })
  it('cumVals 逐步累计并舍入（浮点修复）', () => {
    expect(cumVals([0.1, 0.2])).toEqual([0.1, 0.3])
    expect(cumVals([1.11, 2.22])).toEqual([1.11, 3.33])
    expect(cumVals([])).toEqual([])
  })
})

describe('trendData', () => {
  it('keys 为月份、值舍入、累计逐月', () => {
    const r = trendData({ '2026-01': 100, '2026-02': 55.5, '2026-03': 0 })
    expect(r.months).toEqual(['2026-01', '2026-02', '2026-03'])
    expect(r.vals).toEqual([100, 55.5, 0])
    expect(r.cumVals).toEqual([100, 155.5, 155.5])
  })
  it('空对象安全', () => {
    expect(trendData(undefined)).toEqual({ months: [], vals: [], cumVals: [] })
  })
})

describe('roseData', () => {
  it('映射名称、过滤 ≤0、未知 key 回退原名', () => {
    const r = roseData({ food: 100.004, drink: 0, rent: 20.5 }, { food: '餐饮' })
    expect(r).toEqual([
      { name: '餐饮', value: 100 },
      { name: 'rent', value: 20.5 },
    ])
  })
})

describe('rankData / topN', () => {
  it('rankData 取指定字段并过滤 ≤0', () => {
    expect(rankData([{ name: 'A', total: 5.0 }, { name: 'B', total: 0 }], 'total'))
      .toEqual([{ name: 'A', value: 5 }])
    expect(rankData([{ name: 'C', discount: 12.5 }], 'discount'))
      .toEqual([{ name: 'C', value: 12.5 }])
  })
  it('topN 降序截取', () => {
    const data = [{ name: 'B', value: 2 }, { name: 'A', value: 9 }, { name: 'C', value: 5 }]
    expect(topN(data, 2)).toEqual([{ name: 'A', value: 9 }, { name: 'C', value: 5 }])
    expect(topN(data, 10)).toHaveLength(3)
  })
})

describe('histData', () => {
  it('年度对象转数组', () => {
    expect(histData({ 2024: 10, 2025: 5.005 })).toEqual([
      { name: '2024', value: 10 },
      { name: '2025', value: 5.01 },
    ])
  })
})

describe('heatData', () => {
  it('仅保留 >0 的点，坐标 [列,行,值]，max 取最大', () => {
    const hm = [
      { name: 'P1', values: { '2026-01': 3.004, '2026-02': 0 } },
      { name: 'P2', values: { '2026-01': 0, '2026-02': 7.5 } },
    ]
    const r = heatData(hm)
    expect(r.months).toEqual(['2026-01', '2026-02'])
    expect(r.projects).toEqual(['P1', 'P2'])
    expect(r.points).toEqual([[0, 0, 3], [1, 1, 7.5]])
    expect(r.max).toBe(7.5)
  })
  it('空热力图安全 max=0', () => {
    expect(heatData([])).toEqual({ months: [], projects: [], points: [], max: 0 })
  })
})

describe('sankeyData', () => {
  const base = { payment_types: {}, payment_type_total: {}, payment_type_paid: {} }
  it('基础三节点 + 零合计类型跳过', () => {
    const r = sankeyData({ ...base, payment_types: { a: '类型A', b: '类型B' }, payment_type_total: { a: 100, b: 0 }, payment_type_paid: { a: 40 } })
    expect(r.nodes.map((n) => n.name)).toEqual(['已确认数据', '已支付', '未支付', '类型A'])
    expect(r.nodes[0].itemStyle.color).toBe('#3b82f6')
    expect(r.nodes[3].itemStyle.color).toBe(SANKEY_PALETTE[0])
    expect(r.links).toEqual([
      { source: '已确认数据', target: '类型A', value: 100 },
      { source: '类型A', target: '已支付', value: 40 },
      { source: '类型A', target: '未支付', value: 60 },
    ])
  })
  it('多类型调色板循环；paid=0 时无已支付边', () => {
    const r = sankeyData({ ...base, payment_types: { a: 'A', b: 'B' }, payment_type_total: { a: 10, b: 20 }, payment_type_paid: { a: 0 } })
    expect(r.nodes[3].itemStyle.color).toBe(SANKEY_PALETTE[0])
    expect(r.nodes[4].itemStyle.color).toBe(SANKEY_PALETTE[1])
    const bLinks = r.links.filter((l) => l.target === 'B' || l.source === 'B')
    expect(bLinks).toEqual([
      { source: '已确认数据', target: 'B', value: 20 },
      { source: 'B', target: '未支付', value: 20 },
    ])
  })
  it('unpaid 不为负', () => {
    const r = sankeyData({ ...base, payment_types: { a: 'A' }, payment_type_total: { a: 10 }, payment_type_paid: { a: 99 } })
    expect(r.links).toEqual([
      { source: '已确认数据', target: 'A', value: 10 },
      { source: 'A', target: '已支付', value: 99 },
    ])
  })
})

describe('ledger 编辑逻辑', () => {
  it('ledgerMonthSum 空串/非法按 0', () => {
    expect(ledgerMonthSum(['12.5', '', 'abc', 3, null])).toBe(15.5)
    expect(ledgerMonthSum([])).toBe(0)
  })
  it('toAmount 语义：非空且可数值化才收', () => {
    expect(toAmount('')).toBe(0)
    expect(toAmount('abc')).toBe(0)
    expect(toAmount('5')).toBe(5)
    expect(toAmount('5.5')).toBe(5.5)
    expect(toAmount(0)).toBe(0)
  })
  it('ledgerSaveItems 逐格转换', () => {
    expect(ledgerSaveItems([{ category: 'k1', value: '3' }, { category: 'k2', value: '' }]))
      .toEqual([{ category: 'k1', amount: 3 }, { category: 'k2', amount: 0 }])
  })
  it('defaultProjId 跳过 id=17，否则首个，空表 0', () => {
    expect(defaultProjId([{ id: 17 }, { id: 3 }, { id: 5 }])).toBe(3)
    expect(defaultProjId([{ id: 17 }])).toBe(17)
    expect(defaultProjId([])).toBe(0)
  })
})

describe('summaryTotals', () => {
  it('按类别跨月求和，缺失格按 0', () => {
    const a = {
      categories: { a: '甲', b: '乙' },
      months: ['2026-01', '2026-02'],
      grid: { '2026-01': { a: 1, b: 2 }, '2026-02': { a: 3 } },
    }
    expect(summaryTotals(a)).toEqual({ a: 4, b: 2 })
  })
})

describe('payGroupByProject', () => {
  it('按项目分组、保持插入顺序、金额语义一致', () => {
    const cells = [
      { project_id: '1', type: 't1', value: '5' },
      { project_id: '1', type: 't2', value: '' },
      { project_id: '2', type: 't1', value: 'x' },
    ]
    expect(payGroupByProject(cells)).toEqual({
      1: [{ type: 't1', amount: 5 }, { type: 't2', amount: 0 }],
      2: [{ type: 't1', amount: 0 }],
    })
  })
})

describe('qs / defaultYears', () => {
  it('qs 跳过 undefined/null/空串，保留 0', () => {
    expect(qs({ year: 2026, project_id: 0, empty: '' })).toBe('?year=2026&project_id=0')
    expect(qs({})).toBe('')
    expect(qs(undefined)).toBe('')
  })
  it('defaultYears 2026→2020', () => {
    expect(defaultYears()).toEqual([2026, 2025, 2024, 2023, 2022, 2021, 2020])
  })
})
