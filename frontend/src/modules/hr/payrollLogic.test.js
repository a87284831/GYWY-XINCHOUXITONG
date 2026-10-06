import { describe, it, expect } from 'vitest'
import {
  summaryTotals, computeAdjustChanges, payTotalRow, payDeptOptions, filterPayRows,
  isZeroPayRow, groupNotices, archiveBlockerText, formatLogChanges, formatLogTime,
  adjustDisabledReason, lockedRecalcConfirmText,
} from './payrollLogic'

describe('summaryTotals（复刻 renderSummary 合计口径）', () => {
  it('累加各项目数值并按预算计算执行率', () => {
    const items = [
      { headcount: 10, gross: 1000, net: 800, month_budget: 2000, annual_budget: 24000, ytd_gross: 4000 },
      { headcount: 5, gross: 500, net: 400, month_budget: 0, annual_budget: 6000, ytd_gross: 1000 },
    ]
    const t = summaryTotals(items)
    expect(t.headcount).toBe(15)
    expect(t.gross).toBe(1500)
    expect(t.net).toBe(1200)
    expect(t.month_budget).toBe(2000)
    expect(t.annual_budget).toBe(30000)
    expect(t.ytd_gross).toBe(5000)
    expect(t.month_rate).toBeCloseTo(0.75)
    expect(t.annual_rate).toBeCloseTo(5000 / 30000)
  })

  it('预算为 0 时执行率按 0 显示', () => {
    const t = summaryTotals([{ headcount: 1, gross: 100, net: 80, month_budget: 0, annual_budget: 0, ytd_gross: 100 }])
    expect(t.month_rate).toBe(0)
    expect(t.annual_rate).toBe(0)
  })

  it('空列表执行率为 0', () => {
    const t = summaryTotals([])
    expect(t.headcount).toBe(0)
    expect(t.month_rate).toBe(0)
  })
})

describe('computeAdjustChanges（复刻 saveAdjust 差异收集）', () => {
  const baseRow = {
    staff_id: 7, req_att: 22, act_att: 21, perf_att: 21, coef: 1,
    night: 100, meal: 200, title_sub: 0, reward: 0, welfare: 0,
    punish: 0, late_d: 0, miss_d: 0, other_d: 0, uniform_d: 0,
    pen: 300, med: 100, une: 20, house: 200, big: 10,
    spec_rent: 0, spec_loan: 0, spec_child: 1000, spec_elder: 0, spec_edu: 0, spec_baby: 0,
    actual_tax: 50, remark: '',
  }

  it('仅收集发生变化的数值字段（空输入跳过）', () => {
    const values = { req_att: '21', night: '150', meal: '', coef: '1' }
    const changes = computeAdjustChanges(baseRow, values, '')
    expect(changes).toEqual({ req_att: 21, night: 150 })
  })

  it('任一专项附加变化时六项全量回传（空按 0）', () => {
    const values = { spec_child: '1500', spec_rent: '', spec_loan: '', spec_elder: '', spec_edu: '', spec_baby: '' }
    const changes = computeAdjustChanges(baseRow, values, '')
    expect(changes).toEqual({
      spec_rent: 0, spec_loan: 0, spec_child: 1500, spec_elder: 0, spec_edu: 0, spec_baby: 0,
    })
  })

  it('专项附加无变化时不回传任何专项字段', () => {
    const values = { spec_child: '1000', night: '80' }
    const changes = computeAdjustChanges(baseRow, values, '')
    expect(changes).toEqual({ night: 80 })
  })

  it('remark 变化时纳入 changes', () => {
    const changes = computeAdjustChanges(baseRow, {}, '备注更新')
    expect(changes.remark).toBe('备注更新')
  })

  it('无任何变化返回空对象', () => {
    const values = { req_att: '22', night: '100' }
    const changes = computeAdjustChanges(baseRow, values, '')
    expect(changes).toEqual({})
  })

  it('reason 为空时由调用方处理——本函数只负责差异（reason 不入 changes）', () => {
    const changes = computeAdjustChanges(baseRow, { night: '80' }, '', '数据修正')
    expect(changes).toEqual({ night: 80 })
    expect(changes.reason).toBeUndefined()
  })

  it('非数字输入跳过', () => {
    const changes = computeAdjustChanges(baseRow, { night: 'abc' }, '')
    expect(changes).toEqual({})
  })
})

