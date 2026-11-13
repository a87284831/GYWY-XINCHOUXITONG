import { describe, it, expect } from 'vitest'
import {
  PROJECT_EXPORT_TYPES, visibleProjectExportTypes, projectExportTarget,
  ATTENDANCE_EXPORT_TYPES, attendanceExportTarget,
} from './exportLogic.js'

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

// 数据导出 → 考勤导出：项目/人员类型是筛选条件，不是独立导出项
describe('attendanceExportTarget', () => {
  it('全部项目 + 管理人员合并表', () => {
    const r = attendanceExportTarget({ ym: '2026-11', project: 'all', personType: 'manager', mode: 'merged' })
    expect(r.url).toContain('/api/export/attendance?')
    expect(r.url).toContain('project=all')
    expect(r.url).toContain('person_type=manager')
    expect(r.url).toContain('mode=merged')
    expect(r.previewUrl).toContain('dry_run=1')
    expect(r.filename).toBe('考勤导出_全部项目_管理人员_2026-11.xlsx')
  })
  it('单项目 + 案场人员', () => {
    const r = attendanceExportTarget({ ym: '2026-11', project: '甲项目', personType: 'case', mode: 'merged' })
    expect(r.url).toContain('project=' + encodeURIComponent('甲项目'))
    expect(r.filename).toBe('考勤导出_甲项目_案场人员_2026-11.xlsx')
  })
  it('全量数据包固定全部项目（忽略页面项目选择）', () => {
    const r = attendanceExportTarget({ ym: '2026-11', project: '甲项目', personType: 'all', mode: 'bundle' })
    expect(r.url).toContain('project=all')
    expect(r.url).toContain('mode=bundle')
    expect(r.filename).toBe('考勤全量数据包_全部项目_全部人员_2026-11.xlsx')
  })
  it('项目账号全量数据包仅本项目', () => {
    const r = attendanceExportTarget({ ym: '2026-11', project: '甲项目', personType: 'all', mode: 'bundle', isProjectScope: true })
    expect(r.url).toContain('project=' + encodeURIComponent('甲项目'))
    expect(r.filename).toBe('考勤全量数据包_甲项目_全部人员_2026-11.xlsx')
  })
  it('未知人员类型/打包方式报错', () => {
    expect(() => attendanceExportTarget({ ym: '2026-11', personType: 'alien' })).toThrow(/人员类型/)
    expect(() => attendanceExportTarget({ ym: '2026-11', mode: 'alien' })).toThrow(/打包方式/)
  })
  it('人员类型清单包含全部/管理/案场', () => {
    expect(ATTENDANCE_EXPORT_TYPES.map((t) => t.value)).toEqual(['all', 'manager', 'case'])
  })
})
