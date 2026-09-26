<template>
  <div class="card">
    <h3>人力资源报表</h3>
    <div class="row" style="flex-wrap:wrap">
      <label class="fld">查看维度
        <select v-model="annualBool">
          <option :value="false">按月</option>
          <option :value="true">按年</option>
        </select></label>
      <label class="fld">年份 <select v-model.number="rep.year">
        <option v-for="y in yearOpts" :key="y" :value="y">{{ y }} 年</option>
      </select></label>
      <span v-if="!annualBool" style="display:flex;gap:8px">
        <label class="fld">月份 <select v-model.number="monthNum">
          <option v-for="m in 12" :key="m" :value="m">{{ m }} 月</option>
        </select></label>
      </span>
      <button class="btn primary" @click="load">刷新</button>
    </div>
    <div style="margin-top:12px">
      <div v-if="loadErr" class="msg err">{{ loadErr }}</div>
      <div v-else-if="!d">加载中...</div>
      <template v-else>
        <div class="rpt-kpi-row">
          <RCard label="在职员工总数" :value="d.kpis.active" :delta="d.deltas.active" sub="含试用，不含离职" color="#2563eb" />
          <RCard label="本月入职" :value="d.kpis.in" :delta="d.deltas.in" color="#16a34a" />
          <RCard label="本月离职" :value="d.kpis.out" :delta="d.deltas.out" color="#dc2626" />
          <RCard label="本月转正" :value="d.kpis.regular" :delta="d.deltas.regular" color="#8b5cf6" />
          <RCard label="人均薪酬" :value="rMoney(d.kpis.avg_pay)" :delta="d.deltas.avg_pay" sub="当月应发/发放人数" color="#f59e0b" />
          <RCard label="人均司龄" :value="d.kpis.avg_tenure + ' 年'" sub="在职员工平均司龄" color="#0ea5e9" />
        </div>
        <div class="rpt-grid-2">
          <RSection title="人员结构分析（按项目）">
            <div v-if="d.structure.length" :style="{ height: Math.max(150, d.structure.length * 40) + 'px' }"><canvas :ref="(el) => setEl('hrStruct', el)"></canvas></div>
            <div v-else class="msg info" style="text-align:center;padding:30px">暂无项目数据</div>
          </RSection>
          <RSection title="人员趋势（近6月）">
            <div v-if="d.trend.length" style="height:260px"><canvas :ref="(el) => setEl('hrTrend', el)"></canvas></div>
            <div v-else class="msg info" style="text-align:center;padding:30px">暂无数据</div>
          </RSection>
        </div>
        <div class="rpt-grid-2">
          <RSection title="年龄结构"><div style="height:240px"><canvas :ref="(el) => setEl('hrAge', el)"></canvas></div></RSection>
          <RSection title="司龄分布"><div style="height:240px"><canvas :ref="(el) => setEl('hrTenure', el)"></canvas></div></RSection>
        </div>
        <div class="rpt-grid-2">
          <RSection title="学历分布">
            <div v-if="d.edu_dist.length" style="height:240px"><canvas :ref="(el) => setEl('hrEdu', el)"></canvas></div>
            <div v-else class="msg info" style="text-align:center;padding:30px">暂未录入学历信息</div>
          </RSection>
          <RSection title="籍贯分布 Top10（按市）">
            <div v-if="d.hometown_top.length" :style="{ height: Math.max(180, d.hometown_top.length * 30) + 'px' }"><canvas :ref="(el) => setEl('hrHome', el)"></canvas></div>
            <div v-else class="msg info" style="text-align:center;padding:30px">暂未录入籍贯信息</div>
          </RSection>
        </div>
        <div class="rpt-grid-2">
          <RSection title="入职与离职分析（近6月）"><div style="height:240px"><canvas :ref="(el) => setEl('hrInOut', el)"></canvas></div></RSection>
          <RSection title="人员状态分布"><div style="height:240px"><canvas :ref="(el) => setEl('hrStatus', el)"></canvas></div></RSection>
        </div>
      </template>
    </div>
  </div>
</template>

<script setup>
// 人力资源报表 — 复刻 pageHrReport/hrRender（app.js:2280-2358）
import { ref, computed, watch, onMounted, onBeforeUnmount, nextTick } from 'vue'
import { api } from '@/api/client'
import { useReportStore } from '@/stores/report'
import { rMoney } from './reportLogic'
import { createCharts, rBar, rHBar, rLine } from './chartKit'
import RCard from './RCard.vue'
import RSection from './RSection.vue'

const rep = useReportStore()
const d = ref(null)
const loadErr = ref('')
const charts = createCharts()
const els = {}
function setEl(key, el) { els[key] = el }

const yearOpts = Array.from({ length: 2035 - 2020 + 1 }, (_, i) => 2020 + i)
const monthNum = computed({
  get: () => Number(rep.ym.slice(5)),
  set: (v) => { rep.ym = rep.year + '-' + String(v).padStart(2, '0') },
})
const annualBool = computed({
  get: () => rep.annual,
  set: (v) => { rep.annual = v },
})

const rLabel = (v) => (v && typeof v === 'object' ? v.label : v)
const rValue = (v) => (v && typeof v === 'object' ? v.value : v)

async function draw() {
  await nextTick()
  const x = d.value
  if (!x) return
  if (x.structure.length) rHBar(charts, 'hrStruct', els.hrStruct, x.structure.map(rLabel), x.structure.map(rValue), '#2563eb')
  if (x.trend.length) rLine(charts, 'hrTrend', els.hrTrend, x.trend.map((t) => t.month), [
    { label: '在职人数', data: x.trend.map((t) => t.active), color: '#2563eb' },
    { label: '入职', data: x.trend.map((t) => t.in), color: '#16a34a' },
    { label: '离职', data: x.trend.map((t) => t.out), color: '#dc2626' },
  ])
  rBar(charts, 'hrAge', els.hrAge, x.age_dist.map(rLabel), x.age_dist.map(rValue), { label: '人数', color: '#8b5cf6' })
  rBar(charts, 'hrTenure', els.hrTenure, x.tenure_dist.map(rLabel), x.tenure_dist.map(rValue), { label: '人数', color: '#0ea5e9' })
  if (x.edu_dist.length) rHBar(charts, 'hrEdu', els.hrEdu, x.edu_dist.map(rLabel), x.edu_dist.map(rValue), '#16a34a')
  if (x.hometown_top.length) rHBar(charts, 'hrHome', els.hrHome, x.hometown_top.map(rLabel), x.hometown_top.map(rValue), '#f59e0b')
  rLine(charts, 'hrInOut', els.hrInOut, x.trend.map((t) => t.month), [
    { label: '入职', data: x.trend.map((t) => t.in), color: '#16a34a' },
    { label: '离职', data: x.trend.map((t) => t.out), color: '#dc2626' },
  ])
  rBar(charts, 'hrStatus', els.hrStatus, x.status_dist.map(rLabel), x.status_dist.map(rValue), { label: '人数', color: '#64748b' })
}

async function load() {
  loadErr.value = ''
  try {
    const ym = rep.annual ? '' : rep.ym
    d.value = await api(`/api/report/hr?year=${rep.year}${ym ? '&ym=' + ym : ''}&annual=${rep.annual ? 1 : 0}`)
    draw()
  } catch (e) { loadErr.value = e.message }
}

watch(() => [rep.year, rep.ym, rep.annual], () => { if (d.value) load() })
onMounted(load)
onBeforeUnmount(() => charts.destroyAll())
</script>
