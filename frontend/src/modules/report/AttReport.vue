<template>
  <div class="card">
    <h3>考勤报表</h3>
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
          <RCard label="整体出勤率" :value="overallRate + '%'" :color="overallRate >= 90 ? '#16a34a' : overallRate >= 80 ? '#f59e0b' : '#dc2626'" />
          <RCard v-for="(v, k) in d.anomaly_totals" :key="k" :label="k + '（次）'" :value="v" :color="ACOLOR[k] || '#64748b'" />
        </div>
        <RSection :title="`出勤率汇总（${d.ym} · 各项目）`">
          <div v-if="d.projects.length" class="att-prog-list">
            <div v-for="p in d.projects" :key="p.project" class="att-prog-row">
              <span class="att-proj">{{ p.project }}</span>
              <span class="att-track"><span class="att-fill" :class="rateCls(p.rate)" :style="{ width: Math.min(100, p.rate) + '%' }"></span></span>
              <span class="att-pct" :class="rateCls(p.rate)">{{ p.rate }}%</span>
            </div>
          </div>
          <div v-else class="msg info" style="text-align:center;padding:20px">该月份暂无考勤数据</div>
        </RSection>
        <div class="rpt-grid-2">
          <RSection title="异常趋势（近6月）"><div style="height:260px"><canvas :ref="(el) => setEl('attTrend', el)"></canvas></div></RSection>
          <RSection title="各项目出勤率对比">
            <div v-if="d.projects.length" :style="{ height: Math.max(160, d.projects.length * 40) + 'px' }"><canvas :ref="(el) => setEl('attRate', el)"></canvas></div>
            <div v-else class="msg info" style="text-align:center;padding:30px">暂无数据</div>
          </RSection>
        </div>
        <div class="rpt-grid-2">
          <RSection title="出勤符号构成"><div style="height:260px"><canvas :ref="(el) => setEl('attSymbol', el)"></canvas></div></RSection>
          <RSection title="异常人员 Top10">
            <div v-if="d.top.length" :style="{ height: Math.max(180, d.top.length * 32) + 'px' }"><canvas :ref="(el) => setEl('attTop', el)"></canvas></div>
            <div v-else class="msg info" style="text-align:center;padding:30px">本月无异常记录</div>
          </RSection>
        </div>
      </template>
    </div>
  </div>
</template>

<script setup>
// 考勤报表 — 复刻 pageAttReport/attRender（app.js:2444-2492）
import { ref, computed, onMounted, onBeforeUnmount, nextTick } from 'vue'
import { api } from '@/api/client'
import { useReportStore } from '@/stores/report'
import { createCharts, rHBar, rLine } from './chartKit'
import RCard from './RCard.vue'
import RSection from './RSection.vue'

const ACOLOR = { '迟到': '#f59e0b', '早退': '#8b5cf6', '缺卡': '#ef4444', '旷工': '#dc2626', '事假': '#0ea5e9', '病假': '#10b981', '产假': '#e11d48' }
const TREND_KEYS = ['迟到', '早退', '缺卡', '旷工', '事假', '病假', '产假']

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

const rLabel = (v) => (v && typeof v === 'object' ? v.label : v)
const rValue = (v) => (v && typeof v === 'object' ? v.value : v)

// 整体出勤率 = 所有项目加权平均（sum(actual) / sum(required)）
const overallRate = computed(() => {
  const ps = d.value?.projects || []
  const totReq = ps.reduce((s, p) => s + Number(p.required || 0), 0)
  const totAct = ps.reduce((s, p) => s + Number(p.actual || 0), 0)
  return totReq > 0 ? +((totAct / totReq) * 100).toFixed(1) : 0
})

// 出勤率颜色分级
const rateCls = (r) => (r >= 90 ? 'ok' : r >= 80 ? 'warn' : 'over')

async function draw() {
  await nextTick()
  const x = d.value
  if (!x) return
  rLine(charts, 'attTrend', els.attTrend, x.anomaly_trend.map((t) => t.month),
    TREND_KEYS.filter((k) => x.anomaly_trend.some((t) => t[k] > 0)).map((k) => ({ label: k, data: x.anomaly_trend.map((t) => t[k] || 0), color: ACOLOR[k] })))
  if (x.projects.length) rHBar(charts, 'attRate', els.attRate, x.projects.map((p) => p.project), x.projects.map((p) => p.rate), '#f59e0b', (v) => v + '%')
  if (x.symbol_dist.length) rHBar(charts, 'attSymbol', els.attSymbol, x.symbol_dist.map(rLabel), x.symbol_dist.map(rValue), '#0ea5e9')
  // 异常人员 Top10：横向条形图
  if (x.top.length) {
    const top = x.top.slice().sort((a, b) => a.anomalyCount - b.anomalyCount)
    rHBar(charts, 'attTop', els.attTop,
      top.map((t) => t.name + '（' + (t.anomalies || []).join('/') + '）'),
      top.map((t) => t.anomalyCount),
      '#dc2626', (v) => v + ' 次')
  }
}

async function load() {
  loadErr.value = ''
  try {
    d.value = await api(`/api/report/attendance?ym=${rep.ym}`)
    draw()
  } catch (e) { loadErr.value = e.message }
}

onMounted(load)
onBeforeUnmount(() => charts.destroyAll())
</script>

<style scoped>
.att-prog-list { display: flex; flex-direction: column; gap: 8px; padding: 4px 2px; }
.att-prog-row { display: grid; grid-template-columns: 80px 1fr 56px; align-items: center; gap: 10px; }
.att-proj { font-size: 12px; font-weight: 600; color: #1D2129; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.att-track { display: block; height: 10px; border-radius: 99px; background: #F2F3F5; overflow: hidden; }
.att-fill { display: block; height: 100%; border-radius: 99px; background: linear-gradient(90deg, #4080FF, #165DFF); }
.att-fill.ok { background: linear-gradient(90deg, #5BD07C, #16a34a); }
.att-fill.warn { background: linear-gradient(90deg, #FFC46B, #f59e0b); }
.att-fill.over { background: linear-gradient(90deg, #FF9A9A, #dc2626); }
.att-pct { font-size: 12px; font-weight: 700; text-align: right; color: #1D2129; font-variant-numeric: tabular-nums; }
.att-pct.ok { color: #16a34a; }
.att-pct.warn { color: #f59e0b; }
.att-pct.over { color: #dc2626; }
</style>
