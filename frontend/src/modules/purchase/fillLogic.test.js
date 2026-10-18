import { describe, it, expect } from 'vitest'
import {
  FILL_LINES, newRow, isRowReadonly, canCopyRow, canRevokeRow, canDeleteRow,
  rowSaveable, validateRow, notInLibrary, rowPayload, saveSummary, freqCell, monthCardStatus,
  draftKey, fillCardAction,
} from './fillLogic'

describe('newRow / FILL_LINES', () => {
  it('五条线与空行工厂', () => {
    expect(FILL_LINES).toEqual(['环境', '绿化', '工程', '秩序', '行政'])
    const r = newRow(1, '环境')
    expect(r.status).toBe('new')
    expect(r.is_custom).toBe(false)
    expect(r.quantity).toBe(0)
    expect(r.line).toBe('环境')
  })
})

describe('行状态机（isRowReadonly/copy/revoke/delete/saveable）', () => {
  const row = (status) => ({ status, item_name: 'x', quantity: 1, reason: '' })
  it('confirmed 全只读不可删不可复制', () => {
    const r = row('confirmed')
    expect(isRowReadonly(r, true)).toBe(true)
    expect(isRowReadonly(r, false)).toBe(true)
    expect(canCopyRow(r)).toBe(false)
    expect(canDeleteRow(r, true)).toBe(false)
    expect(rowSaveable(r, true)).toBe(false)
  })
  it('submitted：员工只读需先撤销；admin 默认锁定，改单解锁（_unlocked）后可编辑', () => {
    const r = row('submitted')
    expect(isRowReadonly(r, false)).toBe(true)
    expect(isRowReadonly(r, true)).toBe(true) // admin 默认锁定
    expect(rowSaveable(r, true)).toBe(false) // 锁定不可保存
    const u = { ...r, _unlocked: true }
    expect(isRowReadonly(u, true)).toBe(false) // 解锁后可编辑
    expect(rowSaveable(u, true)).toBe(true)
    expect(canRevokeRow(r)).toBe(true)
    expect(canDeleteRow(r, false)).toBe(false)
    expect(canDeleteRow(r, true)).toBe(true)
    expect(rowSaveable(r, false)).toBe(false)
  })
  it('draft/returned/new 可编辑可保存', () => {
    for (const s of ['draft', 'returned', 'new']) {
      const r = row(s)
      expect(isRowReadonly(r, false)).toBe(false)
      expect(canDeleteRow(r, false)).toBe(true)
      expect(rowSaveable(r, false)).toBe(true)
    }
  })
  it('空行不可保存', () => {
    expect(rowSaveable({ status: 'new', item_name: '', quantity: 0, reason: '' }, false)).toBe(false)
  })
})

describe('validateRow（逐行校验文案）', () => {
  it('按顺序报告首个缺失项', () => {
    expect(validateRow({ line: '', item_name: '', is_custom: false, quantity: 0 }, 3)).toBe('第 3 行：请选择条线')
    expect(validateRow({ line: '环境', item_name: '', is_custom: false, quantity: 0 }, 2)).toBe('第 2 行：请填写商品名称')
    expect(validateRow({ line: '环境', item_name: 'A4纸', spec: '', is_custom: false, quantity: 5, product_id: 9, specs: [{ id: 9, spec: '70g' }] }, 1)).toBe('第 1 行：请选择规格型号')
    expect(validateRow({ line: '环境', item_name: 'A4纸', spec: '70g', is_custom: false, quantity: 0, product_id: 9, specs: [{ id: 9, spec: '70g' }] }, 1)).toBe('第 1 行：请填写数量')
  })
  it('清单外不要求规格；齐备返回 null', () => {
    expect(validateRow({ line: '环境', item_name: '临时物资', spec: '', is_custom: true, quantity: 2 }, 1)).toBe(null)
    expect(validateRow({ line: '环境', item_name: 'A4纸', spec: '70g', is_custom: false, quantity: 5, product_id: 9, specs: [{ id: 9, spec: '70g' }] }, 1)).toBe(null)
  })
  it('清单内模式名称未匹配清单：拦截并引导开清单外开关', () => {
    // 联想无结果（无 product_id 且无 specs）→ notInLibrary 命中
    const r = { line: '环境', item_name: '清单外商品X', spec: '', is_custom: false, quantity: 5, product_id: null, specs: [] }
    expect(notInLibrary(r)).toBe(true)
    expect(validateRow(r, 2)).toBe('第 2 行：「清单外商品X」未匹配到清单内商品，请从联想列表选择，或打开行尾「清单外」开关')
    // 已选名称（有 specs 未选规格）→ 不算未匹配，走规格校验
    const r2 = { line: '环境', item_name: 'A4纸', spec: '', is_custom: false, quantity: 5, product_id: null, specs: [{ id: 9, spec: '70g' }] }
    expect(notInLibrary(r2)).toBe(false)
    expect(validateRow(r2, 1)).toBe('第 1 行：请选择规格型号')
    // 开清单外 → 不命中
    expect(notInLibrary({ line: '环境', item_name: '临时', is_custom: true, product_id: null, specs: [] })).toBe(false)
    // 空名称 → 不命中（走名称必填）
    expect(notInLibrary({ line: '环境', item_name: '', is_custom: false, product_id: null, specs: [] })).toBe(false)
  })
})

