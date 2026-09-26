<script setup>
// 汇总分析（复刻旧财务页 pageSummary/renderSummary：年度×类别 / 项目应收 / 付款汇总 三表）
import { ref, computed, onMounted } from 'vue'
import { api, download } from '@/api/client'
import { toast } from '@/utils/toast'
import { FIN, ensureMeta } from './meta'
import { fmtMoney, moneyCell, summaryTotals, qs, defaultYears } from './financeLogic'
import './finance.css'

const year = ref(new Date().getFullYear())
const a = ref(null)
const pr = ref(null)
const pay = ref(null)

const cats = computed(() => Object.keys(a.value?.categories || {}))
const payTypes = computed(() => Object.keys(pay.value?.types || {}))
const yrLabel = computed(() => (year.value === 'all' ? '截至目前' : year.value + '年'))
const catTotals = computed(() => summaryTotals(a.value || {}))

async function load() {
  const p = FIN.isAdmin ? 0 : FIN.projectId || 0
  try {
    const [ra, rpr, rpay] = await Promise.all([
      api('/api/finance/summary/annual' + qs({ year: year.value, project_id: p })),
      api('/api/finance/summary/projects' + qs({ year: year.value })),
      api('/api/finance/summary/payments' + qs({ year: year.value })),
    ])
    a.value = ra
    pr.value = rpr
    pay.value = rpay
  } catch (e) {
    toast(e.message, false)
  }
}

function exportXlsx() {
  const p = FIN.isAdmin ? 0 : FIN.projectId || 0
  download('/api/finance/export_summary' + qs({ year: year.value, project_id: p }),
    `财务汇总_${year.value === 'all' ? '全部年度' : year.value}.xlsx`)
}

onMounted(async () => {
  await ensureMeta()
  load()
})
</script>

<template>
  <div>
    <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
      <select v-model="year" class="fsel">
        <option value="all">截至目前（全部年度）</option>
        <option v-for="y in defaultYears()" :key="y" :value="y">{{ y }}年</option>
      </select>
      <button class="fbtn" @click="load">查询</button>
      <button class="fbtn ghost" @click="exportXlsx">导出 Excel</button>
      <span style="color:#888;font-size:12px;">选“截至目前”显示全部年度的累计数据</span>
    </div>

    <template v-if="a">
      <div class="fc-sec">{{ yrLabel }}应收汇总（月份 × 类别）</div>
      <div style="overflow:auto;">
        <table class="ftbl">
          <thead>
            <tr>
              <th>月份</th>
              <th v-for="k in cats" :key="k">{{ a.categories[k] }}</th>
              <th>合计</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="m in a.months" :key="m">
              <td>{{ m }}</td>
              <td v-for="k in cats" :key="k">{{ moneyCell(a.grid[m][k]) }}</td>
              <td style="font-weight:600;">{{ fmtMoney(a.month_total[m]) }}</td>
            </tr>
            <tr style="background:#f0f4fa;font-weight:700;">
              <td>{{ year === 'all' ? '累计合计' : '年度合计' }}</td>
              <td v-for="k in cats" :key="k">{{ fmtMoney(catTotals[k]) }}</td>
              <td>{{ fmtMoney(a.grand_total) }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <template v-if="pr">
        <div class="fc-sec" style="margin-top:22px;">{{ yrLabel }}项目应收汇总</div>
        <div style="overflow:auto;">
          <table class="ftbl">
            <thead>
              <tr>
                <th>项目</th>
                <th v-for="k in cats" :key="k">{{ pr.categories[k] }}</th>
                <th>应收合计</th>
                <th>减免赠送</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="pj in pr.projects.filter((x) => x.total > 0)" :key="pj.name">
                <td>{{ pj.name }}</td>
                <td v-for="k in cats" :key="k">{{ moneyCell(pj.categories[k]) }}</td>
                <td style="font-weight:600;">{{ fmtMoney(pj.total) }}</td>
                <td>{{ fmtMoney(pj.discount_total) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </template>

      <template v-if="pay">
        <div class="fc-sec" style="margin-top:22px;">{{ yrLabel }}付款汇总（项目 × 类型 · 已确认数据口径）</div>
        <div style="overflow:auto;">
          <table class="ftbl">
            <thead>
              <tr>
                <th>项目</th>
                <th v-for="k in payTypes" :key="k">{{ pay.types[k] }}</th>
                <th>合计</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="pj in pay.projects.filter((x) => x.total > 0)" :key="pj.name">
                <td>{{ pj.name }}</td>
                <td v-for="k in payTypes" :key="k">{{ moneyCell(pj.types[k]) }}</td>
                <td style="font-weight:600;">{{ fmtMoney(pj.total) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </template>
    </template>
  </div>
</template>
