import { describe, it, expect } from 'vitest'
import {
  VIRTUAL_ATT_GROUPS,
  isVirtualAttGroup,
  virtualGroupLabel,
  attProjectLabel,
  attBlockedAction,
  pickProjectStatus,
} from './attendanceGroups'

describe('考勤虚拟选项', () => {
  it('哨兵值与后端约定一致，不能随意改动', () => {
    expect(VIRTUAL_ATT_GROUPS).toEqual([
      { value: '__managers__', label: '管理人员' },
      { value: '__case__', label: '案场人员' },
    ])
  })

  it('识别虚拟选项', () => {
    expect(isVirtualAttGroup('__managers__')).toBe(true)
    expect(isVirtualAttGroup('__case__')).toBe(true)
    expect(isVirtualAttGroup('甲项目')).toBe(false)
    expect(isVirtualAttGroup('')).toBe(false)
    expect(isVirtualAttGroup(undefined)).toBe(false)
  })

  it('虚拟值翻译为中文组名，真实项目名原样返回', () => {
    expect(virtualGroupLabel('__managers__')).toBe('管理人员')
    expect(virtualGroupLabel('__case__')).toBe('案场人员')
    expect(virtualGroupLabel('甲项目')).toBe('')
    expect(attProjectLabel('__case__')).toBe('案场人员')
    expect(attProjectLabel('甲项目')).toBe('甲项目')
  })
})

describe('attBlockedAction 查看/锁定/导出按钮启用口径（虚拟项与各项目统一）', () => {
  it('虚拟汇总选项：始终禁用并提示选择具体项目', () => {
    expect(attBlockedAction({ isVirtual: true, hasData: true })).toContain('具体项目')
    expect(attBlockedAction({ isVirtual: true, hasData: false })).toContain('具体项目')
  })

  it('具体项目未上传考勤：禁用并提示先上传', () => {
    expect(attBlockedAction({ isVirtual: false, hasData: false })).toContain('尚未上传')
  })

  it('具体项目已上传考勤：放行（返回空串）', () => {
    expect(attBlockedAction({ isVirtual: false, hasData: true })).toBe('')
  })
})

describe('pickProjectStatus 从 status 接口结果取单项目状态', () => {
  const list = [
    { project: '甲项目', has_data: true, locked: false },
    { project: '乙项目', has_data: false, locked: false },
  ]
  it('命中项目返回其状态', () => {
    expect(pickProjectStatus(list, '甲项目')?.has_data).toBe(true)
    expect(pickProjectStatus(list, '乙项目')?.has_data).toBe(false)
  })
  it('未命中（如项目已停用/接口异常）按无数据处理', () => {
    expect(pickProjectStatus(list, '不存在')).toEqual({ has_data: false, locked: false })
    expect(pickProjectStatus(null, '甲项目')).toEqual({ has_data: false, locked: false })
  })
})
