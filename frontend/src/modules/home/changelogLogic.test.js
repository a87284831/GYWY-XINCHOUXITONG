import { describe, it, expect, beforeEach } from 'vitest'
import {
  READ_KEY,
  parseVersion,
  compareVersions,
  unreadEntries,
  latestVersion,
  getReadVersion,
  markRead,
} from './changelogLogic.js'

const E = (version, items = [{ category: '新增', text: 'x' }]) => ({ version, date: '2026-10-23', commit: 'abc1234', items })

describe('parseVersion', () => {
  it('解析 vYYYY.M.D.N', () => {
    expect(parseVersion('v2026.10.23.1')).toEqual([2026, 10, 23, 1])
  })
  it('无 v 前缀也能解析', () => {
    expect(parseVersion('2026.9.1.12')).toEqual([2026, 9, 1, 12])
  })
  it('空值/非法段按 0', () => {
    expect(parseVersion('')).toEqual([0])
    expect(parseVersion(null)).toEqual([0])
    expect(parseVersion('v2026.x.1')).toEqual([2026, 0, 1])
  })
})

describe('compareVersions', () => {
  it('同日序号比较', () => {
    expect(compareVersions('v2026.10.23.2', 'v2026.10.23.1')).toBe(1)
    expect(compareVersions('v2026.10.23.1', 'v2026.10.23.2')).toBe(-1)
    expect(compareVersions('v2026.10.23.1', 'v2026.10.23.1')).toBe(0)
  })
  it('跨日/跨月比较（数值比较非字典序：10 > 9）', () => {
    expect(compareVersions('v2026.10.24.1', 'v2026.10.23.9')).toBe(1)
    expect(compareVersions('v2026.10.1.1', 'v2026.9.30.3')).toBe(1)
  })
  it('段数不同缺失段按 0', () => {
    expect(compareVersions('v2026.10.23', 'v2026.10.23.0')).toBe(0)
    expect(compareVersions('v2026.10.23.1', 'v2026.10.23')).toBe(1)
  })
})

describe('unreadEntries', () => {
  const entries = [E('v2026.10.23.3'), E('v2026.10.23.2'), E('v2026.10.23.1')]

  it('从未读过 → 全部未读', () => {
    expect(unreadEntries(entries, '').map((e) => e.version)).toEqual([
      'v2026.10.23.3', 'v2026.10.23.2', 'v2026.10.23.1',
    ])
  })
  it('只返回严格更新的版本，保持倒序', () => {
    expect(unreadEntries(entries, 'v2026.10.23.1').map((e) => e.version)).toEqual([
      'v2026.10.23.3', 'v2026.10.23.2',
    ])
  })
  it('已读到最新 → 无未读', () => {
    expect(unreadEntries(entries, 'v2026.10.23.3')).toEqual([])
  })
  it('已读基线比列表还新（回滚场景）→ 无未读', () => {
    expect(unreadEntries(entries, 'v2026.10.24.1')).toEqual([])
  })
  it('非法入参不炸', () => {
    expect(unreadEntries(null, 'v1')).toEqual([])
    expect(unreadEntries(undefined, '')).toEqual([])
  })
})

describe('latestVersion', () => {
  it('按版本号取最大，不依赖传入顺序', () => {
    expect(latestVersion([E('v2026.10.23.1'), E('v2026.10.23.9'), E('v2026.10.23.2')])).toBe('v2026.10.23.9')
  })
  it('空列表返回空串', () => {
    expect(latestVersion([])).toBe('')
    expect(latestVersion(null)).toBe('')
  })
})

describe('已读标记（localStorage）', () => {
  beforeEach(() => localStorage.clear())

  it('markRead 写入 + getReadVersion 读回', () => {
    expect(getReadVersion()).toBe('')
    markRead('v2026.10.23.1')
    expect(localStorage.getItem(READ_KEY)).toBe('v2026.10.23.1')
    expect(getReadVersion()).toBe('v2026.10.23.1')
  })
  it('空版本不写入（防止已读基线被清空）', () => {
    markRead('')
    expect(localStorage.getItem(READ_KEY)).toBeNull()
  })
  it('标记后再过滤：无未读', () => {
    const entries = [E('v2026.10.23.2'), E('v2026.10.23.1')]
    markRead(latestVersion(entries))
    expect(unreadEntries(entries, getReadVersion())).toEqual([])
  })
})
