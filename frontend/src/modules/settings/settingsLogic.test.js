// 系统设置页组纯逻辑测试（复刻 settingsPerm/pageSalarySettings/符号库/公式验证，app.js:3249-4265/6651-7205）
import { describe, it, expect } from 'vitest'
import {
  roleIcon, roleColor, cnFormula, GROSS_DEFAULT, NET_DEFAULT,
  STD_TAX_BRACKETS, taxRatePct, taxAddBracket, taxNormalize,
  evalFormulaSafe, normalizeCustomFields, visibleTabs,
  payRuleToDraft, draftToPayRule, PAY_GRADES,
} from './settingsLogic'

describe('roleIcon / roleColor', () => {
  it('内置角色取映射，自定义取名称首字与默认色', () => {
    expect(roleIcon('admin', '管理员')).toBe('超')
    expect(roleIcon('project', '')).toBe('项')
    expect(roleIcon('custom_1', '财务')).toBe('财')
    expect(roleIcon('custom_1', '')).toBe('角')
    expect(roleColor('admin')).toBe('#2B7CF6')
    expect(roleColor('custom_1')).toBe('#9EACEA')
  })
})

describe('cnFormula 公式变量中文翻译', () => {
  it('已知变量翻译，未知保留', () => {
    expect(cnFormula('base_pay + perf_pay - soc_total')).toBe('应发基本工资 + 应发绩效工资 - 五险一金合计')
    expect(cnFormula('foo_bar + base_pay')).toBe('foo_bar + 应发基本工资')
    expect(cnFormula('')).toBe('')
  })
  it('默认公式常量与旧版一致', () => {
    expect(GROSS_DEFAULT).toContain('应发基本工资 + 应发绩效工资 + 病假工资')
    expect(NET_DEFAULT).toBe('应发合计 - 五险一金合计 - 本月个税 - 已发福利')
  })
})

describe('个税级距操作', () => {
  it('STD_TAX_BRACKETS 标准 7 级与旧版一致', () => {
    expect(STD_TAX_BRACKETS).toHaveLength(7)
    expect(STD_TAX_BRACKETS[0]).toEqual([36000, 0.03, 0])
    expect(STD_TAX_BRACKETS[6]).toEqual([99999999999, 0.45, 181920])
  })
  it('taxRatePct 比率转百分比（避免浮点误差）', () => {
    expect(taxRatePct(0.03)).toBe(3)
    expect(taxRatePct(0.1)).toBe(10)
    expect(taxRatePct(0.45)).toBe(45)
  })
  it('taxAddBracket 在末档前插入新档：上限取 prevCap×2 与 +3.6万 的大者（prevCap=末档前一位），税率 +5% 封顶 45%', () => {
    const next = taxAddBracket(STD_TAX_BRACKETS)
    expect(next).toHaveLength(8)
    expect(next[7][0]).toBe(99999999999)
    expect(next[6]).toEqual([1920000, 0.45, 0]) // prevCap=arr[5]=960000 → newCap=1920000
    // 纯函数：原数组不变
    expect(STD_TAX_BRACKETS).toHaveLength(7)
  })
  it('taxAddBracket 仅一档时按 36000 基准且 splice(0) 插在最前（旧版行为）', () => {
    const next = taxAddBracket([[36000, 0.03, 0]])
    expect(next[0]).toEqual([72000, 0.08, 0])
    expect(next[1]).toEqual([36000, 0.03, 0])
  })
  it('taxNormalize 过滤非法税率并按上限排序', () => {
    const out = taxNormalize([
      [144000, 0.10, 2520],
      [36000, 1.5, 0], // 150% 非法 → 过滤（旧版仅滤 >100% 与 ≤0）
      [72000, 0.2, 1692],
      [96000, 0, 100], // 0 非法 → 过滤
    ])
    expect(out.map((b) => b[0])).toEqual([72000, 144000])
    expect(out[0]).toEqual([72000, 0.2, 1692])
  })
})

describe('evalFormulaSafe', () => {
  const vars = { 应发基本工资: 5000, 应发绩效工资: 1000, 五险一金合计: 500, 本月个税: 30, 应发合计: 5470 }
  it('正常四则运算', () => {
    expect(evalFormulaSafe('应发基本工资 + 应发绩效工资', vars)).toBe(6000)
    expect(evalFormulaSafe('应发合计 - 五险一金合计 - 本月个税', vars)).toBe(4940)
    expect(evalFormulaSafe('(5000 + 1000) / 2', vars)).toBe(3000)
  })
  it('空白可读性：允许空格', () => {
    expect(evalFormulaSafe(' 5000 + 1000 ', vars)).toBe(6000)
  })
  it('未知变量抛错', () => {
    expect(() => evalFormulaSafe('不知道 + 1', vars)).toThrow(/未知项目/)
  })
  it('非法字符抛错', () => {
    expect(() => evalFormulaSafe('5000; alert(1)', vars)).toThrow(/非法字符/)
    expect(() => evalFormulaSafe('5000 + "x"', vars)).toThrow(/非法字符/)
  })
})

