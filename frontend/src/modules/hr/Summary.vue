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
            v-for="it in items"
            :key="it.project"
            style="display:flex;align-items:center;gap:4px;padding:2px 0;cursor:pointer;white-space:nowrap"
          >
            <input type="checkbox" :value="it.project" v-model="checks" /> {{ it.project }}
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
        <div class="stat"><div class="k">发放人数（有效考勤）</div><div class="v">{{ t.headcount }}</div></div>
        <div class="stat"><div class="k">应发总金额</div><div class="v">{{ moneyOrDash(t.gross) }}</div></div>
        <div class="stat"><div class="k">实发总金额</div><div class="v">{{ moneyOrDash(t.net) }}</div></div>
        <div class="stat"><div class="k">当月预算执行率</div><div class="v">{{ pctOrDash(t.month_rate) }}</div></div>
        <div class="stat"><div class="k">年度预算执行率</div><div class="v">{{ pctOrDash(t.annual_rate) }}</div></div>
      </div>
      <div v-if="archived" class="msg info">该月已归档锁定，如需修改须超管在"薪资核算"页解锁。</div>
      <div class="table-wrap">
        <table class="tb">
          <thead><tr>
            <th>项目</th><th>发放人数</th><th>应发总金额</th><th>实发总金额</th><th>当月预算</th><th>当月执行率</th>
            <th>年度预算</th><th>年度累计应发</th><th>年度执行率</th><th>核算状态</th>
          </tr></thead>
          <tbody>
            <tr v-for="it in filtered" :key="it.project">
              <td>{{ it.project }}</td><td class="num">{{ it.headcount }}</td>
              <td class="num">{{ moneyOrDash(it.gross) }}</td><td class="num">{{ moneyOrDash(it.net) }}</td>
              <td class="num">{{ moneyOrDash(it.month_budget) }}</td><td class="num">{{ pctOrDash(it.month_rate) }}</td>
              <td class="num">{{ moneyOrDash(it.annual_budget) }}</td><td class="num">{{ moneyOrDash(it.ytd_gross) }}</td>
              <td class="num">{{ pctOrDash(it.annual_rate) }}</td>
              <td><span :class="it.calculated ? 'tag green' : 'tag gray'">{{ it.calculated ? '已核算' : '未核算' }}</span></td>
            </tr>
            <tr style="font-weight:bold;background:#f3f6fb">
              <td>{{ totalLabel }}</td><td class="num">{{ t.headcount }}</td>
              <td class="num">{{ moneyOrDash(t.gross) }}</td><td class="num">{{ moneyOrDash(t.net) }}</td>
              <td class="num">{{ moneyOrDash(t.month_budget) }}</td><td class="num">{{ pctOrDash(t.month_rate) }}</td>
              <td class="num">{{ moneyOrDash(t.annual_budget) }}</td><td class="num">{{ moneyOrDash(t.ytd_gross) }}</td>
              <td class="num">{{ pctOrDash(t.annual_rate) }}</td><td></td>
            </tr>
          </tbody>
        </table>
      </div>
      <div class="hint">发放人数口径：已核算时取实际参与核算的人数，未核算时取当月考勤记录人数。执行率口径：当月执行率=当月应发÷当月预算；年度执行率=年度累计应发÷年度预算；预算为0或无工资时按0显示。筛选后总计与顶部统计卡随勾选范围联动；全不勾选视为全部。</div>
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
const archived = ref(false)
const checks = ref([])
const dropOpen = ref(false)
const loadErr = ref('')

const filtered = computed(() => (checks.value.length ? items.value.filter((it) => checks.value.includes(it.project)) : items.value))
const t = computed(() => summaryTotals(filtered.value))
const allChecked = computed(() => items.value.length > 0 && checks.value.length === items.value.length)
const totalLabel = computed(() =>
  checks.value.length === items.value.length ? '总计' : checks.value.length ? `总计（${checks.value.length}个项目）` : '总计'
)
const dropLabel = computed(() => {
  const total = items.value.length
  const checked = checks.value.length
  return checked === 0 || checked === total ? `全部项目（${total}项）` : `已选${checked}项`
})

function toggleAll(e) {
  checks.value = e.target.checked ? items.value.map((it) => it.project) : []
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
  load()
})
onBeforeUnmount(() => document.removeEventListener('click', onDocClick))
</script>
