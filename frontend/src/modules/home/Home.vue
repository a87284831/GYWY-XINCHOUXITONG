<template>
  <div>
    <div class="home-hero">
      <div>
        <div class="hh-hi">{{ greeting }}，{{ dispName }}<span v-if="showRoleBadge" class="hh-role">{{ roleLabel }}</span></div>
        <div class="hh-sub">欢迎使用昊轩云枢，从下方模块或数据速览开始今天的工作。</div>
      </div>
      <div class="hh-date"><b>{{ today }}</b><br>当前核算月份：{{ month }}</div>
    </div>

    <div class="home-sec-title">数据速览</div>
    <div class="home-kpi-row">
      <div class="home-kpi no-link">
        <div class="hk-top"><span class="hk-ico" style="background:#dbeafe;color:#1d4ed8">👥</span>在职人数</div>
        <div class="hk-num">{{ kpi.active }}</div>
        <div class="hk-sub">含试用，不含离职</div>
      </div>
      <div class="home-kpi" :class="{ 'no-link': !auth.can('payroll') }" @click="kpiGrossClick">
        <div class="hk-top"><span class="hk-ico" style="background:#dcfce7;color:#15803d">💰</span>本月应发总额</div>
        <div class="hk-num">{{ kpi.gross }}</div>
        <div class="hk-sub">{{ month }} 应发合计 · 点击查看</div>
      </div>
      <div class="home-kpi" :class="{ 'no-link': !auth.can('payroll') }" @click="kpiGrossClick">
        <div class="hk-top"><span class="hk-ico" style="background:#fef3c7;color:#b45309">🧾</span>本月发放人数</div>
        <div class="hk-num">{{ kpi.head }}</div>
        <div class="hk-sub">有效考勤人数 · 点击查看</div>
      </div>
      <div v-if="auth.can('perf')" class="home-kpi" @click="auth.can('perf') && goPage('perfApprove')">
        <div class="hk-top"><span class="hk-ico" style="background:#ede9fe;color:#6d28d9">✅</span>待我审批</div>
        <div class="hk-num">{{ kpi.approve }}</div>
        <div class="hk-sub">绩效考核单 · 点击处理</div>
      </div>
      <div v-if="auth.can('maint_fire') || auth.can('maint_elev')" class="home-kpi" @click="kpiMaintClick">
        <div class="hk-top"><span class="hk-ico" style="background:#ffedd5;color:#c2410c">🔧</span>维保在管合同</div>
        <div class="hk-num">{{ kpi.maint }}<span v-if="kpi.maintUnit" class="hk-unit">{{ kpi.maintUnit }}</span></div>
        <div class="hk-sub">{{ kpi.maintSub }}</div>
      </div>
    </div>

    <div class="home-sec-title">我的待办</div>
    <div class="home-todo">
      <div v-if="!todoCards.length" class="msg info">暂无待办事项</div>
      <div
        v-for="(c, i) in todoCards"
        :key="i"
        class="home-kpi"
        @click="goPage(c.page)"
      >
        <div class="hk-top">
          <span class="hk-ico" :style="{ background: c.color + '22', color: c.color }">{{ c.icon }}</span>{{ c.title }}
          <span v-if="c.num !== '' && c.num !== undefined && c.num !== null" :style="{ marginLeft: 'auto', background: c.color, color: '#fff', fontSize: '11px', padding: '1px 9px', borderRadius: '11px', fontWeight: 700 }">{{ c.num }}</span>
        </div>
        <div class="hk-sub" style="font-size:12.5px;color:#475569;margin:1px 0 7px">{{ c.sub }}</div>
        <div class="ha-go" :style="{ color: c.color }">{{ c.goText || '去处理 →' }}</div>
      </div>
    </div>

    <div class="home-sec-title">常用模块</div>
    <div class="home-app-grid">
      <div
        v-for="a in APPS"
        :key="a.key"
        class="home-app"
        :class="{ disabled: !cardEnabled(a) }"
        @click="launchApp(a)"
      >
        <div v-if="!HOME_BUILT.has(a.key)" class="ha-tag">建设中</div>
        <div v-else-if="!cardEnabled(a)" class="ha-tag">未授权</div>
        <div class="ha-head">
          <div class="ha-ico" :style="{ background: cardEnabled(a) ? a.color : '#94a3b8' }">{{ a.icon }}</div>
          <div class="ha-name">{{ a.name }}</div>
        </div>
        <div class="ha-desc">{{ HOME_META[a.key]?.desc || '模块规划中，敬请期待' }}</div>
        <div class="ha-go" :style="{ color: cardEnabled(a) ? a.color : '#94a3b8' }">
          {{ cardGoText(a) }}
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { api } from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import { APPS, HOME_BUILT, HOME_META, appEnabled, APP_ROUTE, goPage } from '@/nav/menus'

const router = useRouter()
const auth = useAuthStore()

