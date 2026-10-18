// 采购填报页真实组件回归测试（2026-10-18 整改后行为基线）
// 挂载真实 FillForm.vue + 内存假后端（语义复刻 app/Purchase/handlers/fill.php）
// 覆盖：P0-1/2 保存校验失败行保留+红框、提交整单拦截；P0-3 admin 已提交行锁定/改单解锁/保存复锁；
//      P0-4 清单外引导与拦截；P1-6 草稿 key 隔离；P1-7 退回行保存不降状态；P1-10 草稿恢复清残留
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { createApp } from 'vue'
import ElementPlus from 'element-plus'

// ---- 共享状态（vi.hoisted 保证在 mock 工厂前初始化） ----
const H = vi.hoisted(() => {
  const state = {
    items: [],          // 假后端表 purchase_items
    seq: 100,
    calls: [],          // API 调用记录
    toasts: [],         // toast 记录
    role: 'project',    // 当前登录角色
    windowOpen: true,
    routeQuery: { month: '2026-10' },
  }
  return { state }
})

// ---- mock api client（复刻 fill.php 状态机语义） ----
vi.mock('@/api/client', () => {
  async function api(path, opts = {}) {
    const method = opts.method || (opts.body ? 'POST' : 'GET')
    H.state.calls.push({ method, path, body: opts.body })
    const u = new URL(path, 'http://x')
    const p = u.pathname
    const month = u.searchParams.get('month') || '2026-10'
    const isAdmin = H.state.role === 'admin'
    const myProject = 2 // 临沂蓝钻庄园

    if (p === '/api/purchase/fill/window') {
      return { ok: true, data: { open: H.state.windowOpen, msg: '填报开放中', window: { start_date: '2026-10-01', end_date: '2026-10-31' } } }
    }
    if (p === '/api/purchase/fill/items' && method === 'GET') {
      const pid = isAdmin ? Number(u.searchParams.get('project_id') || 0) : myProject
      const rows = H.state.items.filter((r) => r.month === month && (!pid || r.project_id === pid))
      return { ok: true, data: rows.map((r) => ({ ...r })) }
    }
    if (p === '/api/purchase/fill/items' && method === 'POST') {
      const b = opts.body || {}
      if (!isAdmin && !H.state.windowOpen) { const e = new Error('填报尚未开始'); e.payload = { ok: false }; throw e }
      const row = {
        id: ++H.state.seq, month: b.month, project_id: isAdmin && b.project_id ? b.project_id : myProject,
        line: b.line, product_id: b.product_id || null, item_name: b.item_name, brand: b.brand || '',
        spec: b.spec || '', unit: b.unit || '', quantity: Number(b.quantity) || 0, stock: Number(b.stock) || 0,
        reason: b.reason || '', use_location: b.use_location || '', remark: b.remark || '',
        is_custom: b.product_id ? 0 : 1, status: b.status === 'submitted' ? 'submitted' : 'draft',
        price: null, return_reason: '', returned_at: null,
      }
      H.state.items.push(row)
      return { ok: true, msg: '已保存', data: { id: row.id } }
    }
    const mPut = p.match(/^\/api\/purchase\/fill\/items\/(\d+)$/)
    if (mPut && method === 'PUT') {
      const id = Number(mPut[1])
      const row = H.state.items.find((r) => r.id === id)
      if (!row) { const e = new Error('记录不存在'); e.payload = { ok: false }; throw e }
      const b = opts.body || {}
      if (row.status === 'confirmed') { const e = new Error('已确认的记录不可修改'); e.payload = { ok: false }; throw e }
      if (row.status === 'submitted' && !isAdmin) {
        const keys = Object.keys(b)
        const onlyUnsubmit = keys.length === 1 && keys[0] === 'status' && b.status === 'draft'
        if (!onlyUnsubmit) { const e = new Error('已提交的记录不可修改，请先撤销提交（招采确认前）'); e.payload = { ok: false }; throw e }
      }
      // 与后端一致：status 仅认 draft/submitted，未传则保持原状态（退回行保存不降级）
      Object.assign(row, b, { status: b.status === 'submitted' ? 'submitted' : (b.status === 'draft' ? 'draft' : row.status) })
      return { ok: true, msg: '已更新' }
    }
    if (mPut && method === 'DELETE') {
      const id = Number(mPut[1])
      const row = H.state.items.find((r) => r.id === id)
      if (row && row.status === 'submitted' && !isAdmin) { const e = new Error('已提交的记录不可删除，请先撤销提交'); e.payload = { ok: false }; throw e }
      H.state.items = H.state.items.filter((r) => r.id !== id)
      return { ok: true, msg: '已删除' }
    }
    if (p === '/api/purchase/products/search') {
      const kw = u.searchParams.get('keyword') || ''
      const lib = kw.includes('扫把') ? [{ line: '环境', name: '测试扫把' }] : []
      return { ok: true, data: lib }
    }
    if (p === '/api/purchase/products/by-name') {
      const name = u.searchParams.get('name') || ''
      if (name === '测试扫把') return { ok: true, data: { specs: [{ id: 1040, spec: '大号', unit: '把', brand: 'TestBrand' }], brand: 'TestBrand', unit: '把' } }
      return { ok: true, data: { specs: [], brand: '', unit: '' } }
    }
    if (p === '/api/purchase/fill/frequency') return { ok: true, data: { count: 0, total: null, unit: '', same_unit: true, detail: [] } }
    if (p === '/api/purchase/projects/options') return { ok: true, data: [{ id: 2, name: '临沂蓝钻庄园' }] }
    throw new Error('未mock的接口: ' + method + ' ' + p)
  }
  return { api, download: vi.fn() }
})

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({
    token: 'tok',
    user: H.state.role === 'admin'
      ? { id: 1, role: 'admin', name: '测试管理员', project_name: null }
      : { id: 2, role: 'project', name: '测试员工', project_name: '临沂蓝钻庄园' },
  }),
}))

