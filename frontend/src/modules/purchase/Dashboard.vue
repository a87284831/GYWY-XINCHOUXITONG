<script setup>
// 采购数据驾驶舱（复刻旧 #/dashboard：4 KPI + 分区图/表，一次取 /dashboard/all 12 组；price-trend 单独请求）
import { ref, computed, onMounted } from 'vue'
import { api } from '@/api/client'
import { toast } from '@/utils/toast'
import { useAuthStore } from '@/stores/auth'
import { money } from '@/utils/format'
import ChartBox from '@/components/ChartBox.vue'
import { prevMonth, sumRows, signedMoney, deltaInfo, rateText, topWindow, qs } from './purchaseLogic'
import {
  projAmountOpt, linesPieOpt, compareOpt, budgetExecOpt, customRatioOpt,
  annualTrendOpt, hbarOpt, priceTrendOpt,
} from './dashCharts'
import '../finance/finance.css'

const auth = useAuthStore()
const month = ref(new Date().toISOString().slice(0, 7))
const d = ref(null)
const loading = ref(false)

const isStaff = computed(() => !!auth.user && auth.user.role !== 'admin')
const staffProject = computed(() => (auth.user && auth.user.project_name) || '')

// —— KPI ——
const kpiDelta = computed(() => {
  if (!d.value) return { text: '', up: null }
  return deltaInfo(d.value.monthly.overview.amount, sumRows(d.value.compare.rows).prev)
})
const ytdSub = computed(() => {
  if (!d.value) return { text: '', up: null }
  const y = d.value.ytd
  if (!y.prev_ytd) return { text: '上年同期无数据', up: null }
  return { text: '较上年同期 ' + signedMoney(y.diff) + ' 元', up: y.diff > 0 ? true : y.diff < 0 ? false : null }
})

function deltaStyle(up) {
  return { color: up === true ? '#dc2626' : up === false ? '#16a34a' : '#94a3b8' }
}

// —— 价格异常表 ——
const pa = computed(() => (d.value ? d.value.price_anomalies : null))
function rateCell(r) {
  const v = (r.rate > 0 ? '+' : '') + Number(r.rate).toFixed(1) + '%'
  return { text: v, up: r.trend === 'up' }
}

// —— 商品价格趋势 ——
const itemName = ref('')
const searching = ref(false)
const productOpts = ref([])
const trend = ref(null)

async function searchProducts(kw) {
  if (!kw || !kw.trim()) { productOpts.value = []; return }
  searching.value = true
  try {
    const res = await api('/api/purchase/products/search?keyword=' + encodeURIComponent(kw.trim()))
    const seen = []
    ;(res.data || []).forEach((p) => { if (!seen.includes(p.name)) seen.push(p.name) })
    productOpts.value = seen.map((n) => ({ value: n, label: n }))
  } catch (e) {
    toast(e.message, false)
  } finally {
    searching.value = false
  }
}

async function loadTrend() {
  if (!itemName.value) { trend.value = null; return }
  try {
    const res = await api('/api/purchase/dashboard/price-trend' + qs({ item: itemName.value, year: d.value.year }))
    trend.value = res.data
  } catch (e) {
    toast(e.message, false)
  }
}

const yearAvgText = computed(() => {
  if (!trend.value || !trend.value.year_avg) return ''
  const keys = Object.keys(trend.value.year_avg).sort().reverse()
  if (!keys.length) return ''
  return keys.map((y) => y + '年均价 ' + money(trend.value.year_avg[y]) + ' 元').join('，')
})

async function load() {
  loading.value = true
  try {
    const res = await api('/api/purchase/dashboard/all' + qs({ month: month.value }))
    d.value = res.data
    trend.value = null
  } catch (e) {
    toast(e.message, false)
  } finally {
    loading.value = false
  }
}

const prevMonthText = computed(() => (d.value ? prevMonth(d.value.month) : ''))

onMounted(load)
</script>

