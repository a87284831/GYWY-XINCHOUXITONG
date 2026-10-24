<template>
  <div class="card">
    <h3>薪酬报表</h3>
    <div class="row">
      <label class="fld">统计月份
        <select v-model.number="rep.year" @change="monthNum = 12">
          <option v-for="i in 16" :key="i" :value="2019 + i">{{ 2019 + i }} 年</option>
        </select>
        <select v-model.number="monthNum">
          <option v-for="m in 12" :key="m" :value="m">{{ m }} 月</option>
        </select>
      </label>
      <button class="btn primary" @click="load">刷新</button>
    </div>
    <div style="margin-top:12px">
      <div v-if="loadErr" class="msg err">{{ loadErr }}</div>
      <div v-else-if="!d">加载中...</div>
      <template v-else>
        <div class="rpt-kpi-row">
          <RCard v-for="k in coreKpis" :key="k.key" :label="k.label"
            :value="k.money ? rMoneyOrDash(k.value) : (k.unit === '%' ? k.value + '%' : k.value)"
            :delta="k.delta !== null && k.delta !== undefined ? k.delta : null"
            :color="colorOf(k.key)" />
        </div>
        <div class="rpt-kpi-row">
          <RCard v-for="k in ytdKpis" :key="k.key" :label="k.label"
            :value="k.money ? rMoneyOrDash(k.value) : (k.unit === '%' ? k.value + '%' : k.value)"
            :delta="k.delta !== null && k.delta !== undefined ? k.delta : null"
            :color="colorOf(k.key)" />
        </div>
        <div class="rpt-grid-2">
          <RSection title="月度工资总额趋势（近12月）"><div style="height:260px"><canvas :ref="(el) => setEl('salTrend', el)"></canvas></div></RSection>
          <RSection title="各项目薪酬对比（当月应发）">
            <div v-if="d.project_compare.length" :style="{ height: Math.max(180, d.project_compare.length * 48) + 'px' }"><canvas :ref="(el) => setEl('salDept', el)"></canvas></div>
            <div v-else class="msg info" style="text-align:center;padding:30px">当月无核算数据</div>
          </RSection>
        </div>
        <div class="rpt-grid-2">
          <RSection title="累计应发工资金额（每月人数 + 累计应发）"><div style="height:280px"><canvas :ref="(el) => setEl('salCum', el)"></canvas></div></RSection>
          <RSection title="人均薪酬趋势（近12月）"><div style="height:260px"><canvas :ref="(el) => setEl('salAvg', el)"></canvas></div></RSection>
        </div>
        <div class="rpt-grid-2">
          <RSection title="五险一金与个税月度趋势（近12月）"><div style="height:260px"><canvas :ref="(el) => setEl('salSoc', el)"></canvas></div></RSection>
          <RSection title="月度预算执行率（近12月）"><div style="height:260px"><canvas :ref="(el) => setEl('salBudget', el)"></canvas></div></RSection>
        </div>
        <RSection title="绩效工资分布（固定−基本，当月发放人员）"><div style="height:240px"><canvas :ref="(el) => setEl('salPerf', el)"></canvas></div></RSection>
      </template>
    </div>
  </div>
</template>

<script setup>
// 薪酬报表 — 复刻 pageSalaryReport/salRender（app.js:2361-2441）
import { ref, computed, onMounted, onBeforeUnmount, nextTick } from 'vue'
import { api } from '@/api/client'
import { useReportStore } from '@/stores/report'
import { rMoneyOrDash } from './reportLogic'
import { createCharts, rBar, rHBar, rLine, fmtWan } from './chartKit'
import RCard from './RCard.vue'
import RSection from './RSection.vue'

const rep = useReportStore()
const d = ref(null)
const loadErr = ref('')
const charts = createCharts()
const els = {}
function setEl(key, el) { els[key] = el }

const monthNum = computed({
  get: () => Number(rep.ym.slice(5)),
  set: (v) => { rep.ym = rep.year + '-' + String(v).padStart(2, '0') },
})

const KCOLOR = { active: '#2563eb', count: '#0ea5e9', gross: '#16a34a', net: '#10b981', avg: '#f59e0b',
  ytd_gross: '#8b5cf6', ytd_net: '#6366f1', tax: '#ef4444', soc: '#f97316', ytd_tax: '#dc2626', ytd_soc: '#d97706', budget: '#14b8a6', budget_rate: '#e11d48' }
const colorOf = (k) => KCOLOR[k] || '#2563eb'

// KPI 分两组：核心4 + 累计与预算4
const CORE_KEYS = ['gross', 'net', 'avg', 'count']
const YTD_KEYS = ['ytd_gross', 'ytd_net', 'budget', 'budget_rate']
const coreKpis = computed(() => (d.value?.kpis || []).filter((k) => CORE_KEYS.includes(k.key)))
const ytdKpis = computed(() => (d.value?.kpis || []).filter((k) => YTD_KEYS.includes(k.key)))

