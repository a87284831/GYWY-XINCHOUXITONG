<template>
  <div class="card">
    <h3>{{ isFire ? '🧯 消防维保报表' : '🛗 电梯维保报表' }}（{{ year }}年）</h3>
    <div class="row">
      <label class="fld">统计年度 <select v-model.number="year" @change="load">
        <option v-for="y in yearOpts" :key="y" :value="y">{{ y }} 年</option>
      </select></label>
      <button class="btn primary" @click="load">刷新</button>
      <button class="btn" @click="router.push(isFire ? '/maintFire' : '/maintElev')">前往{{ isFire ? '消防' : '电梯' }}台账</button>
    </div>
    <div style="margin-top:12px">
      <div v-if="loadErr" class="msg err">{{ loadErr }}</div>
      <div v-else-if="!d">加载中...</div>
      <template v-else>
        <div class="rpt-kpi-row">
          <RCard label="维保合同数" :value="d.contract_count" sub="本年度有效合同" color="#2563eb" />
          <RCard label="在管项目数" :value="d.project_count" sub="覆盖物业项目" color="#0ea5e9" />
          <RCard label="签约总金额" :value="rMoney(d.total_amount)" sub="本年度" color="#16a34a" />
          <RCard label="风险合同" :value="d.risk_total" sub="临期/已过期" color="#ef4444" />
        </div>
        <div class="rpt-grid-2">
          <RSection title="合同状态分布"><div style="height:240px"><canvas :ref="(el) => setEl('state', el)"></canvas></div></RSection>
          <RSection title="合作方金额分布">
            <div v-if="d.partner_amount.length" style="height:240px"><canvas :ref="(el) => setEl('partner', el)"></canvas></div>
            <div v-else class="msg info" style="text-align:center;padding:30px">无签约合作方数据</div>
          </RSection>
        </div>
        <div class="rpt-grid-2">
          <RSection title="各项目维保金额">
            <div v-if="d.project_amount.length" :style="{ height: Math.max(180, d.project_amount.length * 44) + 'px' }"><canvas :ref="(el) => setEl('proj', el)"></canvas></div>
            <div v-else class="msg info" style="text-align:center;padding:30px">暂无项目数据</div>
          </RSection>
          <RSection :title="`各合作方${isFire ? '维保面积' : '维保台数'}分布`">
            <div v-if="d.partner_scale.length" :style="{ height: Math.max(180, d.partner_scale.length * 44) + 'px' }"><canvas :ref="(el) => setEl('scale', el)"></canvas></div>
            <div v-else class="msg info" style="text-align:center;padding:30px">暂无数据</div>
          </RSection>
        </div>
        <RSection title="⚡ 风险合同清单">
          <div class="table-wrap"><table class="tb">
            <thead><tr><th>项目名称</th><th>签约方</th><th>签约金额</th><th>到期日期</th><th>剩余天数</th><th>员工状态</th></tr></thead>
            <tbody>
              <tr v-if="!d.risk_list.length"><td colspan="6" style="text-align:center;color:#94a3b8;padding:20px">✅ 本年度无临期/已过期合同</td></tr>
              <tr v-for="(r, i) in d.risk_list" :key="i">
                <td><b>{{ r.project }}</b></td><td>{{ r.party }}</td><td class="num">{{ rMoney(r.amount) }}</td><td>{{ r.end_date }}</td><td>{{ daysText(r.end_date) }}</td>
                <td><span :class="r.status === 'expired' ? 'tag red' : 'tag orange'">{{ RISK_STATE_TEXT[r.status] || r.status }}</span></td>
              </tr>
            </tbody>
          </table></div>
        </RSection>
      </template>
    </div>
  </div>
</template>

<script setup>
// 消防/电梯维保报表 — 复刻 pageMaintFireReport/pageMaintElevReport/pageMaintReport/maintReportRender（app.js:2556-2618）
import { ref, onMounted, onBeforeUnmount, nextTick } from 'vue'
import { useRouter } from 'vue-router'
import { api } from '@/api/client'
import { rMoney } from '@/modules/report/reportLogic'
import { createCharts, rBar, rHBar, rDoughnut, PALETTE } from '@/modules/report/chartKit'
import RCard from '@/modules/report/RCard.vue'
import RSection from '@/modules/report/RSection.vue'

const props = defineProps({ type: { type: String, default: 'fire' } })
const router = useRouter()

const isFire = props.type === 'fire'
const yearOpts = Array.from({ length: 2035 - 2020 + 1 }, (_, i) => 2020 + i)
const year = ref(new Date().getFullYear())
const d = ref(null)
const loadErr = ref('')
const charts = createCharts()
const els = {}
function setEl(key, el) { els[key] = el }

// 状态文本：报表专用（旧版 maintReportRender 内的 STATE_TEXT）
const RISK_STATE_TEXT = { normal: '正常在保', soon: '30天内到期', expired: '已过期' }
const STATE_TEXT = { normal: '正常在保', soon: '30天内到期', expired: '已过期' }
const rLabel = (v) => (v && typeof v === 'object' ? v.label : v)
const rValue = (v) => (v && typeof v === 'object' ? v.value : v)

async function draw() {
  await nextTick()
  const x = d.value
  if (!x) return
  rBar(charts, 'state', els.state, x.state_dist.map((x2) => STATE_TEXT[x2.label] || x2.label), x.state_dist.map(rValue), { label: '合同数', color: '#f59e0b' })
  if (x.partner_amount.length) rDoughnut(charts, 'partner', els.partner, x.partner_amount.map(rLabel), x.partner_amount.map(rValue), PALETTE, '总金额', x.total_amount, rMoney)
  if (x.project_amount.length) rHBar(charts, 'proj', els.proj, x.project_amount.map((p) => p.name), x.project_amount.map((p) => p.amount), isFire ? '#f59e0b' : '#3b82f6', rMoney)
  if (x.partner_scale.length) rHBar(charts, 'scale', els.scale, x.partner_scale.map(rLabel), x.partner_scale.map(rValue), isFire ? '#0ea5e9' : '#8b5cf6', (v) => v + (isFire ? ' ㎡' : ' 台'))
}

async function load() {
  loadErr.value = ''
  try {
    d.value = await api(`/api/maintenance/${isFire ? 'fire' : 'elev'}_report?year=${year.value}`)
    draw()
  } catch (e) { loadErr.value = e.message }
}

function daysText(end) {
  const diff = Math.ceil((new Date(end + 'T00:00:00') - new Date()) / 86400000)
  return diff > 0 ? diff + '天' : diff === 0 ? '今天' : Math.abs(diff) + '天前已过'
}

onMounted(load)
onBeforeUnmount(() => charts.destroyAll())
</script>
