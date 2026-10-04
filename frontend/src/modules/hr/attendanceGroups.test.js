import { describe, it, expect } from 'vitest'
import { VIRTUAL_ATT_GROUPS, isVirtualAttGroup, virtualGroupLabel, attProjectLabel } from './attendanceGroups'

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