<template>
  <div>
    <div style="width:100%;display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
      <span style="font-size:13px;color:#6b7280;">月度数据：</span>
      <el-date-picker v-model="month" type="month" value-format="YYYY-MM" :clearable="false"
        style="width:160px" @change="load" />
      <el-button type="primary" :loading="loading" @click="load">刷新数据</el-button>
      <span v-if="isStaff" style="font-size:12.5px;color:#94a3b8;">当前仅显示 {{ staffProject }} 项目数据</span>
    </div>

    <template v-if="d">
      <div class="dash-kpis" style="grid-template-columns:repeat(4,1fr);">
        <div class="dash-kpi">
          <div class="dash-kpi-bar" style="background:linear-gradient(135deg,#3b82f6,#6366f1)"></div>
          <div class="dash-kpi-t">本月采购金额</div>
          <div class="dash-kpi-v">{{ money(d.monthly.overview.amount) }} <span class="dash-kpi-s">元</span></div>
          <div style="font-size:12px;margin-top:4px;" :style="deltaStyle(kpiDelta.up)">{{ kpiDelta.text }}</div>
        </div>
        <div class="dash-kpi">
          <div class="dash-kpi-bar" style="background:linear-gradient(135deg,#06b6d4,#0891b2)"></div>
          <div class="dash-kpi-t">当年度累计采购金额</div>
          <div class="dash-kpi-v">{{ money(d.ytd.ytd) }} <span class="dash-kpi-s">元</span></div>
          <div style="font-size:12px;margin-top:4px;" :style="deltaStyle(ytdSub.up)">{{ ytdSub.text }}</div>
        </div>
        <div class="dash-kpi">
          <div class="dash-kpi-bar" style="background:linear-gradient(135deg,#10b981,#059669)"></div>
          <div class="dash-kpi-t">本月采购项目</div>
          <div class="dash-kpi-v">{{ d.monthly.overview.projects }} <span class="dash-kpi-s">共 {{ d.monthly.overview.projects }} 个项目</span></div>
        </div>
        <div class="dash-kpi">
          <div class="dash-kpi-bar" style="background:linear-gradient(135deg,#f59e0b,#f97316)"></div>
          <div class="dash-kpi-t">本月采购条目</div>
          <div class="dash-kpi-v">{{ d.monthly.overview.items }} <span class="dash-kpi-s">条</span></div>
        </div>
      </div>

      <div class="fc-sec">月度分析</div>
      <div class="dash-grid">
        <div class="dash-card"><div class="fc-t">各条线采购金额占比</div><ChartBox :option="linesPieOpt(d.monthly.lines)" :height="280" /></div>
        <div class="dash-card"><div class="fc-t">各项目采购金额</div><ChartBox :option="projAmountOpt(d.monthly.projects)" :height="280" /></div>
        <div class="dash-card"><div class="fc-t">各项目环比（红涨绿跌）</div><ChartBox :option="compareOpt(d.compare.rows, '本月', prevMonthText)" :height="280" /></div>
        <div class="dash-card"><div class="fc-t">各项目同比</div><ChartBox :option="compareOpt(d.yoy.rows, '本月', '去年同月')" :height="280" /></div>
      </div>

      <div class="fc-sec">预算与流程</div>
      <div class="dash-grid">
        <div class="dash-card"><div class="fc-t">各项目预算执行（预算 vs 实际）</div><ChartBox :option="budgetExecOpt(d.budget_exec.rows)" :height="280" /></div>
        <div class="dash-card"><div class="fc-t">清单外采购占比</div><ChartBox :option="customRatioOpt(d.custom_ratio)" :height="280" /></div>
        <div class="dash-card" style="grid-column:span 1;">
          <div class="fc-t">填报进度</div>
          <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:10px;">
            <span class="att-tag none">项目 {{ d.fill_progress.filled_projects }}/{{ d.fill_progress.total_projects }}</span>
            <span class="att-tag has">已提交 {{ d.fill_progress.submitted_projects }}</span>
            <span class="att-tag has">已确认 {{ d.fill_progress.confirmed_projects }}</span>
            <span class="att-tag none">已退回 {{ d.fill_progress.returned_projects }}</span>
            <span class="att-tag none">条目 {{ d.fill_progress.items_total }}（提交 {{ d.fill_progress.items_submitted }} / 确认 {{ d.fill_progress.items_confirmed }}）</span>
          </div>
          <table class="ftbl">
            <thead><tr><th>项目</th><th>总条目</th><th>已提交</th><th>已确认</th><th>已退回</th><th>状态</th></tr></thead>
            <tbody>
              <tr v-for="r in d.fill_progress.rows" :key="r.project_id">
                <td>{{ r.project_name }}</td>
                <td>{{ r.total }}</td>
                <td>{{ r.submitted }}</td>
                <td>{{ r.confirmed }}</td>
                <td>{{ r.returned }}</td>
                <td>{{ r.status }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <div class="fc-sec">年度分析</div>
      <div class="dash-grid">
        <div class="dash-card" style="grid-column:span 2;"><div class="fc-t">年度采购金额逐月趋势</div><ChartBox :option="annualTrendOpt(d.annual.rows)" :height="300" /></div>
        <div class="dash-card"><div class="fc-t">年度各条线采购金额</div><ChartBox :option="linesPieOpt(d.annual_lines.rows)" :height="300" /></div>
        <div class="dash-card" style="grid-column:span 2;"><div class="fc-t">年度各项目采购金额</div><ChartBox :option="projAmountOpt(d.annual_projects.rows)" :height="300" /></div>
        <div class="dash-card">
          <div class="fc-t">近3月高频采购商品 TOP20（按采购次数）<span v-if="topWindow(d.top)" style="font-weight:400;color:#94a3b8;">（{{ topWindow(d.top) }}）</span></div>
          <ChartBox :option="hbarOpt(d.top.rows, 'item_name', 'times', '#8b5cf6', '次')" :height="300" />
        </div>
      </div>

      <div class="fc-sec">价格监控</div>
      <div class="dash-grid">
        <div class="dash-card" style="grid-column:span 2;">
          <div class="fc-t">价格异常监控（环比）<span style="font-weight:400;color:#94a3b8;">（阈值 {{ pa.threshold }}%，{{ pa.total > 0 ? '发现 ' + pa.total + ' 种商品价格波动超阈值' : '本月无价格异常商品' }}）</span></div>
          <table class="ftbl">
            <thead><tr><th>商品名称</th><th>本月均价</th><th>上月均价</th><th>环比变化</th><th>本月采购</th><th>总量</th></tr></thead>
            <tbody>
              <tr v-if="!pa.rows.length"><td colspan="6" style="text-align:center;color:#94a3b8;">本月无价格异常商品</td></tr>
              <tr v-for="r in pa.rows" :key="r.item_name">
                <td>{{ r.item_name }}</td>
                <td>{{ money(r.cur_price) }}</td>
                <td>{{ money(r.prev_price) }}</td>
                <td :style="deltaStyle(rateCell(r).up)">{{ rateCell(r).text }}</td>
                <td>{{ r.cnt }} 次</td>
                <td>{{ r.qty }} {{ r.units }}</td>
              </tr>
            </tbody>
          </table>
        </div>
        <div class="dash-card">
          <div class="fc-t">商品价格趋势（月度）</div>
          <el-select v-model="itemName" filterable remote :remote-method="searchProducts" :loading="searching"
            placeholder="输入商品名称搜索（如 A4纸）" style="width:100%;margin-bottom:10px;" @change="loadTrend">
            <el-option v-for="o in productOpts" :key="o.value" :value="o.value" :label="o.label" />
          </el-select>
          <div v-if="yearAvgText" style="font-size:12.5px;color:#6b7280;margin-bottom:6px;">年度均价：{{ yearAvgText }}</div>
          <ChartBox v-if="trend" :option="priceTrendOpt(trend)" :height="240" />
          <div v-else style="font-size:12.5px;color:#94a3b8;padding:40px 0;text-align:center;">搜索并选择商品后展示其各月平均采购单价走势</div>
        </div>
      </div>
    </template>
  </div>
</template>