describe('normalizeCustomFields', () => {
  it('过滤空名并规整字段', () => {
    const out = normalizeCustomFields([
      { name: ' 高温补贴 ', type: 'deduction', source: 'attendance', enabled: true, default: '300.5' },
      { name: '   ', enabled: true },
      null,
    ])
    expect(out).toHaveLength(1)
    expect(out[0]).toEqual({ name: '高温补贴', type: 'deduction', source: 'attendance', enabled: true, default: 300.5 })
  })
  it('缺省值补默认', () => {
    expect(normalizeCustomFields([{ name: 'x' }])).toEqual([
      { name: 'x', type: 'subsidy', source: 'fixed', enabled: false, default: 0 },
    ])
  })
})

describe('visibleTabs', () => {
  const TABS = [
    { key: 'perm', perm: 'users' },
    { key: 'salarySettings', perm: 'rules' },
    { key: 'logs', perm: 'logs' },
  ]
  const can = (perm) => perm === 'users' || perm === 'rules'
  it('按 can 过滤 tab', () => {
    expect(visibleTabs(TABS, can).map((t) => t.key)).toEqual(['perm', 'salarySettings'])
  })
})

describe('绩效发放规则 payRuleToDraft / draftToPayRule', () => {
  it('PAY_GRADES 三档固定且与后端逐字一致', () => {
    expect(PAY_GRADES).toEqual(['专员级', '主管级', '经理级'])
  })
  it('缺省/未知 cycle 回落 monthly，并初始化全部档位编辑态', () => {
    const d = payRuleToDraft(undefined)
    expect(d.cycle).toBe('monthly')
    expect(Object.keys(d.ratios)).toEqual(PAY_GRADES)
    expect(Object.keys(d.modes)).toEqual(PAY_GRADES)
    expect(payRuleToDraft({ cycle: 'weird' }).cycle).toBe('monthly')
  })
  it('quarterly 往返：比例逐档保留', () => {
    const src = { cycle: 'quarterly', levels: { 经理级: { quarter_ratio: 0.8, half_year_ratio: 0.5 } } }
    const d = payRuleToDraft(src)
    expect(d.cycle).toBe('quarterly')
    expect(d.ratios['经理级']).toEqual({ quarter_ratio: 0.8, half_year_ratio: 0.5 })
    expect(d.ratios['主管级']).toEqual({ quarter_ratio: 0, half_year_ratio: 0 })
    const out = draftToPayRule(d)
    expect(out).toEqual({
      cycle: 'quarterly',
      levels: {
        专员级: { quarter_ratio: 0, half_year_ratio: 0 },
        主管级: { quarter_ratio: 0, half_year_ratio: 0 },
        经理级: { quarter_ratio: 0.8, half_year_ratio: 0.5 },
      },
    })
  })
  it('quarter_grade：仅 mode 单选，往返不含任何比例键；mode 缺省按 monthly', () => {
    const src = { cycle: 'quarter_grade', levels: { 经理级: { mode: 'quarter' } } }
    const d = payRuleToDraft(src)
    expect(d.cycle).toBe('quarter_grade')
    expect(d.modes['经理级']).toBe('quarter')
    expect(d.modes['主管级']).toBe('monthly')
    expect(d.modes['专员级']).toBe('monthly')
    const out = draftToPayRule(d)
    expect(out.cycle).toBe('quarter_grade')
    expect(out.levels['经理级']).toEqual({ mode: 'quarter' })
    expect(out.levels['主管级']).toEqual({ mode: 'monthly' })
    // 后端契约：季度绩效法 levels 内绝不允许出现比例键
    for (const g of PAY_GRADES) {
      expect(out.levels[g]).not.toHaveProperty('quarter_ratio')
      expect(out.levels[g]).not.toHaveProperty('half_year_ratio')
    }
  })
  it('monthly 序列化保持 ratio:1.0（旧配置字节语义不变）', () => {
    expect(draftToPayRule(payRuleToDraft({ cycle: 'monthly', ratio: 0.5 })))
      .toEqual({ cycle: 'monthly', ratio: 1.0 })
  })
})