const money = (x) => {
  const n = Number(x || 0)
  return n.toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

const month = computed(() => {
  const d = new Date()
  return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0')
})

const greeting = computed(() => {
  const h = new Date().getHours()
  if (h < 5) return '夜深了'
  if (h < 11) return '早上好'
  if (h < 13) return '中午好'
  if (h < 18) return '下午好'
  return '晚上好'
})

const today = computed(() => {
  const d = new Date()
  const w = ['日', '一', '二', '三', '四', '五', '六'][d.getDay()]
  return `${d.getFullYear()} 年 ${d.getMonth() + 1} 月 ${d.getDate()} 日 · 星期${w}`
})

const dispName = computed(() => {
  const u = auth.user || {}
  return u.name || u.username || ''
})
const roleLabel = computed(() => {
  const u = auth.user || {}
  if (u.role === 'admin') return '超级管理员'
  if (u.role === 'project') return '项目账号 · ' + (u.project || '')
  return '只读账号'
})
const showRoleBadge = computed(() => dispName.value !== roleLabel.value)

const kpi = reactive({ active: '…', gross: '…', head: '…', approve: '…', maint: '…', maintUnit: '', maintSub: '消防 + 电梯 · 点击查看' })
const todoCards = ref([])

function kpiGrossClick() {
  if (auth.can('payroll')) goPage('summary')
}
function kpiMaintClick() {
  if (auth.can('maint_fire') || auth.can('maint_elev')) goPage('fireReport')
}

const cardEnabled = (a) => HOME_BUILT.has(a.key) && appEnabled(a, auth)
const cardGoText = (a) => {
  const enabled = cardEnabled(a)
  if (enabled) return HOME_META[a.key]?.go || '进入 →'
  return HOME_BUILT.has(a.key) ? '暂无访问权限' : '建设中 · 敬请期待'
}
function launchApp(a) {
  if (!cardEnabled(a)) return
  const target = APP_ROUTE[a.key]
  if (target) router.push(target)
}

// 复刻 homeLoadKpi
async function homeLoadKpi() {
  if (auth.can('hr_report')) {
    try {
      const d = await api(`/api/report/hr?year=${month.value.slice(0, 4)}&ym=${month.value}&annual=0`)
      kpi.active = d.kpis.active
    } catch (e) { kpi.active = '—' }
  } else { kpi.active = '—' }

  if (auth.can('payroll')) {
    try {
      const d = await api(`/api/summary?ym=${month.value}`)
      kpi.gross = money(d.total.gross)
      kpi.head = d.total.headcount
    } catch (e) { kpi.gross = '—'; kpi.head = '—' }
  } else {
    kpi.gross = '—'
    kpi.head = '—'
  }

  if (auth.can('perf')) {
    try {
      const d = await api('/api/performance/plans?scope=approve')
      kpi.approve = (d.plans || []).length
    } catch (e) { kpi.approve = '—' }
  }

  if (auth.can('maint_fire') || auth.can('maint_elev')) {
    try {
      const d = await api(`/api/maintenance/dashboard?year=${new Date().getFullYear()}`)
      const f = d.overview.fireCount || 0
      const e2 = d.overview.elevatorCount || 0
      kpi.maint = f + e2
      kpi.maintUnit = '份'
      kpi.maintSub = `消防 ${f} · 电梯 ${e2}${d.riskTotal ? ` · 临期风险 ${d.riskTotal}` : ''} · 点击查看`
    } catch (err) { kpi.maint = '—' }
  }
}

// 复刻 homeTodoCard + homeLoadTodo
function todoCard(icon, title, num, sub, page, color, goText) {
  const hasNum = num !== undefined && num !== null && num !== ''
  return { icon, title, num: hasNum ? num : '', sub, page, color, goText }
}

async function homeLoadTodo() {
  const cards = []
  if (auth.can('perf')) {
    try {
      const d = await api('/api/performance/plans?scope=approve')
      cards.push(todoCard('✅', '待我审批', (d.plans || []).length, '绩效考核单等待您逐级审批确认', 'perfApprove', '#8b5cf6'))
    } catch (e) {}
    try {
      const d = await api('/api/performance/plans?scope=report')
      cards.push(todoCard('✍️', '待我填报', (d.plans || []).length, '考核单需要您填报相关数据', 'perfReport', '#f59e0b'))
    } catch (e) {}
    try {
      const d = await api('/api/performance/plans?scope=mine')
      const n = (d.plans || []).filter((p) => p.status !== 'done' && p.status !== 'draft').length
      cards.push(todoCard('📋', '我的考核进行中', n, '本人考核流程当前进度', 'perfMine', '#2563eb', '查看我的考核 →'))
    } catch (e) {}
  }
  if (auth.can('perf_admin')) cards.push(todoCard('🗂️', '考核记录管理', '', '全部考核流程、季度明细与项目得分排名', 'perfRecords', '#0ea5e9', '进入管理 →'))
  // 审批中心待办（对所有登录账号可见）
  try {
    const ad = await api('/api/approval/list?scope=approve')
    const an = (ad.items || []).length
    if (an > 0) cards.unshift(todoCard('✅', '待我审批', an, '录用/转正/离职等审批单等待您处理', 'approvalCenter', '#8b5cf6'))
  } catch (e) {}
  if (auth.user && auth.user.role === 'admin') {
    try {
      const od = await api('/api/approval/onboard/list?status=pending')
      const on = (od.items || []).length
      if (on > 0) cards.push(todoCard('🧳', '入职办理待办', on, '录用已通过，待完成入职办理清单', 'approvalCenter', '#0891b2'))
    } catch (e) {}
  }
  if (auth.can('payroll')) cards.push(todoCard('🧮', '薪资核算与微调', '', `进入 ${month.value} 薪资核算与人工微调`, 'payroll', '#16a34a', '去核算 →'))
  if (auth.can('staff')) cards.push(todoCard('👥', '人员档案', '', '维护员工档案、入职转正与离职信息', 'staff', '#0891b2', '查看档案 →'))
  todoCards.value = cards
}

onMounted(() => {
  homeLoadKpi()
  homeLoadTodo()
})
</script>
