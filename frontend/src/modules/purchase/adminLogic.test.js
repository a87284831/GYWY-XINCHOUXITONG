import { describe, it, expect } from 'vitest'
import {
  monthShort,
  statusMeta,
  lineTagType,
  budgetStats,
  budgetRowState,
  budgetYears,
  seedCustomForm,
  mapPayload,
  saveCustomPayload,
  hasBudgetMonth,
} from './adminLogic'

describe('adminLogic', () => {
  it('monthShort: 空值返回空串；datetime 截取为 MM-DD HH:MM（复刻 replace(T).slice(5,16)）', () => {
    expect(monthShort('')).toBe('')
    expect(monthShort(null)).toBe('')
    expect(monthShort('2026-09-26T10:20:30')).toBe('09-26 10:20')
    expect(monthShort('2026-09-26 10:20:30')).toBe('09-26 10:20')
  })

  it('statusMeta: 四状态中文+tag 配色（returned 深红底）', () => {
    expect(statusMeta('returned')).toMatchObject({ text: '已退回', type: 'danger', dark: true })
    expect(statusMeta('confirmed')).toMatchObject({ text: '已确认', type: 'success' })
    expect(statusMeta('submitted')).toMatchObject({ text: '已提交', type: 'primary' })
    expect(statusMeta('draft')).toMatchObject({ text: '草稿', type: 'info' })
    expect(statusMeta('whatever').type).toBe('info')
  })

  it('lineTagType: 条线全部确认→success，否则 primary', () => {
    expect(lineTagType({ total: 5, confirmed: 5 })).toBe('success')
    expect(lineTagType({ total: 5, confirmed: 3 })).toBe('primary')
    expect(lineTagType({ total: 0, confirmed: 0 })).toBe('success')
  })

  it('budgetStats: 合计/执行率 round1/剩余 round2/超支数/已填项目数', () => {
    const rows = [
      { budget: 100, actual: 30.333, over: false },
      { budget: 200, actual: 250.456, over: true },
      { budget: 0, actual: 0, over: false },
    ]
    const s = budgetStats(rows)
    expect(s.budget).toBe(300)
    expect(s.actual).toBeCloseTo(280.789, 6) // 求和不先 round（复刻 reduce 直加，展示层 toLocaleString）
    expect(s.rate).toBe(93.6) // round(280.789/300*1000)/10
    expect(s.remain).toBe(19.21)
    expect(s.overCount).toBe(1)
    expect(s.filledCount).toBe(2)
    expect(budgetStats([]).rate).toBe(0)
  })

  it('budgetRowState: 四分支 over/within/noimport/unset', () => {
    expect(budgetRowState({ budget: 100, actual: 120, over: true })).toBe('over')
    expect(budgetRowState({ budget: 100, actual: 50, over: false })).toBe('within')
    expect(budgetRowState({ budget: 100, actual: 0, over: false })).toBe('noimport')
    expect(budgetRowState({ budget: 0, actual: 0, over: false })).toBe('unset')
  })

  it('budgetYears: 当前年前后各一年', () => {
    expect(budgetYears(new Date('2026-09-01T00:00:00'))).toEqual([2025, 2026, 2027])
  })

  it('seedCustomForm: 填报行→编辑表单（name/alias 默认 item_name，category 恒空，quantity/use_location 清空）', () => {
    const f = seedCustomForm({
      item_name: '拖把', brand: '妙洁', spec: '大号', unit: '把', line: '行政',
      stock: 5, remark: '急用',
    })
    expect(f).toEqual({
      name: '拖把', brand: '妙洁', spec: '大号', unit: '把', line: '行政', category: '',
      alias: '拖把', quantity: '', stock: 5, use_location: '', remark: '急用',
    })
  })

  it('mapPayload: 归入候选商品（含 save_alias 与 created_by 透传）', () => {
    const row = { id: 9, _sel: 88, _saveAlias: true }
    expect(mapPayload(row, 7)).toEqual({ item_id: 9, product_id: 88, save_alias: true, created_by: 7 })
    expect(mapPayload({ id: 9, _sel: 0, _saveAlias: false }, 0)).toEqual({
      item_id: 9, product_id: 0, save_alias: false, created_by: 0,
    })
  })

  it('saveCustomPayload: create 不带 product_id；merge 带 product_id', () => {
    const form = {
      name: 'A', brand: 'B', spec: 'S', unit: 'U', line: '工程', category: '五金',
      alias: 'A旧名', quantity: 2, stock: 1, use_location: '地下室', remark: 'r',
    }
    const row = { id: 3 }
    const c = saveCustomPayload(form, row, 'create', 0, 7)
    expect(c.mode).toBe('create')
    expect(c.item_id).toBe(3)
    expect(c.created_by).toBe(7)
    expect(c).not.toHaveProperty('product_id')
    const m = saveCustomPayload(form, row, 'merge', 55, 7)
    expect(m.mode).toBe('merge')
    expect(m.product_id).toBe(55)
  })

  it('hasBudgetMonth: 全年速览 ✓ 判定', () => {
    const months = ['2026-01', '2026-03']
    expect(hasBudgetMonth(months, 2026, 1)).toBe(true)
    expect(hasBudgetMonth(months, 2026, 2)).toBe(false)
    expect(hasBudgetMonth(months, 2026, 12)).toBe(false)
  })
})
