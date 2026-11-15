import { describe, it, expect } from 'vitest'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'

// 数据导出页曾同时出现两个「核算月份」控件（导出条件卡 + 考勤卡），都绑 ui.month。
// 这里直接对 SFC 源码做结构断言，锁住「全页只有一个月份选择器」且参数链路不断。
const src = readFileSync(resolve(process.cwd(), 'src/modules/hr/Export.vue'), 'utf8')

const idx = (text) => {
  const i = src.indexOf(text)
  expect(i).toBeGreaterThan(-1)
  return i
}
const salarySeg = src.slice(idx('工资表导出'), idx('考勤导出'))
const attendanceSeg = src.slice(idx('考勤导出'), idx('将生成的文件'))

describe('Export.vue 月份控件唯一性与参数链路', () => {
  it('全页只有一个月份选择器', () => {
    expect((src.match(/type="month"/g) || []).length).toBe(1)
    expect((src.match(/v-model="ui\.month"/g) || []).length).toBe(1)
  })

  it('唯一的月份控件在工资表导出卡内，并绑定 ui.month', () => {
    expect(salarySeg).toMatch(/type="month"\s+v-model="ui\.month"/)
  })

  it('考勤导出不再有独立月份控件，但明确复用同一月份', () => {
    expect(attendanceSeg).not.toContain('type="month"')
    expect(attendanceSeg).toContain('{{ ui.month }}')
  })

  it('已删除顶部「导出条件」卡', () => {
    expect(src).not.toContain('导出条件')
  })

  it('工资类导出的 ym 取自 ui.month，请求与文件名同源', () => {
    expect(src).toMatch(/const ym = ui\.month/)
    expect(src).toContain('/api/export/summary?ym=${ym}')
    expect(src).toContain('`全部项目工资表_${ui.month}.zip`')
  })

  it('考勤导出的 ym 同样取自 ui.month', () => {
    expect(src).toMatch(/ym: ui\.month/)
  })

  it('切换月份会触发考勤只读预检', () => {
    expect(src).toMatch(/watch\(\(\) => ui\.month, \(\) => \{ runPrecheck\(\) \}\)/)
  })
})
