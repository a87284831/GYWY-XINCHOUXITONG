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
          <RCard v-for="(v, k) in d.anomaly_totals" :key="k" :label="k + '（次）'" :value="v" :color="ACOLOR[k] || '#64748b'" />
        </div>
        <RSection :title="`出勤率汇总（${d.ym} · 各项目）`">
          <div class="table-wrap">
            <table class="tb">
              <thead><tr><th>项目</th><th>人数</th><th>应出勤(天)</th><th>实际出勤(天)</th><th>出勤率</th></tr></thead>
              <tbody>
                <tr v-if="!d.projects.length"><td colspan="5" style="text-align:center;color:#94a3b8;padding:20px">该月份暂无考勤数据</td></tr>
                <tr v-for="p in d.projects" :key="p.project">
                  <td><b>{{ p.project }}</b></td><td>{{ p.headcount }}</td><td>{{ p.required }}</td><td>{{ p.actual }}</td>
                  <td><b :style="{ color: p.rate >= 90 ? '#16a34a' : p.rate >= 80 ? '#f59e0b' : '#dc2626' }">{{ p.rate }}%</b></td>
                </tr>
              </tbody>
            </table>
          </div>
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
            <div v-if="d.top.length" class="table-wrap">
              <table class="tb">
                <thead><tr><th>#</th><th>姓名</th><th>项目</th><th>异常次数</th><th>异常类型</th></tr></thead>
                <tbody>
                  <tr v-for="(t, i) in d.top" :key="i">
                    <td>{{ i + 1 }}</td><td><b>{{ t.name }}</b></td><td>{{ t.project }}</td>
                    <td style="color:#dc2626;font-weight:700">{{ t.anomalyCount }}</td>
                    <td>{{ (t.anomalies || []).join('、') || '-' }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
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

async function draw() {
  await nextTick()
  const x = d.value
  if (!x) return
  rLine(charts, 'attTrend', els.attTrend, x.anomaly_trend.map((t) => t.month),
    TREND_KEYS.filter((k) => x.anomaly_trend.some((t) => t[k] > 0)).map((k) => ({ label: k, data: x.anomaly_trend.map((t) => t[k] || 0), color: ACOLOR[k] })))
  if (x.projects.length) rHBar(charts, 'attRate', els.attRate, x.projects.map((p) => p.project), x.projects.map((p) => p.rate), '#f59e0b', (v) => v + '%')
  if (x.symbol_dist.length) rHBar(charts, 'attSymbol', els.attSymbol, x.symbol_dist.map(rLabel), x.symbol_dist.map(rValue), '#0ea5e9')
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
