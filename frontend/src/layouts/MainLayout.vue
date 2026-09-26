<template>
  <div style="display:flex; height:100vh;">
    <div class="sidebar">
      <div class="logo">昊轩云枢<small>原创开发人：李昊轩<br>技术协作人：谢印良</small></div>
      <div class="menu" id="menu">
        <template v-for="(m, mi) in visibleMenus" :key="m.section || m.key || mi">
          <div
            v-if="m.top"
            class="menu-item"
            :class="{ active: currentKey === m.key }"
            @click="goPage(m.key)"
          >
            <span class="ms-ico">{{ m.icon }}</span><span class="ms-name">{{ m.label }}</span>
          </div>
          <template v-else>
            <div class="menu-section" :class="{ collapsed: !openSections.has(m.section) }" @click="toggleSection(m.section)">
              <span class="ms-ico">{{ m.icon }}</span><span class="ms-name">{{ m.section }}</span><span class="arrow">▾</span>
            </div>
            <div class="menu-sub" :class="{ collapsed: !openSections.has(m.section) }">
              <div
                v-for="it in m.items"
                :key="it.key"
                class="menu-sub-item"
                :class="{ active: currentKey === it.key }"
                @click="goPage(it.key)"
              >{{ itemLabel(it) }}</div>
            </div>
          </template>
        </template>
      </div>
      <div class="userbox">
        <div class="u" v-html="userLabel"></div>
        <a style="cursor:pointer" @click="showChangePw = true">修改密码</a>
        <a style="cursor:pointer" @click="onLogout">退出登录</a>
      </div>
    </div>
    <div class="main">
      <div class="topbar">
        <div class="app-launcher" :class="{ open: launcherOpen }" @click.stop>
          <div class="al-btn" @click="launcherOpen = !launcherOpen"><span class="grid-ico">⊡</span>全部模块</div>
          <div class="al-panel">
            <div class="al-title">昊轩云枢 · 全部应用</div>
            <div class="al-grid">
              <div
                v-for="a in APPS"
                :key="a.key"
                class="al-item"
                :class="{ current: currentApp === a.key, disabled: !isEnabled(a) }"
                @click="launchApp(a)"
              >
                <div class="ai-ico" :style="{ background: isEnabled(a) ? a.color : '#94a3b8' }">{{ a.icon }}</div>
                <div class="ai-name">{{ a.name }}</div>
              </div>
            </div>
          </div>
        </div>
        <div class="title" id="pageTitle">{{ pageTitle }}</div>
        <div class="bell-wrap" @click.stop>
          <button class="bell-btn" @click="toggleBell" title="消息通知">🔔<span v-if="unread > 0" class="bell-badge">{{ unread > 99 ? '99+' : unread }}</span></button>
          <div class="bell-panel" v-if="bellOpen">
            <div class="bell-head">消息通知 <span class="bell-act" style="cursor:pointer" @click="onMarkAllRead">全部已读</span></div>
            <div class="bell-list">
              <div v-if="bellLoading" style="padding: 14px; font-size: 12.5px; color: #9ca3af;">加载中…</div>
              <div v-else-if="!bellItems.length" class="bell-empty">暂无消息</div>
              <div
                v-for="m in bellItems"
                :key="m.id"
                class="bell-item"
                :class="{ unread: !m.read }"
                @click="openMsg(m)"
              >
                <div class="bell-ico" :style="{ background: msgMeta(m.type).c + '22', color: msgMeta(m.type).c }">{{ msgMeta(m.type).i }}</div>
                <div class="bell-body">
                  <div class="bell-t">{{ m.title }}</div>
                  <div v-if="m.content" class="bell-c">{{ m.content }}</div>
                  <div class="bell-tm">{{ m.project || '' }}{{ m.project ? ' · ' : '' }}{{ m.time }}</div>
                </div>
              </div>
            </div>
            <div class="bell-foot">仅保留最近 50 条</div>
          </div>
        </div>
      </div>
      <div class="content" id="content">
        <router-view />
      </div>
    </div>

    <div v-if="showChangePw" class="modal-mask" @mousedown.self="showChangePw = false">
      <div class="modal" style="width:420px">
        <h3>修改密码</h3>
        <div class="form-grid">
          <label class="full">原密码<input type="password" v-model="pwOld" /></label>
          <label class="full">新密码（至少6位）<input type="password" v-model="pwNew" /></label>
        </div>
        <div class="row end" style="margin-top:14px">
          <button class="btn" @click="showChangePw = false">取消</button>
          <button class="btn primary" @click="doChangePw">确认修改</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted, onBeforeUnmount, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api } from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import { APPS, ALL_MENUS, PAGE_TITLES, appOfPage, pageKeyOfPath, pagePath, goPage, appEnabled, APP_ROUTE } from '@/nav/menus'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()

const launcherOpen = ref(false)
const bellOpen = ref(false)
const bellLoading = ref(false)
const bellItems = ref([])
const unread = ref(0)
const showChangePw = ref(false)
const pwOld = ref('')
const pwNew = ref('')
const openSections = reactive(new Set())
let unreadTimer = null

const MSG_ICON = {
  approval: { i: '✅', c: '#8b5cf6' },
  remind: { i: '⏰', c: '#f59e0b' },
  notice: { i: '📢', c: '#0ea5e9' },
  salary: { i: '💰', c: '#16a34a' },
}
const msgMeta = (type) => MSG_ICON[type] || { i: '🔔', c: '#64748b' }

