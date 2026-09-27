import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'

vi.mock('@/router', () => ({ default: { push: vi.fn() } }))
import router from '@/router'
import { useAuthStore } from './auth'

const jsonResponse = (data, status = 200) =>
  new Response(JSON.stringify(data), {
    status,
    headers: { 'Content-Type': 'application/json' },
  })

beforeEach(() => {
  setActivePinia(createPinia())
  sessionStorage.clear()
})

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('auth store', () => {
  it('login 成功后 token 写入 store 与 sessionStorage.gw_token', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue(jsonResponse({ ok: true, token: 'tk001', user: { name: '张三', role: 'admin' } }))
    )
    const s = useAuthStore()
    await s.login('admin', 'pw')
    expect(s.token).toBe('tk001')
    expect(s.user.name).toBe('张三')
    expect(sessionStorage.getItem('gw_token')).toBe('tk001')
  })

  it('login 失败时抛出后端错误且不写 token', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(jsonResponse({ ok: false, error: '密码错误' }, 401)))
    const s = useAuthStore()
    await expect(s.login('admin', 'bad')).rejects.toThrow('密码错误')
    expect(s.token).toBe('')
    expect(sessionStorage.getItem('gw_token')).toBeNull()
  })

  it('logout 清除 store 与 gw_token 并跳转 /login', async () => {
    vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new Error('network down')))
    const s = useAuthStore()
    s.token = 'tk001'
    s.user = { name: '张三' }
    sessionStorage.setItem('gw_token', 'tk001')

    await s.logout()

    expect(s.token).toBe('')
    expect(s.user).toBeNull()
    expect(sessionStorage.getItem('gw_token')).toBeNull()
    expect(router.push).toHaveBeenCalledWith('/login')
  })

  it('restore 从 gw_token 恢复登录态', () => {
    sessionStorage.setItem('gw_token', 'saved-tok')
    const s = useAuthStore()
    s.restore()
    expect(s.token).toBe('saved-tok')
  })

  it('restore 无 gw_token 时保持空', () => {
    const s = useAuthStore()
    s.restore()
    expect(s.token).toBe('')
  })

  it('ensureReady: 有 token 无 user 时拉 /api/init 并 hydrate', async () => {
    const fetchMock = vi.fn().mockResolvedValue(
      jsonResponse({ ok: true, user: { name: '管理员', role: 'admin' }, app_modules: [], projects: [] })
    )
    vi.stubGlobal('fetch', fetchMock)
    sessionStorage.setItem('gw_token', 'saved-tok')
    const s = useAuthStore()

    await s.ensureReady()

    expect(fetchMock).toHaveBeenCalledTimes(1)
    expect(s.user.name).toBe('管理员')
  })

  it('ensureReady: 并发调用复用同一 Promise，只发一次 /api/init', async () => {
    const fetchMock = vi.fn().mockResolvedValue(
      jsonResponse({ ok: true, user: { role: 'admin' }, app_modules: [], projects: [] })
    )
    vi.stubGlobal('fetch', fetchMock)
    sessionStorage.setItem('gw_token', 'saved-tok')
    const s = useAuthStore()

    await Promise.all([s.ensureReady(), s.ensureReady()])

    expect(fetchMock).toHaveBeenCalledTimes(1)
  })

  it('ensureReady: 已有 user 时不请求；无 token 时不请求', async () => {
    const fetchMock = vi.fn()
    vi.stubGlobal('fetch', fetchMock)
    const s = useAuthStore()
    s.token = 'tk'
    s.user = { role: 'admin' }
    await s.ensureReady()
    expect(fetchMock).not.toHaveBeenCalled()

    s.token = ''
    s.user = null
    await s.ensureReady()
    expect(fetchMock).not.toHaveBeenCalled()
  })

  it('ensureReady: 失败后清理进行中 Promise，允许重试', async () => {
    const fetchMock = vi
      .fn()
      .mockResolvedValueOnce(jsonResponse({ ok: false, error: '未登录' }, 401))
      .mockResolvedValueOnce(
        jsonResponse({ ok: true, user: { role: 'admin' }, app_modules: [], projects: [] })
      )
    vi.stubGlobal('fetch', fetchMock)
    sessionStorage.setItem('gw_token', 'saved-tok')
    const s = useAuthStore()

    await expect(s.ensureReady()).rejects.toThrow('未登录')
    // client 401 已清 token；模拟重新登录后恢复
    s.token = 'saved-tok'
    await s.ensureReady()
    expect(s.user.role).toBe('admin')
    expect(fetchMock).toHaveBeenCalledTimes(2)
  })

  it('can: 空串所有人可见；admin 全通过；普通角色按 perms 集合判断', () => {
    const s = useAuthStore()
    expect(s.can('')).toBe(true)

    s.user = { role: 'admin' }
    expect(s.can('payroll')).toBe(true)

    s.user = { role: 'readonly' }
    s.perms = ['staff']
    expect(s.can('staff')).toBe(true)
    expect(s.can('payroll')).toBe(false)
  })

  it('hydrate 复刻旧 bootstrap 权限聚合：admin 取全部模块 perms；普通角色取角色 perms；* 展开', () => {
    const s = useAuthStore()
    const appModules = [
      { key: 'hr', perms: [['payroll'], ['staff']] },
      { key: 'finance', perms: [['finance_view']] },
    ]

    s.hydrate({ user: { role: 'admin' }, app_modules: appModules })
    expect(s.perms.sort()).toEqual(['finance_view', 'payroll', 'staff'])

    s.hydrate({
      user: { role: 'r2' },
      app_modules: appModules,
      roles: [{ id: 'r2', perms: ['staff'] }],
    })
    expect(s.perms).toEqual(['staff'])

    s.hydrate({
      user: { role: 'r3' },
      app_modules: appModules,
      roles: [{ id: 'r3', permissions: JSON.stringify(['*']) }],
    })
    expect(s.perms.sort()).toEqual(['finance_view', 'payroll', 'staff'])
  })
})