async function draw() {
  await nextTick()
  const x = d.value
  if (!x) return
  rLine(charts, 'salTrend', els.salTrend, x.monthly_trend.map((t) => t.month), [
    { label: '应发', data: x.monthly_trend.map((t) => t.gross), color: '#16a34a' },
    { label: '实发', data: x.monthly_trend.map((t) => t.net), color: '#2563eb' },
  ], { fmt: fmtWan })
  if (x.project_compare.length) rHBar(charts, 'salDept', els.salDept, x.project_compare.map((p) => p.label), x.project_compare.map((p) => p.gross), '#0ea5e9', (v) => rMoney(v))
  // 累计应发趋势：柱=当月发放人数（左轴），线=当年累计应发金额（右轴）
  if (x.monthly_trend.length) charts.render('salCum', els.salCum, {
    type: 'bar',
    data: { labels: x.monthly_trend.map((t) => t.month), datasets: [
      { type: 'bar', label: '当月发放人数', data: x.monthly_trend.map((t) => t.cnt), backgroundColor: 'rgba(14,165,233,.55)', borderRadius: 4, yAxisID: 'y' },
      { type: 'line', label: '累计应发金额', data: x.monthly_trend.map((t) => t.cum_gross), borderColor: '#f59e0b', backgroundColor: '#f59e0b', tension: 0.3, borderWidth: 2, pointRadius: 3, yAxisID: 'y1' },
    ] },
    options: { responsive: true, maintainAspectRatio: false,
      plugins: { legend: { position: 'top', labels: { boxWidth: 10, font: { size: 10 }, color: '#475569' } },
        tooltip: { callbacks: { label: (c) => (c.datasetIndex === 0 ? ' 当月发放人数：' + c.parsed.y + ' 人' : ' 累计应发：' + rMoney(c.parsed.y)) } } },
      scales: {
        x: { ticks: { color: '#64748b', font: { size: 10 } }, grid: { color: 'rgba(148,163,184,.15)' } },
        y: { beginAtZero: true, position: 'left', ticks: { color: '#0284c7', font: { size: 10 } }, grid: { color: 'rgba(148,163,184,.15)' }, title: { display: true, text: '人数（人）', color: '#0284c7', font: { size: 10 } } },
        y1: { beginAtZero: true, position: 'right', ticks: { color: '#d97706', font: { size: 10 }, callback: fmtWan }, grid: { drawOnChartArea: false }, title: { display: true, text: '累计应发（元）', color: '#d97706', font: { size: 10 } } },
      } },
  })
  rLine(charts, 'salAvg', els.salAvg, x.monthly_trend.map((t) => t.month), [{ label: '人均应发', data: x.monthly_trend.map((t) => t.avg), color: '#f59e0b' }], { fmt: (v) => rMoney(v) })
  rLine(charts, 'salSoc', els.salSoc, x.monthly_trend.map((t) => t.month), [
    { label: '五险一金', data: x.monthly_trend.map((t) => t.soc), color: '#f97316' },
    { label: '个税', data: x.monthly_trend.map((t) => t.tax), color: '#ef4444' },
  ], { fmt: fmtWan })
  // 月度预算执行率：柱状图 + 100%参考线，超支标红
  if (x.budget_trend.length) {
    const rates = x.budget_trend.map((t) => (t.budget > 0 ? (t.gross / t.budget) * 100 : 0))
    const bgColors = rates.map((r) => (r > 100 ? '#dc2626' : '#165DFF'))
    const yMax = Math.max(120, ...rates.map((r) => r * 1.15))
    const refLinePlugin = {
      id: 'refLine',
      afterDraw: function (chart) {
        var ctx = chart.ctx
        var xScale = chart.scales.x
        var yScale = chart.scales.y
        var y100 = yScale.getPixelForValue(100)
        ctx.save()
        ctx.strokeStyle = '#dc2626'
        ctx.setLineDash([6, 4])
        ctx.lineWidth = 1.5
        ctx.beginPath()
        ctx.moveTo(xScale.left, y100)
        ctx.lineTo(xScale.right, y100)
        ctx.stroke()
        ctx.fillStyle = '#dc2626'
        ctx.font = '600 10px "Microsoft YaHei"'
        ctx.textAlign = 'right'
        ctx.fillText('100% 基准', xScale.right - 6, y100 - 5)
        ctx.restore()
      },
    }
    charts.render('salBudget', els.salBudget, {
      type: 'bar',
      data: { labels: x.budget_trend.map((t) => t.month), datasets: [{ label: '执行率', data: rates, backgroundColor: bgColors, borderRadius: 4, barThickness: 16 }] },
      options: { responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false }, tooltip: { callbacks: { label: function (c) { return ' 执行率：' + c.parsed.y.toFixed(1) + '%' } } } },
        scales: { x: { ticks: { color: '#64748b', font: { size: 10 } }, grid: { display: false } },
          y: { beginAtZero: true, max: yMax, ticks: { color: '#64748b', font: { size: 10 }, callback: function (v) { return v + '%' } }, grid: { color: 'rgba(148,163,184,.15)' } } },
      },
      plugins: [refLinePlugin],
    })
  }
  rBar(charts, 'salPerf', els.salPerf, x.perf_dist.map((v) => (v && typeof v === 'object' ? v.label : v)), x.perf_dist.map((v) => (v && typeof v === 'object' ? v.value : v)), { label: '人数', color: '#14b8a6' })
}

async function load() {
  loadErr.value = ''
  try {
    d.value = await api(`/api/report/salary?ym=${rep.ym}`)
    draw()
  } catch (e) { loadErr.value = e.message }
}

onMounted(load)
onBeforeUnmount(() => charts.destroyAll())
</script>
