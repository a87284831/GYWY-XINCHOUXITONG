<script setup>
// 财务数据驾驶舱（复刻旧财务页 pageDashboard/renderDash：6 KPI + 7 图）
import { ref, nextTick, onMounted, onBeforeUnmount } from 'vue'
import { api } from '@/api/client'
import { toast } from '@/utils/toast'
import { FIN, ensureMeta } from './meta'
import { fmtMoney, trendData, roseData, rankData, histData, heatData, sankeyData, qs, defaultYears } from './financeLogic'
import { charts, trendAreaOpt, sankeyOpt, roseOpt, hbarOpt, heatOpt, histOpt } from './financeCharts'
import './finance.css'

const year = ref(new Date().getFullYear())
const projId = ref(0)
const cards = ref(null)
const d = ref(null)

const KPIS = [
  { key: 'receivable_grand', t: '当年应收总额', s: '元', g: 'linear-gradient(135deg,#3b82f6,#6366f1)' },
  { key: 'payment_grand', t: '付款已确认', s: '元', g: 'linear-gradient(135deg,#06b6d4,#0891b2)' },
  { key: 'payment_paid', t: '当年已支付', s: '元', g: 'linear-gradient(135deg,#10b981,#059669)' },
  { key: 'discount_grand', t: '减免赠送合计', s: '元', g: 'linear-gradient(135deg,#f59e0b,#f97316)' },
]

function kpiVal(k) {
  if (k === 'active_months') return String(d.value.cards.active_months || 0)
  return fmtMoney(d.value.cards[k])
}

async function query() {
  const p = FIN.isAdmin ? projId.value : FIN.projectId || 0
  try {
    const res = await api('/api/finance/chart/dashboard' + qs({ year: year.value, project_id: p }))
    d.value = res
    cards.value = res.cards || {}
    await nextTick()
    charts.dispose()
    const t = trendData(res.monthly_trend)
    charts.init('chTrend', trendAreaOpt(t.months, t.vals, t.cumVals))
    charts.init('chSankey', sankeyOpt(sankeyData(res)))
    charts.init('chCat', roseOpt(roseData(res.category_total, res.categories)))
    charts.init('chProj', hbarOpt(rankData(res.project_compare, 'total'), ['#3b82f6', '#6366f1']))
    charts.init('chDisc', hbarOpt(rankData(res.discount_top, 'discount'), ['#f59e0b', '#f97316']))
    charts.init('chHeat', heatOpt(heatData(res.heatmap)))
    charts.init('chHist', histOpt(histData(res.history)))
  } catch (e) {
    toast(e.message, false)
  }
}

onMounted(async () => {
  await ensureMeta()
  query()
})
onBeforeUnmount(() => charts.dispose())
</script>

<template>
  <div>
    <div style="width:100%;display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
      <select v-model="year" class="fsel">
        <option v-for="y in defaultYears()" :key="y" :value="y">{{ y }}年</option>
      </select>
      <select v-if="FIN.isAdmin" v-model="projId" class="fsel" style="max-width:220px;">
        <option :value="0">全部项目</option>
        <option v-for="p in FIN.projects" :key="p.id" :value="p.id">{{ p.name }}</option>
      </select>
      <button class="fbtn" @click="query">查询</button>
    </div>

    <template v-if="d">
      <div class="dash-kpis">
        <div v-for="k in KPIS" :key="k.key" class="dash-kpi">
          <div class="dash-kpi-bar" :style="{ background: k.g }"></div>
          <div class="dash-kpi-t">{{ k.t }}</div>
          <div class="dash-kpi-v">{{ kpiVal(k.key) }} <span class="dash-kpi-s">{{ k.s }}</span></div>
        </div>
        <div class="dash-kpi">
          <div class="dash-kpi-bar" style="background:linear-gradient(135deg,#8b5cf6,#7c3aed)"></div>
          <div class="dash-kpi-t">有数据月份(应收)</div>
          <div class="dash-kpi-v">{{ String(d.cards.active_months || 0) }} <span class="dash-kpi-s">个月</span></div>
        </div>
        <div class="dash-kpi">
          <div class="dash-kpi-bar" style="background:linear-gradient(135deg,#ec4899,#f43f5e)"></div>
          <div class="dash-kpi-t">付款支付率</div>
          <div class="dash-kpi-v">{{ d.cards.payment_rate || 0 }} <span class="dash-kpi-s">%</span></div>
          <div style="height:8px;background:#eef2f7;border-radius:99px;margin-top:10px;overflow:hidden;">
            <div style="height:100%;background:linear-gradient(90deg,#ec4899,#f43f5e);border-radius:99px;transition:width .6s;"
              :style="{ width: Math.min(d.cards.payment_rate || 0, 100) + '%' }"></div>
          </div>
        </div>
      </div>

      <div class="dash-grid">
        <div class="dash-card" style="grid-column:span 2;"><div class="fc-t">应收月度走势与累计</div><div id="chTrend" style="height:300px;"></div></div>
        <div class="dash-card"><div class="fc-t">付款资金流转</div><div id="chSankey" style="height:300px;"></div></div>
        <div class="dash-card"><div class="fc-t">应收费用构成（玫瑰）</div><div id="chCat" style="height:290px;"></div></div>
        <div class="dash-card"><div class="fc-t">项目应收排名</div><div id="chProj" style="height:290px;"></div></div>
        <div class="dash-card"><div class="fc-t">减免优惠 TOP</div><div id="chDisc" style="height:290px;"></div></div>
        <div class="dash-card" style="grid-column:span 3;"><div class="fc-t">项目 × 月份应收热力图</div><div id="chHeat" style="height:340px;"></div></div>
        <div class="dash-card" style="grid-column:span 3;"><div class="fc-t">历年应收总额演化</div><div id="chHist" style="height:280px;"></div></div>
      </div>
    </template>
  </div>
</template>