describe('payTotalRow（复刻 payTotalHtml 合计列）', () => {
  it('按字段求和并返回列位置映射', () => {
    const rows = [
      { base_pay: 1000, perf_pay: 200, sick_pay: 50, night: 10, meal: 20, title_sub: 1, reward: 5, welfare: 2, punish: 3, late_d: 4, miss_d: 5, other_d: 6, uniform_d: 7, gross: 1200, soc_total: 300, spec_total: 1000, actual_tax: 20, net: 880 },
      { base_pay: 500, perf_pay: 100, sick_pay: 0, night: 0, meal: 0, title_sub: 0, reward: 0, welfare: 0, punish: 0, late_d: 0, miss_d: 0, other_d: 0, uniform_d: 0, gross: 600, soc_total: 150, spec_total: 1000, actual_tax: 5, net: 445 },
    ]
    const t = payTotalRow(rows)
    expect(t.count).toBe(2)
    expect(t.sums.base_pay).toBe(1500)
    expect(t.sums.gross).toBe(1800)
    expect(t.sums.net).toBe(1325)
    expect(t.sums.spec_total).toBe(2000)
  })

  it('空行合计为 0', () => {
    const t = payTotalRow([])
    expect(t.count).toBe(0)
    expect(t.sums.gross).toBe(0)
  })
})

describe('payDeptOptions（复刻部门下拉选项来源）', () => {
  it('按项目过滤并去重、剔除空部门', () => {
    const rows = [
      { project: 'A', department: '客服部' },
      { project: 'A', department: '客服部' },
      { project: 'A', department: '' },
      { project: 'B', department: '工程部' },
    ]
    expect(payDeptOptions(rows, 'A')).toEqual(['客服部'])
    expect(payDeptOptions(rows, '')).toEqual(['客服部', '工程部'])
  })
})

describe('filterPayRows（复刻项目+部门筛选）', () => {
  const rows = [
    { project: 'A', department: '客服部' },
    { project: 'A', department: '工程部' },
    { project: 'B', department: '客服部' },
  ]
  it('仅项目筛选', () => {
    expect(filterPayRows(rows, 'A', '')).toHaveLength(2)
  })
  it('项目+部门联合筛选', () => {
    expect(filterPayRows(rows, 'A', '客服部')).toHaveLength(1)
  })
  it('无筛选返回全部', () => {
    expect(filterPayRows(rows, '', '')).toHaveLength(3)
  })
})

describe('isZeroPayRow（0 工资/负工资行高亮判断）', () => {
  it('net 为 0 时高亮', () => {
    expect(isZeroPayRow({ net: 0 })).toBe(true)
  })
  it('net 为负数时高亮', () => {
    expect(isZeroPayRow({ net: -120.5 })).toBe(true)
  })
  it('net 为正数时不高亮', () => {
    expect(isZeroPayRow({ net: 0.01 })).toBe(false)
  })
  it('net 缺失时不高亮（避免误伤）', () => {
    expect(isZeroPayRow({})).toBe(false)
  })
  it('row 为 null 时不高亮（防御性）', () => {
    expect(isZeroPayRow(null)).toBe(false)
  })
})

describe('groupNotices（缺失/警告条目按 level 分组）', () => {
  it('danger 与 info 各归其组并保持原顺序', () => {
    const missing = [{ name: 'A', project: 'P', reason: 'r1', level: 'danger' }]
    const warnings = [{ name: 'B', project: 'P', reason: 'r2', level: 'info' }]
    const { danger, info } = groupNotices(missing, warnings)
    expect(danger).toHaveLength(1)
    expect(danger[0].name).toBe('A')
    expect(info).toHaveLength(1)
    expect(info[0].name).toBe('B')
  })

  it('无 level 键的旧条目一律归 info', () => {
    const { danger, info } = groupNotices([], [{ name: 'C', reason: 'r3' }])
    expect(danger).toEqual([])
    expect(info).toHaveLength(1)
    expect(info[0].name).toBe('C')
  })

  it('空入参返回两个空数组', () => {
    const { danger, info } = groupNotices([], [])
    expect(danger).toEqual([])
    expect(info).toEqual([])
  })

  it('danger 两条按输入顺序排列', () => {
    const missing = [
      { name: 'D1', project: 'P', reason: 'r1', level: 'danger' },
      { name: 'D2', project: 'P', reason: 'r2', level: 'danger' },
    ]
    const { danger } = groupNotices(missing, [])
    expect(danger.map((x) => x.name)).toEqual(['D1', 'D2'])
  })
})

