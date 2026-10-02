<template>
  <div>
    <div class="card">
      <h3>项目薪资预算管理</h3>
      <div class="row">
        <label class="fld">预算年度 <select v-model="year" @change="load">
          <option v-for="y in yearOpts" :key="y">{{ y }}</option>
        </select></label>
        <button class="btn primary" @click="load">查询</button>
        <button class="btn" @click="downloadTemplate">下载导入模板</button>
        <input ref="fileEl" type="file" accept=".xlsx" style="display:none" @change="importBudget" />
        <button class="btn" @click="fileEl && fileEl.click()">Excel导入预算</button>
      </div>
      <div v-if="impMsg" class="msg ok">{{ impMsg }}</div>
    </div>
    <div v-if="loadErr" class="card"><div class="msg err">{{ loadErr }}</div></div>
    <div v-else-if="!items.length" class="card"><div class="msg info">暂无项目，请先在"项目档案"中添加。</div></div>
    <div v-for="it in items" :key="it.project" class="card">
      <h3>{{ it.project }} · {{ year }}年度预算　<span :class="it.status === '启用' ? 'tag green' : 'tag gray'">{{ it.status }}</span></h3>
      <div class="row" style="margin-bottom:10px">
        <label class="fld">年度总预算(元) <input type="number" step="0.01" style="width:150px" v-model.number="it.annual" /></label>
        <span class="hint" style="margin:0">年度总预算自动 = 各月预算之和；年度执行率 = 本年累计实发 ÷ 年度总预算 = <b>{{ pctOrDash(it.annual_rate) }}</b></span>
      </div>
      <div class="table-wrap">
        <table class="tb">
          <thead><tr><th>月份</th><th v-for="m in 12" :key="m">{{ m }}月</th></tr></thead>
          <tbody>
            <tr>
              <td>月度预算</td>
              <td v-for="m in 12" :key="m" class="num">
                <input type="number" step="0.01" style="width:80px" v-model.number="it.months_budget[String(m)]" @input="syncAnnual(it)" />
              </td>
            </tr>
            <tr>
              <td>当月实发</td>
              <td v-for="m in 12" :key="m" class="num">{{ moneyOrDash(it.months_actual[String(m)]) }}</td>
            </tr>
            <tr>
              <td>月度执行率</td>
              <td v-for="m in 12" :key="m" class="num">{{ pctOrDash(it.month_rates[String(m)]) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
      <div class="hint">年中修改预算后，所有历史月份执行率自动按新预算重新计算；预算为0或无产出时执行率显示0。</div>
      <div class="row" style="margin-top:8px">
        <button class="btn primary" @click="save(it)">保存预算</button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { api, download } from '@/api/client'
import { useUiStore } from '@/stores/ui'
import { moneyOrDash, pctOrDash } from '@/utils/format'
import { toast } from '@/utils/toast'
import { budgetAnnualFromMonths } from './staffLogic'

const ui = useUiStore()
const fileEl = ref(null)
const yearOpts = computed(() => {
  const y = Number(ui.month.slice(0, 4))
  return [y - 1, y, y + 1].map(String)
})
const year = ref(ui.month.slice(0, 4))
const items = ref([])
const loadErr = ref('')
const impMsg = ref('')

async function load() {
  loadErr.value = ''
  try {
    const data = await api(`/api/budget/view?year=${year.value}`)
    items.value = (data.items || []).map((it) => ({
      ...it,
      months_budget: { ...it.months_budget },
      months_actual: { ...it.months_actual },
      month_rates: { ...it.month_rates },
    }))
  } catch (e) { loadErr.value = e.message }
}

// 年度总预算自动 = 各月预算之和（复刻 budgetSyncAnnual）
function syncAnnual(it) {
  it.annual = budgetAnnualFromMonths(it.months_budget)
}

function downloadTemplate() {
  download(`/api/budget/template?year=${year.value}`, '预算导入模板.xlsx')
}

async function importBudget() {
  const f = fileEl.value && fileEl.value.files[0]
  if (!f) return
  const form = new FormData()
  form.append('year', year.value)
  form.append('file', f)
  try {
    const r = await api('/api/budget/import', { form })
    impMsg.value = `导入成功：${r.count} 个项目`
    load()
  } catch (e) {
    impMsg.value = ''
    alert(e.message)
  }
  if (fileEl.value) fileEl.value.value = ''
}

async function save(it) {
  const months = {}
  for (const m of Object.keys(it.months_budget)) months[m] = parseFloat(it.months_budget[m] || 0)
  const annual = Object.values(months).reduce((s, v) => s + (Number(v) || 0), 0) // 年度总预算=各月之和
  try {
    await api('/api/budget/save', { body: { year: year.value, project: it.project, annual, months } })
    toast('预算已保存，执行率已同步')
    load()
  } catch (e) { alert(e.message) }
}

onMounted(load)
</script>
