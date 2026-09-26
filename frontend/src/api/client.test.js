import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'

vi.mock('@/router', () => ({ default: { push: vi.fn() } }))
import router from '@/router'
import { api, download } from './client'
import { useAuthStore } from '@/stores/auth'

const jsonResponse = (data, status = 200) =>
  new Response(JSON.stringify(data), {
    status,
    headers: { 'Content-Type': 'application/json' },
  })

beforeEach(() => {
  setActivePinia(createPinia())
  document.body.innerHTML = ''
})

afterEach(() => {
  vi.unstubAllGlobals()
  vi.restoreAllMocks()
})

describe('api()', () => {
  it('请求自动带 X-Token 头（取自 auth store）', async () => {
    const store = useAuthStore()
    store.token = 'tok123'
    const fetchMock = vi.fn().mockResolvedValue(jsonResponse({ ok: true }))
    vi.stubGlobal('fetch', fetchMock)

    await api('/api/init')

    expect(fetchMock).toHaveBeenCalledOnce()
    const [path, init] = fetchMock.mock.calls[0]
    expect(path).toBe('/api/init')
    expect(init.headers['X-Token']).toBe('tok123')
  })

  it('无 body 请求不设 Content-Type，有 body 时为 JSON 且默认 POST', async () => {
    const fetchMock = vi.fn().mockImplementation(() => Promise.resolve(jsonResponse({ ok: true })))
    vi.stubGlobal('fetch', fetchMock)

    await api('/api/init')
    expect(fetchMock.mock.calls[0][1].headers['Content-Type']).toBeUndefined()
    expect(fetchMock.mock.calls[0][1].method).toBe('GET')

    await api('/api/save', { body: { a: 1 } })
    expect(fetchMock.mock.calls[1][1].headers['Content-Type']).toBe('application/json')
    expect(fetchMock.mock.calls[1][1].method).toBe('POST')
    expect(fetchMock.mock.calls[1][1].body).toBe(JSON.stringify({ a: 1 }))
  })

  it('响应 ok=false 时抛出后端 error 文案', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(jsonResponse({ ok: false, error: '余额不足' })))
    await expect(api('/api/x')).rejects.toThrow('余额不足')
  })

  it('响应无 error 字段时抛出默认文案 操作失败', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(jsonResponse({ ok: false })))
    await expect(api('/api/x')).rejects.toThrow('操作失败')
  })

  it('401 时清除登录态并跳转 /login，抛出 未登录', async () => {
    sessionStorage.setItem('gw_token', 'stale-tok')
    const store = useAuthStore()
    store.token = 'stale-tok'
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(jsonResponse({ ok: false }, 401)))
    await expect(api('/api/init')).rejects.toThrow('未登录')
    expect(router.push).toHaveBeenCalledWith('/login')
    expect(store.token).toBe('')
    expect(sessionStorage.getItem('gw_token')).toBeNull()
  })

  it('/api/login 的 401 不触发跳转，按 ok=false 抛错', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(jsonResponse({ ok: false, error: '密码错误' }, 401)))
    await expect(api('/api/login', { body: { username: 'u', password: 'p' } })).rejects.toThrow('密码错误')
    expect(router.push).not.toHaveBeenCalled()
  })

  it('非 JSON 2xx 返回 blob', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue(new Response('filedata', { status: 200, headers: { 'Content-Type': 'application/octet-stream' } }))
    )
    const blob = await api('/api/export')
    expect(blob.size).toBe(8)
  })

  it('非 JSON 非 2xx 抛出 下载失败', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue(new Response('err', { status: 500, headers: { 'Content-Type': 'text/html' } }))
    )
    await expect(api('/api/export')).rejects.toThrow('下载失败')
  })
})

describe('download()', () => {
  it('成功时创建 <a download> 并触发点击，提示导出完成', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue(new Response('filedata', { status: 200, headers: { 'Content-Type': 'application/octet-stream' } }))
    )
    URL.createObjectURL = vi.fn(() => 'blob:mock')
    URL.revokeObjectURL = vi.fn()
    const click = vi.fn()
    const origCreate = document.createElement.bind(document)
    vi.spyOn(document, 'createElement').mockImplementation((tag) => {
      const el = origCreate(tag)
      if (tag === 'a') el.click = click
      return el
    })

    await download('/api/export', 'file.xlsx')

    expect(click).toHaveBeenCalledOnce()
    expect(document.body.textContent).toContain('导出完成')
  })

  it('失败时显示 导出失败 提示', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue(new Response('err', { status: 500, headers: { 'Content-Type': 'text/html' } }))
    )

    await download('/api/export', 'file.xlsx')

    expect(document.body.textContent).toContain('导出失败')
  })
})