describe('archiveBlockerText（归档确认文案）', () => {
  it('单条带项目：首行条数 + 姓名（项目）：reason + 末尾确认句', () => {
    const text = archiveBlockerText([{ name: '张三', project: '测试项目', reason: '缺系数' }])
    expect(text).toContain('以下 1 条薪资异常未处理：')
    expect(text).toContain('张三（测试项目）：缺系数')
    expect(text).toContain('确认仍要归档吗？忽略异常可能导致错误工资发放。')
  })

  it('project 为空时只写姓名：reason（不带括号）', () => {
    const text = archiveBlockerText([{ name: '李四', project: '', reason: '缺基数' }])
    expect(text).toContain('李四：缺基数')
    expect(text).not.toContain('（')
  })

  it('空数组：含「以下 0 条」+ 末尾确认句', () => {
    const text = archiveBlockerText([])
    expect(text).toContain('以下 0 条薪资异常未处理：')
    expect(text).toContain('确认仍要归档吗？忽略异常可能导致错误工资发放。')
  })
})

describe('formatLogChanges（微调日志字段变化文本）', () => {
  it('单字段：中文名 + 旧值→新值', () => {
    const text = formatLogChanges([{ field: 'reward', old: 0, new: 100 }])
    expect(text).toBe('月度奖励: 0 → 100')
  })

  it('多字段用中文分号连接，保持后端给定顺序', () => {
    const text = formatLogChanges([
      { field: 'actual_tax', old: 180, new: 198 },
      { field: 'miss_d', old: 0, new: 30 },
    ])
    expect(text).toBe('本月实缴个税: 180 → 198；缺卡扣款: 0 → 30')
  })

  it('旧值为 null/undefined 按 0 展示（首次微调）', () => {
    expect(formatLogChanges([{ field: 'meal', old: null, new: 200 }])).toBe('餐补: 0 → 200')
    expect(formatLogChanges([{ field: 'meal', new: 200 }])).toBe('餐补: 0 → 200')
  })

  it('数值去掉多余尾零但保留必要小数', () => {
    expect(formatLogChanges([{ field: 'coef', old: 1, new: 0.8 }])).toBe('绩效系数: 1 → 0.8')
  })

  it('备注字段展示文本新旧值', () => {
    expect(formatLogChanges([{ field: 'remark', old: '', new: '已沟通' }])).toBe('备注: (空) → 已沟通')
  })

  it('空变化返回空串', () => {
    expect(formatLogChanges([])).toBe('')
    expect(formatLogChanges(null)).toBe('')
  })
})

describe('formatLogTime（微调日志时间展示）', () => {
  it('去掉秒，保留到分钟', () => {
    expect(formatLogTime('2026-10-06 16:34:44')).toBe('2026-10-06 16:34')
  })
  it('空值安全返回空串', () => {
    expect(formatLogTime('')).toBe('')
    expect(formatLogTime(null)).toBe('')
  })
})

describe('adjustDisabledReason（微调按钮禁用原因，问题5）', () => {
  it('管理员且未锁定：可微调，返回 null', () => {
    expect(adjustDisabledReason(false, true)).toBeNull()
  })
  it('已锁定：任何角色均返回锁定提示', () => {
    expect(adjustDisabledReason(true, true)).toContain('锁定')
    expect(adjustDisabledReason(true, false)).toContain('锁定')
  })
  it('非管理员（项目账号）未锁定：返回权限提示', () => {
    expect(adjustDisabledReason(false, false)).toContain('权限')
  })
})

describe('lockedRecalcConfirmText（重算遇锁定行 409 的二次确认文案）', () => {
  const error = '所选范围内已有 2 人的工资核定锁定；重新核算将覆盖锁定数据。确认要强制重算吗？'
  it('含后端错误文案 + 名单（姓名（项目））+ 总数', () => {
    const text = lockedRecalcConfirmText({
      error,
      locked: [
        { staff_id: 1, name: '张三', project: '甲项目' },
        { staff_id: 2, name: '李四', project: '乙项目' },
      ],
    })
    expect(text).toContain(error)
    expect(text).toContain('张三（甲项目）')
    expect(text).toContain('李四（乙项目）')
    expect(text).toContain('共 2 人')
  })
  it('名单超过 8 人只列前 8 并提示其余数量', () => {
    const locked = Array.from({ length: 10 }, (_, i) => ({ staff_id: i + 1, name: `员${i + 1}`, project: 'P' }))
    const text = lockedRecalcConfirmText({ error, locked })
    expect(text).toContain('员1（P）')
    expect(text).toContain('员8（P）')
    expect(text).not.toContain('员9（P）')
    expect(text).toContain('等共 10 人')
  })
  it('无名单时仅返回错误文案', () => {
    expect(lockedRecalcConfirmText({ error, locked: [] })).toBe(error)
  })
})