describe('rowPayload', () => {
  it('清单内带 product_id，admin 带 project_id，提交置 submitted', () => {
    const p = rowPayload(
      { line: '环境', item_name: 'A4纸', brand: '得力', spec: '70g', unit: '箱', quantity: '3', stock: 1, reason: 'r', use_location: 'u', remark: '', status: 'draft', is_custom: false, product_id: 9 },
      '2026-09', 12, true, true,
    )
    expect(p).toEqual({
      month: '2026-09', project_id: 12, line: '环境', item_name: 'A4纸', brand: '得力', spec: '70g', unit: '箱',
      quantity: 3, stock: 1, reason: 'r', use_location: 'u', remark: '', status: 'submitted', product_id: 9, is_custom: 0,
    })
  })
  it('清单外不带 product_id；staff 不带 project_id；退回行非提交保存不传 status（保持 returned 不降级）', () => {
    const p = rowPayload(
      { line: '行政', item_name: '临时', brand: '', spec: '', unit: '个', quantity: 1, stock: 0, reason: '', use_location: '', remark: '', status: 'returned', is_custom: true, product_id: null },
      '2026-09', 0, false, false,
    )
    expect(p.product_id).toBeUndefined()
    expect(p.project_id).toBeUndefined()
    expect(p.status).toBeUndefined()
    expect(p.is_custom).toBe(1)
  })
  it('退回行提交时置 submitted；draft 行保存置 draft', () => {
    const base = { line: '行政', item_name: '临时', brand: '', spec: '', unit: '个', quantity: 1, stock: 0, reason: '', use_location: '', remark: '', is_custom: true, product_id: null }
    expect(rowPayload({ ...base, status: 'returned' }, '2026-09', 0, false, true).status).toBe('submitted')
    expect(rowPayload({ ...base, status: 'draft' }, '2026-09', 0, false, false).status).toBe('draft')
    expect(rowPayload({ ...base, status: 'new' }, '2026-09', 0, false, false).status).toBe('draft')
    expect(rowPayload({ ...base, status: 'submitted' }, '2026-09', 0, true, false).status).toBe('submitted')
  })
})

describe('saveSummary / freqCell / monthCardStatus / draftKey / fillCardAction', () => {
  it('saveSummary 四分支', () => {
    expect(saveSummary(0, 0, false)).toEqual({ type: 'info', text: '没有需要保存的内容' })
    expect(saveSummary(3, 0, false)).toEqual({ type: 'success', text: '已保存 3 条' })
    expect(saveSummary(3, 0, true)).toEqual({ type: 'success', text: '已保存并提交 3 条' })
    expect(saveSummary(2, 1, false)).toEqual({ type: 'warning', text: '已保存 2 条，1 条未保存（请按提示补全）' })
  })
  it('freqCell 三态与明细格式', () => {
    expect(freqCell(null)).toEqual({ kind: 'wait', text: '选规格后显示', detail: [] })
    expect(freqCell({ count: 0 })).toEqual({ kind: 'none', text: '近3月无记录', detail: [] })
    const c = freqCell({ count: 3, detail: [{ month: '2026-08', qty: 5, unit: '箱' }, { month: '2026-07', qty: 2, unit: '' }] })
    expect(c.kind).toBe('ok')
    expect(c.text).toBe(' 购3次')
    expect(c.detail).toEqual(['·5箱·8月', '·2·7月'])
  })
  it('monthCardStatus 四态', () => {
    const w = { start_date: '2026-09-20', end_date: '2026-09-23' }
    expect(monthCardStatus({ window: null }, '2026-09-21')).toEqual({ tag: '未开放', type: 'info', window: '未配置填报窗口' })
    expect(monthCardStatus({ window: w, open: true }, '2026-09-21').tag).toBe('填报开放中')
    expect(monthCardStatus({ window: w, open: false }, '2026-09-10').tag).toBe('尚未开放')
    expect(monthCardStatus({ window: w, open: false }, '2026-09-25').tag).toBe('已截止')
    expect(monthCardStatus({ window: w, open: false }, '2026-09-25').window).toBe('窗口：2026-09-20 ~ 2026-09-23')
  })
  it('draftKey 与卡片按钮', () => {
    expect(draftKey('2026-09', 0)).toBe('gw_purchase_fill_draft_2026-09_0')
    expect(draftKey('2026-09', 12)).toBe('gw_purchase_fill_draft_2026-09_12')
    expect(fillCardAction({ month: '2026-09', returned_count: 2, open: true }, '2026-09').text).toBe('修改后重新填报')
    expect(fillCardAction({ month: '2026-09', returned_count: 0, open: true }, '2026-09').text).toBe('进入填报')
    expect(fillCardAction({ month: '2026-08', returned_count: 0, open: false }, '2026-09').text).toBe('查看')
  })
})
