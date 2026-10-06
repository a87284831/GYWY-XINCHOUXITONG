import { describe, it, expect } from 'vitest'
import { PROJECT_EXPORT_TYPES, visibleProjectExportTypes, projectExportTarget } from './exportLogic.js'

// 问题6：分项目导出整合成「项目 + 人员类型」一个入口。
// 复用三个既有后端接口，不新增后端能力；管理员工资维持仅总部（admin）可见。
describe('visibleProjectExportTypes', () => {
  it('管理员可见基层/案场/管理三类', () => {
    expect(visibleProjectExportTypes(true).map((t) => t.value)).toEqual(['staff', 'case', 'manager'])
  })
  it('项目账号只可见基层/案场，管理选项隐藏（维持现权限）', () => {
    expect(visibleProjectExportTypes(false).map((t) => t.value)).toEqual(['staff', 'case'])
  })
})

describe('projectExportTarget', () => {
  it('基层员工 → /api/export/project', () => {
    const r = projectExportTarget('staff', '2026-08', '甲项目')
    expect(r.url).toBe('/api/export/project?ym=2026-08&project=' + encodeURIComponent('甲项目'))
    expect(r.filename).toContain('甲项目')
    expect(r.filename).toContain('2026-08')
  })
  it('案场人员 → project-case', () => {
    expect(projectExportTarget('case', '2026-08', '甲项目').url).toContain('/api/export/project-case?')
  })
  it('管理人员 → project-managers', () => {
    expect(projectExportTarget('manager', '2026-08', '甲项目').url).toContain('/api/export/project-managers?')
  })
  it('项目账号越权选 manager 被拒绝', () => {
    expect(() => projectExportTarget('manager', '2026-08', '甲项目', false)).toThrow(/权限/)
  })
  it('未知类型报错', () => {
    expect(() => projectExportTarget('alien', '2026-08', '甲项目')).toThrow(/类型/)
  })
  it('类型清单标签非空', () => {
    for (const t of PROJECT_EXPORT_TYPES) expect(t.label.length).toBeGreaterThan(0)
  })
})
