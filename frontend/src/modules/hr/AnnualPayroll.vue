<template>
  <div class="card">
    <h3>全年工资发放明细</h3>
    <div class="row" style="gap:8px;flex-wrap:wrap;align-items:center">
      <label class="fld">年份
        <select v-model="year" @change="load">
          <option v-for="y in yearOptions" :key="y" :value="y">{{ y }}年</option>
        </select>
      </label>
      <label class="fld">项目
        <select v-model="project" @change="load">
          <option value="">全部项目</option>
          <option v-for="p in auth.projects" :key="p" :value="p">{{ p }}</option>
        </select>
      </label>
      <label class="fld">姓名 <input v-model.trim="name" placeholder="姓名搜索" @keyup.enter="load" /></label>
      <button class="btn" :disabled="loading" @click="load">查询</button>
      <span style="flex:1"></span>
      <button class="btn primary" :disabled="loading || !rows.length" @click="doExport">导出 Excel（汇总+明细）</button>
    </div>
    <div class="hint" style="margin-top:8px">
      口径：月份列为应发工资；含离职人员（离职日期独立列，离职后月份留空）；全年发薪人数=当年有发放记录的人数（含离职）。
    </div>

    <div v-if="loading" class="hint" style="margin-top:12px">加载中…</div>
    <div v-else-if="!rows.length" class="hint" style="margin-top:12px">该年度无工资发放数据</div>

    <div v-else style="overflow-x:auto;margin-top:12px;border:1px solid var(--border,#e3e7ec);border-radius:8px">
      <table class="grid" style="min-width:1500px">
        <thead>
          <tr>
            <th>项目</th><th>部门</th><th>岗位</th><th>姓名</th><th>员工状态</th><th>入职日期</th><th>离职日期</th>
            <th v-for="m in 12" :key="m">{{ m }}月</th>
            <th>全年应发</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="(r, i) in rows" :key="i">
            <td>{{ r.project }}</td>
            <td>{{ r.department }}</td>
            <td>{{ r.position }}</td>
            <td>{{ r.name }}</td>
            <td><span :class="r.resigned ? 'tag off' : 'tag on'">{{ r.status_label }}</span></td>
            <td>{{ r.hire_date || '—' }}</td>
            <td>{{ r.resign_date || '—' }}</td>
            <td v-for="m in 12" :key="m" style="text-align:right">{{ fmt(r.monthly[ym(m)]) }}</td>
            <td style="text-align:right;font-weight:700">{{ fmt(r.total_gross) }}</td>
          </tr>
          <tr style="background:var(--surface-muted,#f4f6f8);font-weight:700">
            <td>合计</td><td></td><td></td>
            <td>{{ totals.headcount }}人</td><td></td><td></td><td></td>
            <td v-for="m in 12" :key="m" style="text-align:right">{{ fmt(totals.monthly[ym(m)]) }}</td>
            <td style="text-align:right">{{ fmt(totals.total_gross) }}</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { api, download } from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import { toast } from '@/utils/toast'

const auth = useAuthStore()
const nowYear = new Date().getFullYear()
const yearOptions = [nowYear, nowYear - 1, nowYear - 2]

const year = ref(nowYear)
const project = ref('')
const name = ref('')
const loading = ref(false)
const rows = ref([])
const totals = ref({ headcount: 0, total_gross: 0, monthly: {}, prev_headcount: 0, prev_gross: 0 })
const months = ref([])

const query = computed(() => {
  const p = new URLSearchParams({ year: year.value })
  if (project.value) p.set('project', project.value)
  if (name.value) p.set('name', name.value)
  return p.toString()
})

function ym(m) { return months.value[m - 1] || '' }

function fmt(v) {
  const n = Number(v || 0)
  return n === 0 ? '' : n.toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

async function load() {
  loading.value = true
  try {
    const res = await api(`/api/payroll/annual?${query.value}`)
    if (res.ok) {
      rows.value = res.rows || []
      totals.value = res.totals || totals.value
      months.value = res.months || []
    } else {
      toast(res.error || '加载失败', false)
    }
  } catch (e) {
    toast('加载失败：' + (e.message || e), false)
  } finally {
    loading.value = false
  }
}

function doExport() {
  download(`/api/payroll/annual/export?${query.value}`, `全年发放明细_${year.value}.xlsx`)
}

onMounted(load)
</script>