vi.mock('vue-router', () => ({
  useRoute: () => ({ query: H.state.routeQuery }),
  useRouter: () => ({ push: vi.fn() }),
}))

vi.mock('@/utils/toast', () => ({
  toast: (msg, ok) => H.state.toasts.push({ msg: String(msg), ok }),
}))

const { default: FillForm } = await import('./FillForm.vue')

// 员工草稿 key 按项目名隔离（P1-6）
const DKEY = 'gw_purchase_fill_draft_2026-10_临沂蓝钻庄园'
const flush = async (n = 6) => { for (let i = 0; i < n; i++) await new Promise((r) => setTimeout(r, 0)) }

let currentApp = null
function mountComp() {
  const el = document.createElement('div')
  document.body.innerHTML = ''
  document.body.appendChild(el)
  const app = createApp(FillForm)
  app.use(ElementPlus)
  app.mount(el)
  currentApp = app
  return app
}

// —— DOM 辅助 ——
function tableRows() { return [...document.querySelectorAll('.el-table__body tbody tr')] }
async function setInput(input, val) {
  input.value = val
  input.dispatchEvent(new Event('input', { bubbles: true }))
  await flush(2)
}
function btnByText(t) {
  return [...document.querySelectorAll('button')].find((b) => (b.textContent || '').includes(t) && !b.disabled)
}
function rowNameValues() {
  return tableRows().map((tr) => {
    const td = tr.querySelectorAll('td')[1]
    const inp = td ? td.querySelector('input') : null
    return inp ? inp.value : null
  })
}
function nameInputOf(tr) { return tr.querySelectorAll('td')[1].querySelector('input') }
function qtyInputOf(tr) { return tr.querySelectorAll('td')[5].querySelector('input') }

beforeEach(() => {
  // 卸载上一用例组件：停止 watch 并触发 onUnmounted 清掉草稿防抖，防止跨用例写 localStorage 污染
  if (currentApp) { currentApp.unmount(); currentApp = null }
  H.state.items = []
  H.state.seq = 100
  H.state.calls = []
  H.state.toasts = []
  H.state.role = 'project'
  H.state.windowOpen = true
  H.state.routeQuery = { month: '2026-10' }
  localStorage.clear()
})

