import { describe, it, expect } from 'vitest'
import { buildAttendanceErrorNotice, buildAttendancePreviewNotice, buildAttendanceBusyNotice } from './attendanceNotice'

function errWith(payload, message = '') {
  const e = new Error(message || payload.error || '上传失败')
  e.payload = payload
  return e
}

describe('buildAttendanceErrorNotice（考勤校验拒单）', () => {
  it('按结构化条目分组并给出计数，仅统计列不一致时允许仍然上传', () => {
    const items = Array.from({ length: 15 }, (_, i) => ({
      code: 'stat_mismatch', row: i + 7, name: `员工${i + 1}`, label: '实际出勤',
      filled: 24, counted: 25, reason: '实际出勤列填 24，按符号统计为 25', message: '第7行 员工1：实际出勤列填 24，按符号统计为 25',
    }))
    const n = buildAttendanceErrorNotice(errWith({ error_items: items, error_total: 15, can_force: true }), { context: 'a.xlsx · 项目A · 2026-10' })
    expect(n.kind).toBe('warn')
    expect(n.danger).toHaveLength(15)
    expect(n.danger[0]).toMatchObject({ name: '员工1', tag: '第7行' })
    expect(n.danger[0].reason).toBe('实际出勤列填 24，按符号统计为 25')
    expect(n.pill).toBe('15 项需处理')
    expect(n.context).toContain('a.xlsx')
    expect(n.actions.force).toBe(true)
    expect(n.actions.cancel).toBe(true)
    expect(n.dangerHint).toContain('统计列可留空')
  })

  it('非统计列错误不提供仍然上传入口，且不重复长解释', () => {
    const n = buildAttendanceErrorNotice(errWith({
      error_items: [{ code: 'unknown_staff', name: '张三', reason: '不在本项目人员档案中', message: '人员不在本项目人员档案中：张三' }],
      can_force: false,
    }))
    expect(n.actions.force).toBe(false)
    expect(n.danger[0].reason).toBe('不在本项目人员档案中')
    expect(JSON.stringify(n)).not.toContain('请核对格子符号')
  })

  it('旧接口无 error_items 时回退为单条，不丢错误信息', () => {
    const n = buildAttendanceErrorNotice(errWith({ error: '项目不存在' }, '项目不存在'))
    expect(n.danger).toHaveLength(1)
    expect(n.danger[0].reason).toBe('项目不存在')
  })
})

describe('buildAttendancePreviewNotice（校验通过/上传成功）', () => {
  const base = { context: 'a.xlsx · 项目A · 2026-10', ym: '2026-10', isVirtual: false }

  it('校验通过时汇总覆盖与核算风险，并给出确认操作', () => {
    const n = buildAttendancePreviewNotice({
      count: 43, names: ['甲', '乙'], overwrite: true, has_calc: true,
      info_items: [{ code: 'resigned_in_month', name: '丙', reason: '本月离职（离职日期 2026-10-15），请确认考勤天数' }],
    }, { ...base, mode: 'preview' })
    expect(n.title).toBe('校验通过：43 人')
    expect(n.info.map((i) => i.name)).toEqual(['整表覆盖', '已有核算', '丙'])
    expect(n.chips.some((c) => c.text === '已有核算结果')).toBe(true)
    expect(n.actions.confirm).toBe(true)
    expect(n.footText).toContain('本次涉及')
  })

  it('上传成功后不再提供确认按钮，提醒重新核算', () => {
    const n = buildAttendancePreviewNotice({ count: 43, names: [], overwrite: true, has_calc: true }, { ...base, mode: 'uploaded' })
    expect(n.title).toBe('上传成功：43 人')
    expect(n.actions.confirm).toBe(false)
    expect(n.info[1].reason).toContain('重新核算')
  })

  it('完全正常的上传不产生提示组', () => {
    const n = buildAttendancePreviewNotice({ count: 5, names: ['甲'] }, { ...base, mode: 'uploaded' })
    expect(n.info).toHaveLength(0)
    expect(n.footText).toBe('上传完成。')
  })
})

describe('buildAttendanceBusyNotice', () => {
  it('校验中只保留标题与上下文', () => {
    const n = buildAttendanceBusyNotice('正在校验考勤表…', 'a.xlsx · 项目A')
    expect(n.pill).toBe('请稍候')
    expect(n.danger).toHaveLength(0)
    expect(n.actions).toEqual({})
  })
})