const currentKey = computed(() => pageKeyOfPath(route.path))
const currentApp = computed(() => appOfPage(currentKey.value))
const pageTitle = computed(() => PAGE_TITLES[currentKey.value] || '')

const roleLabel = computed(() => {
  const u = auth.user || {}
  if (u.role === 'admin') return '超级管理员'
  if (u.role === 'project') return '项目账号·' + (u.project || '')
  return '只读账号'
})
const userLabel = computed(() => {
  const u = auth.user || {}
  const name = u.name || u.username || ''
  const cls = u.role === 'admin' ? 'purple' : 'blue'
  return `${name} <span class="tag ${cls}">${roleLabel.value}</span>`
})

const visibleMenus = computed(() => {
  const out = []
  for (const m of ALL_MENUS) {
    if (!m.fixed && m.app && m.app !== currentApp.value) continue
    if (m.top) {
      if (m.always || auth.can(m.perm)) out.push(m)
    } else {
      const items = m.items.filter((it) => {
        if (it.perm && !auth.can(it.perm)) return false
        if (it.adminOnly && (!auth.user || auth.user.role !== 'admin')) return false
        if (it.staffOnly && auth.user && auth.user.role === 'admin') return false
        return true
      })
      if (!items.length) continue
      out.push({ ...m, items })
    }
  }
  return out
})

// 活动分组展开、其余折叠（复刻 renderMenu 的 collapsed = !isActive）
watch(
  visibleMenus,
  (menus) => {
    const activeSection = menus.find(
      (m) => m.items && m.items.some((it) => it.key === currentKey.value)
    )
    for (const m of menus) {
      if (!m.items) continue
      if (m === activeSection) openSections.add(m.section)
      else openSections.delete(m.section)
    }
  },
  { immediate: true }
)

const itemLabel = (it) => {
  if (it.staffLabel && auth.user && auth.user.role !== 'admin') return it.staffLabel
  return it.label
}

const isEnabled = (a) => appEnabled(a, auth)

function toggleSection(section) {
  if (openSections.has(section)) openSections.delete(section)
  else openSections.add(section)
}

function launchApp(a) {
  launcherOpen.value = false
  if (!isEnabled(a)) {
    window.alert('暂无【' + a.name + '】模块权限，请联系管理员')
    return
  }
  const target = APP_ROUTE[a.key]
  if (target) { router.push(target); return }
  window.alert('【' + a.name + '】模块建设中，敬请期待')
}

async function loadUnread() {
  try {
    const d = await api('/api/messages/unread_count')
    unread.value = d.unread || 0
  } catch (e) { /* 静默 */ }
}

async function toggleBell() {
  bellOpen.value = !bellOpen.value
  if (bellOpen.value) await renderBellList()
}

async function renderBellList() {
  bellLoading.value = true
  try {
    const d = await api('/api/messages?limit=50')
    bellItems.value = d.items || []
    loadUnread()
  } catch (e) {
    bellItems.value = []
  }
  bellLoading.value = false
}

async function openMsg(m) {
  await api('/api/messages/read', { body: { id: m.id } }).catch(() => {})
  loadUnread()
  bellOpen.value = false
  msgJump(m.link)
}

function msgJump(link) {
  if (/\/purchase\/index\.html/.test(link || '')) {
    const h = String(link).split('#')[1] || ''
    const routePath = h.replace(/^#?\//, '').split('?')[0]
    const hashMap = {
      dashboard: 'purchaseDashboard', fill: 'purchaseFill', 'fill-form': 'purchaseFill',
      overview: 'purchaseOverview', customs: 'purchaseCustoms', budget: 'purchaseBudget',
      summary: 'purchaseSummary', 'export-import': 'purchaseExportImport', products: 'purchaseProducts',
      windows: 'purchaseWindows', oplogs: 'purchaseOplogs', 'my-items': 'purchaseMyItems',
    }
    router.push(pagePath(hashMap[routePath] || 'purchaseDashboard'))
    return
  }
  const key = String(link || '').replace(/^\/+/, '').replace(/\?.*$/, '')
  if (PAGE_TITLES[key]) { goPage(key); return }
  if (/^https?:\/\//i.test(link || '')) { window.open(link, '_blank'); return }
  if (link && link !== '/') { window.location.href = link }
}

async function onMarkAllRead() {
  await api('/api/messages/read_all', { method: 'POST' })
  loadUnread()
  renderBellList()
}

async function doChangePw() {
  try {
    await api('/api/change_password', { body: { old: pwOld.value, new: pwNew.value } })
    showChangePw.value = false
    pwOld.value = ''
    pwNew.value = ''
    window.alert('密码已修改')
  } catch (e) {
    window.alert(e.message)
  }
}

async function onLogout() {
  await auth.logout()
}

function onDocClick() {
  launcherOpen.value = false
  bellOpen.value = false
}

onMounted(async () => {
  document.addEventListener('click', onDocClick)
  unreadTimer = setInterval(loadUnread, 120000)
  loadUnread()
  // 会话恢复：有 token 但缺用户或项目列表 → /api/init 拉取（登录响应不含 projects）
  if (auth.token && (!auth.user || !auth.projects.length)) {
    try {
      const data = await api('/api/init')
      auth.hydrate(data)
    } catch (e) {
      if (e.message !== '未登录') {
        auth.token = ''
        sessionStorage.removeItem('gw_token')
        router.push('/login')
      }
    }
  }
})

onBeforeUnmount(() => {
  document.removeEventListener('click', onDocClick)
  clearInterval(unreadTimer)
})
</script>