describe('FillForm 整改后行为基线（员工视角）', () => {
  it('R1 合法行保存全部：行保留显示、状态草稿、草稿已清、后端落库', async () => {
    mountComp()
    await flush()
    tableRows()[0].querySelector('.el-switch').dispatchEvent(new MouseEvent('click', { bubbles: true }))
    await flush()
    await setInput(nameInputOf(tableRows()[0]), '清单外测试商品A')
    await setInput(qtyInputOf(tableRows()[0]), '3')
    await new Promise((r) => setTimeout(r, 2400)) // 草稿落盘
    expect(localStorage.getItem(DKEY)).toBeTruthy()
    btnByText('保存全部').click()
    await flush(10)
    expect(rowNameValues()).toContain('清单外测试商品A')
    expect(document.body.textContent).toContain('草稿')
    expect(H.state.toasts.some((t) => t.msg.includes('已保存'))).toBe(true)
    expect(H.state.items.length).toBe(1)
    expect(H.state.items[0].status).toBe('draft')
    // 全部成功：本地草稿已清
    expect(localStorage.getItem(DKEY)).toBeNull()
  }, 15000)

  it('R2 部分行校验失败点保存：失败行红框保留不消失，成功行落库，草稿续存失败行', async () => {
    mountComp()
    await flush()
    // 行1：清单外合法
    tableRows()[0].querySelector('.el-switch').dispatchEvent(new MouseEvent('click', { bubbles: true }))
    await flush()
    await setInput(nameInputOf(tableRows()[0]), '合法商品B')
    await setInput(qtyInputOf(tableRows()[0]), '2')
    // 行2：清单外但数量=0（不合法）
    btnByText('新增一行').click()
    await flush()
    tableRows()[1].querySelector('.el-switch').dispatchEvent(new MouseEvent('click', { bubbles: true }))
    await flush()
    await setInput(nameInputOf(tableRows()[1]), '没填数量的商品C')
    btnByText('保存全部').click()
    await flush(10)
    const names = rowNameValues()
    // 成功行保留，失败行也保留（整改核心：不再消失）
    expect(names).toContain('合法商品B')
    expect(names).toContain('没填数量的商品C')
    // 失败行红框标记
    expect(tableRows()[1].classList.contains('row-error')).toBe(true)
    // 提示包含原因
    expect(H.state.toasts.some((t) => t.msg.includes('请填写数量'))).toBe(true)
    // 后端仅落库合法行
    expect(H.state.items.length).toBe(1)
    expect(H.state.items[0].item_name).toBe('合法商品B')
    // 草稿续存且只含未入库的失败行
    await new Promise((r) => setTimeout(r, 2400))
    const raw = localStorage.getItem(DKEY)
    expect(raw).toBeTruthy()
    expect(raw).toContain('没填数量的商品C')
    expect(raw).not.toContain('合法商品B')
  }, 15000)

  it('R3 必填未填完点提交：整单拦截一条不发，行保留+红框，草稿不清', async () => {
    mountComp()
    await flush()
    tableRows()[0].querySelector('.el-switch').dispatchEvent(new MouseEvent('click', { bubbles: true }))
    await flush()
    await setInput(nameInputOf(tableRows()[0]), '只填名字的商品D')
    await new Promise((r) => setTimeout(r, 2400))
    expect(localStorage.getItem(DKEY)).toBeTruthy()
    btnByText('提交全部').click()
    await flush(10)
    expect(H.state.toasts.some((t) => t.msg.includes('未填完整') && t.msg.includes('请填写数量'))).toBe(true)
    // 一条请求都没发
    expect(H.state.calls.filter((c) => c.method === 'POST' && c.path === '/api/purchase/fill/items').length).toBe(0)
    // 行保留 + 红框
    expect(rowNameValues()).toContain('只填名字的商品D')
    expect(tableRows()[0].classList.contains('row-error')).toBe(true)
    // 草稿保留（数据可恢复）
    expect(localStorage.getItem(DKEY)).toBeTruthy()
  }, 15000)

  it('R4 编辑红框行内容后红框自动解除', async () => {
    mountComp()
    await flush()
    tableRows()[0].querySelector('.el-switch').dispatchEvent(new MouseEvent('click', { bubbles: true }))
    await flush()
    await setInput(nameInputOf(tableRows()[0]), '待补数量的商品')
    btnByText('保存全部').click()
    await flush(10)
    expect(tableRows()[0].classList.contains('row-error')).toBe(true)
    // 补数量 → 行内容变化 → 红框解除
    await setInput(qtyInputOf(tableRows()[0]), '5')
    await flush(4)
    expect(tableRows()[0].classList.contains('row-error')).toBe(false)
  }, 15000)

  it('R5 员工已提交行：输入框全禁用；窗口开放有「撤销」，窗口关闭隐藏', async () => {
    H.state.items.push({
      id: 55, month: '2026-10', project_id: 2, line: '环境', product_id: 1040, item_name: '已提交商品E',
      brand: 'B', spec: '大号', unit: '把', quantity: 5, stock: 0, reason: '', use_location: '', remark: '',
      is_custom: 0, status: 'submitted', price: null, return_reason: '', returned_at: null,
    })
    mountComp()
    await flush()
    const tr = tableRows()[0]
    expect([...tr.querySelectorAll('input')].every((i) => i.disabled)).toBe(true)
    expect(tr.textContent).toContain('撤销')
    expect([...tr.querySelectorAll('button')].some((b) => b.textContent.includes('删除'))).toBe(false)
    expect([...tr.querySelectorAll('button')].some((b) => b.textContent.includes('改单'))).toBe(false) // 员工无改单入口
  }, 15000)

  it('R5b 窗口关闭时员工已提交行隐藏「撤销」按钮', async () => {
    H.state.windowOpen = false
    H.state.items.push({
      id: 57, month: '2026-10', project_id: 2, line: '环境', product_id: 1040, item_name: '已提交商品W',
      brand: 'B', spec: '大号', unit: '把', quantity: 5, stock: 0, reason: '', use_location: '', remark: '',
      is_custom: 0, status: 'submitted', price: null, return_reason: '', returned_at: null,
    })
    mountComp()
    await flush()
    const tr = tableRows()[0]
    expect([...tr.querySelectorAll('button')].some((b) => b.textContent.includes('撤销'))).toBe(false)
  }, 15000)

  it('R6 清单内模式输清单外名称：行内即时提示、禁新增行、保存拦截红框且行不丢', async () => {
    mountComp()
    await flush()
    await setInput(nameInputOf(tableRows()[0]), '完全不存在的商品G')
    await setInput(qtyInputOf(tableRows()[0]), '4')
    // 行内即时引导提示（保存前已出现）
    expect(document.body.textContent).toContain('未匹配到清单内商品')
    // 未解决前禁止新增行
    btnByText('新增一行').click()
    await flush(4)
    expect(tableRows().length).toBe(1)
    expect(H.state.toasts.some((t) => t.msg.includes('清单外'))).toBe(true)
    // 保存拦截：行保留 + 红框 + 提示指明清单外，且不发请求
    const before = H.state.calls.filter((c) => c.method === 'POST').length
    btnByText('保存全部').click()
    await flush(10)
    expect(rowNameValues()).toContain('完全不存在的商品G')
    expect(tableRows()[0].classList.contains('row-error')).toBe(true)
    expect(H.state.toasts.some((t) => t.msg.includes('未匹配到清单内商品'))).toBe(true)
    expect(H.state.calls.filter((c) => c.method === 'POST').length).toBe(before)
  }, 15000)

  it('R7 退回行点保存：保持 returned 不降级（payload 不带 status）；点提交则重新提交', async () => {
    H.state.items.push({
      id: 60, month: '2026-10', project_id: 2, line: '环境', product_id: 1040, item_name: '被退回商品H',
      brand: 'B', spec: '大号', unit: '把', quantity: 1, stock: 0, reason: '', use_location: '', remark: '',
      is_custom: 0, status: 'returned', price: null, return_reason: '数量存疑', returned_at: '2026-10-11 10:00:00',
    })
    mountComp()
    await flush()
    expect(document.body.textContent).toContain('已退回')
    await setInput(qtyInputOf(tableRows()[0]), '7')
    btnByText('保存全部').click()
    await flush(10)
    // payload 不带 status，后端保持 returned
    const put = H.state.calls.find((c) => c.method === 'PUT' && c.path === '/api/purchase/fill/items/60')
    expect(put).toBeTruthy()
    expect('status' in put.body).toBe(false)
    expect(H.state.items[0].status).toBe('returned')
    expect(document.body.textContent).toContain('已退回')
    // 提交全部 → status=submitted
    btnByText('提交全部').click()
    await flush(10)
    const put2 = H.state.calls.filter((c) => c.method === 'PUT' && c.path === '/api/purchase/fill/items/60').pop()
    expect(put2.body.status).toBe('submitted')
    expect(H.state.items[0].status).toBe('submitted')
  }, 15000)

  it('R8 服务端已有草稿时本地残留草稿被清除（防重复恢复）', async () => {
    H.state.items.push({
      id: 61, month: '2026-10', project_id: 2, line: '环境', product_id: null, item_name: '服务端草稿I',
      brand: '', spec: '', unit: '个', quantity: 2, stock: 0, reason: '', use_location: '', remark: '',
      is_custom: 1, status: 'draft', price: null, return_reason: '', returned_at: null,
    })
    localStorage.setItem(DKEY, JSON.stringify([{ line: '环境', item_name: '本地残留草稿J', quantity: 1 }]))
    mountComp()
    await flush()
    expect(localStorage.getItem(DKEY)).toBeNull()
    // 仅服务端一行，本地残留未恢复
    expect(tableRows().length).toBe(1)
    expect(rowNameValues()).toContain('服务端草稿I')
  }, 15000)

  it('R9 服务端无草稿时本地草稿正常恢复', async () => {
    localStorage.setItem(DKEY, JSON.stringify([{ line: '环境', item_name: '本地草稿K', spec: '', brand: '', unit: '个', quantity: 9, stock: 0, reason: '', use_location: '', remark: '', is_custom: true, status: 'new', product_id: null }]))
    mountComp()
    await flush(8)
    expect(rowNameValues()).toContain('本地草稿K')
    expect(H.state.toasts.some((t) => t.msg.includes('已从本地草稿恢复'))).toBe(true)
  }, 15000)
})

