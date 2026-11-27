<template>
  <div class="card">
    <label class="fld">核算月份 <input type="month" v-model="ui.month" @change="load" /></label>
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-top:8px">
      <b>项目筛选：</b>
      <div style="position:relative;display:inline-block" @click.stop>
        <button class="btn" @click="dropOpen = !dropOpen">{{ dropLabel }}</button>
        <div
          v-if="dropOpen"
          style="position:absolute;top:calc(100% + 4px);left:0;z-index:99;background:#fff;border:1px solid #d9d9d9;border-radius:8px;box-shadow:0 4px 16px rgba(0,0,0,0.12);padding:10px 12px;min-width:260px;max-height:320px;overflow:auto"
        >
          <label style="display:flex;align-items:center;gap:4px;padding:3px 0;cursor:pointer">
            <input type="checkbox" :checked="allChecked" @change="toggleAll" /> 全选
          </label>
          <div style="border-top:1px solid #eee;margin:4px 0 2px"></div>
          <label
            v-for="p in projectOptions"
            :key="p"
            style="display:flex;align-items:center;gap:4px;padding:2px 0;cursor:pointer;white-space:nowrap"
          >
            <input type="checkbox" :value="p" v-model="checks" /> {{ p }}
          </label>
        </div>
      </div>
    </div>
    <div style="margin-top:8px">
      <button class="btn" @click="exportSummary">导出表格</button>
      <button class="btn primary" @click="load">刷新</button>
    </div>
    <div style="margin-top:12px" v-if="!loadErr">
      <div class="stat-cards">
        <div class="stat"><div class="k">发放人数</div><div class="v">{{ t.headcount }}</div></div>
        <div class="stat"><div class="k">应发合计（基层+管理+总部）</div><div class="v">{{ moneyOrDash(t.gross) }}</div></div>
        <div class="stat"><div class="k">实发总金额</div><div class="v">{{ moneyOrDash(t.net) }}</div></div>
        <div class="stat"><div class="k">案场应发（不计入合计）</div><div class="v">{{ moneyOrDash(t.case_gross) }}</div></div>
        <div class="stat"><div class="k">当月预算执行率</div><div class="v">{{ pctOrDash(t.month_rate) }}</div></div>
        <div class="stat"><div class="k">年度预算执行率</div><div class="v">{{ pctOrDash(t.annual_rate) }}</div></div>
      </div>
      <div v-if="archived" class="msg info">该月已归档锁定，如需修改须超管在"薪资核算"页解锁。</div>
      <div class="table-wrap">
        <table class="tb">
          <thead><tr>
            <th>项目</th><th>人数</th><th>应发</th><th>实发</th>
            <th>当月预算</th><th>当月执行率</th>
            <th>年度预算</th><th>年度累计应发</th><th>年度执行率</th><th>核算状态</th>
          </tr></thead>
          <tbody>
            <tr v-for="it in filtered" :key="it.project">
              <td>{{ it.project }}</td>
              <td class="num"><b>{{ it.headcount }}</b></td><td class="num"><b>{{ moneyOrDash(it.gross) }}</b></td>
              <td class="num">{{ moneyOrDash(it.net) }}</td>
              <td class="num">{{ moneyOrDash(it.month_budget) }}</td><td class="num">{{ pctOrDash(it.month_rate) }}</td>
              <td class="num">{{ moneyOrDash(it.annual_budget) }}</td><td class="num">{{ moneyOrDash(it.ytd_gross) }}</td>
              <td class="num">{{ pctOrDash(it.annual_rate) }}</td>
              <td><span :class="it.calculated ? 'tag green' : 'tag gray'">{{ it.calculated ? '已核算' : '未核算' }}</span></td>
            </tr>
            <tr style="font-weight:bold;background:#f3f6fb">
              <td>{{ totalLabel }}</td>
              <td class="num">{{ t.headcount }}</td><td class="num">{{ moneyOrDash(t.gross) }}</td>
              <td class="num">{{ moneyOrDash(t.net) }}</td>
              <td class="num">{{ moneyOrDash(t.month_budget) }}</td><td class="num">{{ pctOrDash(t.month_rate) }}</td>
              <td class="num">{{ moneyOrDash(t.annual_budget) }}</td><td class="num">{{ moneyOrDash(t.ytd_gross) }}</td>
              <td class="num">{{ pctOrDash(t.annual_rate) }}</td><td></td>
            </tr>
          </tbody>
        </table>
      </div>
      <h4 style="margin:16px 0 6px">案场人员（单独核算，不计入项目合计）</h4>
      <div class="table-wrap">
        <table class="tb">
          <thead><tr><th>项目</th><th>案场人数</th><th>案场应发</th><th>案场实发</th></tr></thead>
          <tbody>
            <tr v-for="it in filtered" :key="'case-' + it.project" v-show="it.case_headcount > 0">
              <td>{{ it.project }}</td><td class="num">{{ it.case_headcount }}</td>
              <td class="num">{{ moneyOrDash(it.case_gross) }}</td><td class="num">{{ moneyOrDash(it.case_net) }}</td>
            </tr>
            <tr style="font-weight:bold;background:#fff7ed">
              <td>案场合计（不计入项目合计）</td><td class="num">{{ t.case_headcount }}</td>
              <td class="num">{{ moneyOrDash(t.case_gross) }}</td><td class="num">{{ moneyOrDash(t.case_net) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
      <div class="hint">口径：项目合计=基层+管理+总部；案场单独列示、不并入项目合计，也不参与预算执行率。预算执行率只用应发合计计算。筛选后总计与顶部统计卡随勾选范围联动；全不勾选视为全部。</div>
    </div>
    <div style="margin-top:12px" v-else><div class="msg err">{{ loadErr }}</div></div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount, watch } from 'vue'
import { api, download } from '@/api/client'
import { useUiStore } from '@/stores/ui'
import { moneyOrDash, pctOrDash } from '@/utils/format'
import { summaryTotals } from './payrollLogic'

const ui = useUiStore()
const items = ref([])
const projectOptions = ref([])
const archived = ref(false)
const checks = ref([])
const dropOpen = ref(false)
const loadErr = ref('')

const filtered = computed(() => (checks.value.length ? items.value.filter((it) => checks.value.includes(it.project)) : items.value))
const t = computed(() => summaryTotals(filtered.value))
const allChecked = computed(() => projectOptions.value.length > 0 && projectOptions.value.every((p) => checks.value.includes(p)))
const totalLabel = computed(() =>
  checks.value.length === projectOptions.value.length ? '总计' : checks.value.length ? `总计（${checks.value.length}个项目）` : '总计'
)
const dropLabel = computed(() => {
  const total = projectOptions.value.length
  const checked = checks.value.filter((p) => projectOptions.value.includes(p)).length
  return checked === 0 || checked === total ? `全部项目（${total}项）` : `已选${checked}项`
})

function toggleAll(e) {
  checks.value = e.target.checked ? [...projectOptions.value] : []
}

async function loadProjects() {
  try {
    const data = await api('/api/projects')
    projectOptions.value = (data.projects || [])
      .map((p) => p.name)
      .filter(Boolean)
  } catch (e) {
    projectOptions.value = []
  }
}

async function load() {
  loadErr.value = ''
  try {
    const data = await api(`/api/summary?ym=${ui.month}`)
    items.value = data.items || []
    archived.value = !!data.archived
    checks.value = []
  } catch (e) {
    loadErr.value = e.message
  }
}

// 复刻 exportSummary（app.js:733-738）：全选→无后缀，部分勾选→_筛选
function exportSummary() {
  const p = checks.value.join(',')
  const label = checks.value.length === items.value.length ? '' : checks.value.length ? '_筛选' : ''
  download(`/api/summary/export?ym=${ui.month}&project=${encodeURIComponent(p)}`, `薪资汇总展示_${ui.month}${label}.xlsx`)
}

function onDocClick() {
  dropOpen.value = false
}

watch(() => ui.month, load)
onMounted(() => {
  document.addEventListener('click', onDocClick)
  loadProjects()
  load()
})
onBeforeUnmount(() => document.removeEventListener('click', onDocClick))
</script>
