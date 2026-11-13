import { describe, it, expect } from 'vitest'
import {
  latestQuarterEndMonth, isQuarterEndMonth, needsQuarterEnd, toggleCat,
  perfExportQuery, perfExportTarget, perfPreviewUrl, methodHint, DEFAULT_CATS,
} from './performanceExportLogic'

describe('绩效导出参数逻辑', () => {
  it('默认取当前月及之前最近的季度末月', () => {
    expect(latestQuarterEndMonth(new Date(2026, 10, 13))).toBe('2026-10')
    expect(latestQuarterEndMonth(new Date(2026, 8, 1))).toBe('2026-07')
    expect(latestQuarterEndMonth(new Date(2026, 1, 5))).toBe('2026-01')
    expect(latestQuarterEndMonth(new Date(2026, 0, 1))).toBe('2026-01')
  })

  it('季度末月判定只认 1/4/7/10 月', () => {
    expect(isQuarterEndMonth('2026-10')).toBe(true)
    expect(isQuarterEndMonth('2026-01')).toBe(true)
    expect(isQuarterEndMonth('2026-11')).toBe(false)
    expect(isQuarterEndMonth('bad')).toBe(false)
  })

  it('需要季度末月的发放法/类别', () => {
    expect(needsQuarterEnd('quarter', ['staff'])).toBe(true)
    expect(needsQuarterEnd('installment', [])).toBe(true)
    expect(needsQuarterEnd('follow', ['staff', 'case'])).toBe(false)
    expect(needsQuarterEnd('follow', ['staff', 'manager'])).toBe(true)
    expect(needsQuarterEnd('monthly', ['manager'])).toBe(false)
  })

  it('类别多选切换不修改原数组', () => {
    const base = [...DEFAULT_CATS]
    expect(toggleCat(base, 'staff')).toEqual(['manager', 'case', 'hq'])
    expect(toggleCat(['staff'], 'case')).toEqual(['staff', 'case'])
    expect(base).toEqual(DEFAULT_CATS)
  })

  it('导出参数与文件名按模式拼装', () => {
    const query = perfExportQuery({ ym: '2026-10', cats: ['staff', 'manager'], project: 'all', method: 'follow', mode: 'book' })
    expect(query).toContain('ym=2026-10')
    expect(query).toContain('cats=staff%2Cmanager')
    expect(query).toContain('mode=book')
    expect(query).not.toContain('project=')

    const detail = perfExportTarget({ ym: '2026-10', cats: ['staff'], project: '甲项目', method: 'follow', mode: 'book' })
    expect(detail.url).toContain('/api/export/performance-detail?')
    expect(detail.url).toContain(`project=${encodeURIComponent('甲项目')}`)
    expect(detail.filename).toBe('绩效导出_2026-10.xlsx')

    expect(perfExportTarget({ ym: '2026-10', cats: ['staff'], mode: 'summary' }).filename).toBe('绩效导出_2026-10_仅汇总.xlsx')
    expect(perfExportTarget({ ym: '2026-10', cats: ['staff'], mode: 'split' }).filename).toBe('绩效导出_2026-10_分类.zip')
  })

  it('预览地址与发放法提示', () => {
    expect(perfPreviewUrl({ ym: '2026-10', cats: ['staff'] })).toContain('/api/export/performance-detail/preview?')
    expect(methodHint('follow')).toContain('季度')
    expect(methodHint('monthly')).toContain('季度列不出现')
    expect(methodHint('installment')).toContain('半年度')
    expect(methodHint('unknown')).toBe('')
  })
})