describe('FillForm 整改后行为基线（admin 视角）', () => {
  it('R10 admin 已提交行默认锁定，「改单」确认后解锁，保存后自动复锁且内容入库', async () => {
    H.state.role = 'admin'
    H.state.routeQuery = { month: '2026-10', project_id: '2' }
    H.state.items.push({
      id: 56, month: '2026-10', project_id: 2, line: '环境', product_id: 1040, item_name: '已提交商品F',
      brand: 'B', spec: '大号', unit: '把', quantity: 5, stock: 0, reason: '', use_location: '', remark: '',
      is_custom: 0, status: 'submitted', price: null, return_reason: '', returned_at: null,
    })
    mountComp()
    await flush()
    // 默认锁定：输入框禁用 + 有「改单」入口
    expect(nameInputOf(tableRows()[0]).disabled).toBe(true)
    const unlockBtn = [...tableRows()[0].querySelectorAll('button')].find((b) => b.textContent.includes('改单'))
    expect(unlockBtn).toBeTruthy()
    // 点改单 → 确认框 → 解锁修改
    unlockBtn.click()
    await flush(6)
    const confirmBtn = [...document.querySelectorAll('.el-message-box button')].find((b) => b.textContent.includes('解锁修改'))
    expect(confirmBtn).toBeTruthy()
    confirmBtn.click()
    await flush(6)
    expect(nameInputOf(tableRows()[0]).disabled).toBe(false)
    // 修改名称并保存
    await setInput(nameInputOf(tableRows()[0]), '改后名称F')
    btnByText('保存全部').click()
    await flush(10)
    const put = H.state.calls.find((c) => c.method === 'PUT' && c.path === '/api/purchase/fill/items/56')
    expect(put).toBeTruthy()
    expect(put.body.item_name).toBe('改后名称F')
    expect(put.body.status).toBe('submitted') // 改单保持已提交状态
    expect(H.state.items[0].item_name).toBe('改后名称F')
    // 保存后刷新复锁
    expect(nameInputOf(tableRows()[0]).disabled).toBe(true)
  }, 15000)

  it('R11 admin 草稿 key 按代填项目 id 隔离', async () => {
    H.state.role = 'admin'
    H.state.routeQuery = { month: '2026-10', project_id: '2' }
    mountComp()
    await flush()
    tableRows()[0].querySelector('.el-switch').dispatchEvent(new MouseEvent('click', { bubbles: true }))
    await flush()
    await setInput(nameInputOf(tableRows()[0]), 'admin代填商品M')
    await new Promise((r) => setTimeout(r, 2400))
    expect(localStorage.getItem('gw_purchase_fill_draft_2026-10_2')).toBeTruthy()
    expect(localStorage.getItem(DKEY)).toBeNull() // 不落在员工 key
  }, 15000)
})
