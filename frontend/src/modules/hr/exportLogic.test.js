import { describe, it, expect } from 'vitest'
import {
  PROJECT_EXPORT_TYPES, visibleProjectExportTypes, projectExportTarget,
  ATTENDANCE_EXPORT_TYPES, attendanceExportTarget, exportCapabilities,
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

// 卡片式改版后的权限收口：页面所有可用性判断都取自 exportCapabilities，
// 避免拆分卡片后各卡各写一套（原先只写在筛选区联动里）。
describe('exportCapabilities', () => {
  const projects = ['甲项目', '乙项目', '物业总部']

  it('总部管理员：全部项目、全公司项可用、工资项目清单排除物业总部', () => {
    const c = exportCapabilities({ isAdmin: true, isProjectScope: false, projects })
    expect(c.scopeLabel).toBe('全部项目')
    expect(c.salaryProjects).toEqual(['甲项目', '乙项目'])
    expect(c.attendanceProjects).toEqual(projects)
    expect(c.canExportCompanyWide).toBe(true)
    expect(c.defaultAttendanceProject).toBe('all')
    expect(c.projectTypes.map((t) => t.value)).toEqual(['staff', 'case', 'manager'])
  })

  it('项目账号：强制本项目、无全部项目入口、无全公司项、管理类型隐藏', () => {
    const c = exportCapabilities({ isAdmin: false, isProjectScope: true, userProject: '乙项目', projects })
    expect(c.scopeLabel).toBe('仅本项目（乙项目）')
    expect(c.salaryProjects).toEqual(['乙项目'])
    expect(c.attendanceProjects).toEqual(['乙项目'])
    expect(c.project).toBe('乙项目')
    expect(c.defaultAttendanceProject).toBe('乙项目')
    expect(c.canExportCompanyWide).toBe(false)
    expect(c.projectTypes.map((t) => t.value)).toEqual(['staff', 'case'])
  })

  it('项目账号未绑定项目：不给跨项目选项，导出直接报错而不是发请求吃 403', () => {
    const c = exportCapabilities({ isProjectScope: true, userProject: '', projects })
    expect(c.salaryProjects).toEqual([])
    expect(c.canExportCompanyWide).toBe(false)
    expect(() => projectExportTarget('staff', '2026-11', '甲项目', false, true, '')).toThrow(/未绑定项目/)
  })

  it('项目账号传别的项目也会被强制改回本项目（防越权）', () => {
    const r = projectExportTarget('staff', '2026-11', '乙项目', false, true, '甲项目')
    expect(r.url).toContain('project=' + encodeURIComponent('甲项目'))
    expect(r.filename.startsWith('甲项目_')).toBe(true)
    expect(r.filename).not.toContain('乙项目')
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
  it('项目账号未绑定项目时考勤导出报错（不发请求吃 403）', () => {
    expect(() => attendanceExportTarget({
      ym: '2026-11', project: '', personType: 'all', mode: 'bundle', isProjectScope: true,
    })).toThrow(/未绑定项目/)
  })
  it('未知人员类型/打包方式报错', () => {
    expect(() => attendanceExportTarget({ ym: '2026-11', personType: 'alien' })).toThrow(/人员类型/)
    expect(() => attendanceExportTarget({ ym: '2026-11', mode: 'alien' })).toThrow(/打包方式/)
  })
  it('人员类型清单包含全部/管理/案场', () => {
    expect(ATTENDANCE_EXPORT_TYPES.map((t) => t.value)).toEqual(['all', 'manager', 'case'])
  })
})
