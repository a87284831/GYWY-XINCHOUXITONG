import { defineStore } from 'pinia'
import { api } from '@/api/client'
import router from '@/router'

export const useAuthStore = defineStore('auth', {
  state: () => ({
    user: null,
    token: '',
    // 权限点集合（复刻旧版 bootstrap() 的聚合逻辑，由 hydrate() 填充）
    perms: [],
    appModules: [],
  }),
  getters: {
    // 复刻 canPerm(mod)：admin 全通过，否则按权限点集合判断；空串=所有人可见
    can: (state) => (perm) => {
      if (!perm) return true
      if (state.user && state.user.role === 'admin') return true
      return state.perms.includes(perm)
    },
  },
  actions: {
    async login(username, password) {
      const data = await api('/api/login', { body: { username, password } })
      this.token = data.token
      this.user = data.user || null
      sessionStorage.setItem('gw_token', data.token)
    },
    async logout() {
      api('/api/logout').catch(() => {})
      this.token = ''
      this.user = null
      sessionStorage.removeItem('gw_token')
      router.push('/login')
    },
    restore() {
      const t = sessionStorage.getItem('gw_token')
      if (t) this.token = t
    },
    // 复刻旧版 bootstrap() 中权限点聚合（app.js:122-135）
    hydrate(data) {
      this.user = data.user
      this.appModules = data.app_modules || []
      const permSet = []
      const add = (p) => {
        if (!permSet.includes(p)) permSet.push(p)
      }
      if (data.user && data.user.role === 'admin') {
        this.appModules.forEach((m) => (m.perms || []).forEach((p) => add(p[0])))
      } else {
        const roles = data.roles || []
        const r = roles.find((x) => x.id === data.user.role || x.role_key === data.user.role || (x.data && x.data.id === data.user.role))
        const rp = (r && (r.perms || (r.permissions ? JSON.parse(r.permissions) : []))) || []
        if (rp.includes('*')) {
          this.appModules.forEach((m) => (m.perms || []).forEach((p) => add(p[0])))
        } else {
          rp.forEach(add)
        }
      }
      this.perms = permSet
    },
  },
})
