import { describe, it, expect } from 'vitest'
import { syncReportModel } from './dingtalkSyncReport'

describe('钉钉同步结果展示模型', () => {
  it('干净同步：绿色标题、无警告、统计行含花名册口径', () => {
    const m = syncReportModel({ dingtalk_users: 10, roster: 10 })
    expect(m.hasWarning).toBe(false)
    expect(m.title).toBe('同步完成')
    expect(m.warningLines).toEqual([])
    const rowMap = Object.fromEntries(m.rows)
    expect(rowMap['花名册字段异常']).toBe(0)
    expect(rowMap['花名册缺失']).toBe(0)
    expect(rowMap['钉钉在职人员']).toBe(10)
  })

  it('字段异常：橙色口径、标题带项数、列出人员明细', () => {
    const m = syncReportModel({
      roster: 2,
      roster_field_errors: 2,
      roster_field_error_items: [
        { name: '王保洁', detail: '月度薪资标准 = true' },
        { name: '王保洁', detail: '月度基本工资 = false' },
      ],
    })
    expect(m.hasWarning).toBe(true)
    expect(m.title).toBe('同步完成（2 项花名册字段异常，已跳过保留原值）')
    expect(m.warningLines).toEqual([
      '· 王保洁：月度薪资标准 = true',
      '· 王保洁：月度基本工资 = false',
    ])
  })

  it('明细超过 10 人只展示前 10 条并给出剩余数量', () => {
    const items = Array.from({ length: 13 }, (_, i) => ({ name: `员工${i}`, detail: '月度薪资标准 = true' }))
    const m = syncReportModel({ roster_field_errors: 13, roster_field_error_items: items })
    expect(m.warningLines).toHaveLength(10)
    expect(m.warningTruncated).toBe(3)
  })

  it('仅有花名册缺失也算警告口径', () => {
    const m = syncReportModel({ roster_missing: 1 })
    expect(m.hasWarning).toBe(true)
    expect(m.title).toBe('同步完成')
    expect(m.missingCount).toBe(1)
  })

  it('空报告不报错', () => {
    const m = syncReportModel(undefined)
    expect(m.title).toBe('同步完成')
    expect(m.rows.length).toBe(10)
  })
})
