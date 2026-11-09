// 回归测试：清单外审核页「归入标准商品」下拉选中后必须回显商品名称。
// 历史 bug：legacy.css 的全局 input[type=text] 规则命中了 Element Plus 的 .el-select__input，
// 白色底/边框把 z-index:-1 的选中项 label 盖住 → 下拉框显示空白（值其实是对的）。
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { createApp, nextTick } from 'vue'
import ElementPlus from 'element-plus'
import { createPinia, setActivePinia } from 'pinia'
import fs from 'node:fs'
import path from 'node:path'

vi.mock('@/api/client', () => ({
  api: vi.fn(async (url) => {
    if (url.startsWith('/api/purchase/admin/customs')) {
      return {
        data: [
          { id: 1, project_name: 'P1', line: 'L', item_name: '螺丝', brand: 'B', spec: 'M8', unit: '个', category: 'C' },
        ],
      }
    }
    if (url.startsWith('/api/purchase/products')) {
      return {
        data: [
          { id: 7, name: '六角螺栓', spec: 'M8', brand: '国标' },
          { id: 8, name: '螺母', spec: 'M8', brand: '国标' },
        ],
        total: 2,
      }
    }
    return { data: [] }
  }),
}))
vi.mock('@/utils/toast', () => ({ toast: vi.fn() }))

import Customs from './Customs.vue'

globalThis.ResizeObserver = class {
  observe() {}
  unobserve() {}
  disconnect() {}
}

const flush = async (n = 10) => {
  for (let i = 0; i < n; i++) await nextTick()
  await new Promise((r) => setTimeout(r, 100))
}

describe('清单外审核 - 归入标准商品下拉回显', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    document.body.innerHTML = ''
  })

  it('选中商品后，下拉的 label 槽显示“名称（规格）·品牌”，且搜索词已清空', async () => {
    const app = createApp(Customs)
    app.use(ElementPlus)
    const host = document.createElement('div')
    document.body.appendChild(host)
    app.mount(host)
    await flush()

    const selectRoot = host.querySelector('.el-select')
    expect(selectRoot).toBeTruthy()

    // 展开下拉
    selectRoot.querySelector('.el-select__wrapper').dispatchEvent(new MouseEvent('click', { bubbles: true }))
    await nextTick()

    // 输入关键字做本地过滤
    const input = selectRoot.querySelector('input')
    input.value = '螺栓'
    input.dispatchEvent(new Event('input', { bubbles: true }))
    await flush()

    const items = [...document.querySelectorAll('.el-select-dropdown__item')].filter((el) => el.style.display !== 'none')
    expect(items.length).toBeGreaterThan(0)
    const target = items.find((el) => el.textContent.includes('六角螺栓'))
    expect(target).toBeTruthy()
    target.dispatchEvent(new MouseEvent('click', { bubbles: true }))
    await flush()

    // 关键断言：选中项文字必须回显，不能是空
    const placeholder = selectRoot.querySelector('.el-select__placeholder')
    expect(placeholder).toBeTruthy()
    expect(placeholder.textContent.trim()).toBe('六角螺栓（M8）·国标')
    // 搜索词必须被清空，否则 label 会被输入框内容顶掉/盖住
    expect(input.value).toBe('')

    app.unmount()
  })
})

describe('legacy.css 全局输入框样式不得命中 Element Plus 下拉内部输入框', () => {
  const cssPath = path.resolve(process.cwd(), 'src/styles/legacy.css')
  const css = fs.readFileSync(cssPath, 'utf8')

  it('基础输入框规则排除 .el-select__input', () => {
    const rule = css.match(/^input\[type=text\][^{}]*\{[^}]*\}/m)
    expect(rule).toBeTruthy()
    const selectorList = rule[0].slice(0, rule[0].indexOf('{'))
    selectorList
      .split(',')
      .map((s) => s.trim())
      .filter((s) => s.startsWith('input['))
      .forEach((s) => {
        expect(s).toContain(':not(.el-select__input)')
      })
  })

  it('focus 规则同样排除 .el-select__input', () => {
    const rule = css.match(/^input:focus[^{}]*\{[^}]*\}/m)
    expect(rule).toBeTruthy()
    expect(rule[0].slice(0, rule[0].indexOf('{'))).toContain(':not(.el-select__input)')
  })
})
