<script setup>
// 采购数据驾驶舱（4 KPI + 月度/预算/年度/价格四个分区，一次取 /dashboard/all；价格趋势单独请求）
// 视觉：方向 B 浅色为主，可切换方向 C 深色面板（仅本页）
import { ref, computed, onMounted } from 'vue'
import { api } from '@/api/client'
import { toast } from '@/utils/toast'
import { useAuthStore } from '@/stores/auth'
import { moneyOrDash } from '@/utils/format'
import ChartBox from '@/components/ChartBox.vue'
import { prevMonth, sumRows, signedMoney, deltaInfo, topWindow, qs } from './purchaseLogic'
import { linesPieOpt, annualTrendOpt, hbarOpt, priceTrendOpt } from './dashCharts'
import '../finance/finance.css'
import './dashboard.css'

const auth = useAuthStore()
const month = ref(new Date().toISOString().slice(0, 7))
const d = ref(null)
const loading = ref(false)
const updatedAt = ref('')

const isStaff = computed(() => !!auth.user && auth.user.role !== 'admin')
const staffProject = computed(() => (auth.user && auth.user.project_name) || '')

// —— 主题（仅本页，记忆到 localStorage）——
const dark = ref(localStorage.getItem('dsh-theme') === 'dark')
function setDark(v) {
  dark.value = v
  localStorage.setItem('dsh-theme', v ? 'dark' : 'light')
}

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

function deltaCls(up) {
  return up === true ? 'up' : up === false ? 'down' : 'flat'
}

// 近 8 个月迷你趋势线（口径：年度逐月金额）
function sparkPoints(vals) {
  if (!vals || vals.length < 2) return ''
  const w = 76
  const h = 26
  const pad = 2
  const max = Math.max.apply(null, vals)
  const min = Math.min.apply(null, vals)
  const span = max - min || 1
  return vals.map(function (v, i) {
    const x = (i * w) / (vals.length - 1)
    const y = h - ((v - min) / span) * (h - pad * 2) - pad
    return x.toFixed(1) + ',' + y.toFixed(1)
  }).join(' ')
}
const sparkA = computed(() => {
  if (!d.value) return ''
  const rows = (d.value.annual.rows || []).slice(-8)
  return sparkPoints(rows.map((r) => Number(r.amount) || 0))
})
const sparkB = computed(() => {
  if (!d.value) return ''
  const rows = (d.value.annual.rows || []).slice(-8)
  let c = 0
  return sparkPoints(rows.map((r) => { c += Number(r.amount) || 0; return c }))
})

// —— 各项目金额 + 环比/同比 ——
const cmpMode = ref('mom')
const cmpRows = computed(() => {
  if (!d.value) return []
  return cmpMode.value === 'mom' ? d.value.compare.rows || [] : d.value.yoy.rows || []
})
const projRows = computed(() => {
  if (!d.value) return []
  const projs = (d.value.monthly.projects || []).slice()
  const cmp = cmpRows.value
  const max = Math.max.apply(null, [1].concat(projs.map((p) => Number(p.amount) || 0)))
  return projs.sort((a, b) => (Number(b.amount) || 0) - (Number(a.amount) || 0)).map((p) => {
    const amount = Number(p.amount) || 0
    const c = cmp.find((x) => x.name === p.name) || {}
    const cur = Number(c.current === undefined || c.current === null ? amount : c.current)
    const base = Number(c.prev) || 0
    const rate = base > 0 ? ((cur - base) / base) * 100 : null
    return {
      name: p.name,
      amount: amount,
      pct: (amount / max) * 100,
      rate: rate,
      dir: rate === null ? 'flat' : rate > 0 ? 'up' : rate < 0 ? 'down' : 'flat',
    }
  })
})

// —— 年度各项目（横条） ——
const annualProjRows = computed(() => {
  const rows = d.value && d.value.annual_projects ? d.value.annual_projects.rows || [] : []
  const max = Math.max.apply(null, [1].concat(rows.map((r) => Number(r.amount) || 0)))
  return rows.slice().sort((a, b) => (Number(b.amount) || 0) - (Number(a.amount) || 0)).map((r) => {
    const amount = Number(r.amount) || 0
    return { name: r.name, amount: amount, pct: (amount / max) * 100 }
  })
})

// 清单外采购占比（后端只给金额，前端算占比）
const customRate = computed(() => {
  const c = d.value && d.value.custom_ratio ? d.value.custom_ratio : {}
  const total = Number(c.total_amount) || 0
  return total > 0 ? ((Number(c.custom_amount) || 0) / total) * 100 : 0
})

// —— 预算执行 ——
const budRows = computed(() => {
  const rows = d.value && d.value.budget_exec ? d.value.budget_exec.rows || [] : []
  return rows.map((r) => {
    const budget = r.budget === null || r.budget === undefined ? null : Number(r.budget)
    const actual = Number(r.actual) || 0
    const pct = budget > 0 ? (actual / budget) * 100 : null
    let cls = ''
    if (pct === null) cls = 'none'
    else if (pct > 100) cls = 'over'
    else if (pct < 75) cls = 'warn'
    return { name: r.project_name, budget: budget, actual: actual, pct: pct, cls: cls }
  })
})

// —— 填报进度 ——
const fillRows = computed(() => {
  const rows = d.value && d.value.fill_progress ? d.value.fill_progress.rows || [] : []
  return rows.map((r) => {
    let cls = 'run'
    if (Number(r.returned) > 0) cls = 'no'
    else if (Number(r.total) > 0 && Number(r.confirmed) >= Number(r.total)) cls = 'ok'
    else if (Number(r.total) > 0 && Number(r.submitted) >= Number(r.total)) cls = 'wait'
    return Object.assign({}, r, { cls: cls })
  })
})

// —— 价格异常表 ——
const pa = computed(() => (d.value ? d.value.price_anomalies : null))
function rateCell(r) {
  const v = (r.rate > 0 ? '+' : '') + Number(r.rate).toFixed(1) + '%'
  return { text: v, up: r.trend === 'up' }
}

// —— 商品价格趋势（全量加载 + 本地过滤，规避 EP 2.14.6 remote 选中空白 bug）——
const itemName = ref('')
const allProducts = ref([])
const productOpts = ref([])
const productsLoaded = ref(false)
const trend = ref(null)

async function loadAllProducts() {
  if (productsLoaded.value) return
  try {
    const out = []
    const seen = {}
    for (let p = 1; p <= 8; p++) {
      const res = await api('/api/purchase/products?page=' + p + '&page_size=200')
      const rows = res.data || []
      rows.forEach((x) => {
        const n = x.name
        if (n && !seen[n]) { seen[n] = 1; out.push({ value: n, label: n }) }
      })
      if (rows.length < 200) break
    }
    allProducts.value = out
    productOpts.value = out
    productsLoaded.value = true
  } catch (e) {
    productsLoaded.value = false
  }
}

function productFilter(kw) {
  const k = (kw || '').trim().toLowerCase()
  if (!k) { productOpts.value = allProducts.value; return }
  productOpts.value = allProducts.value.filter((p) => p.label.toLowerCase().indexOf(k) >= 0)
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
  return keys.map((y) => y + ' 年均价 ' + moneyOrDash(trend.value.year_avg[y]) + ' 元').join('，')
})

async function load() {
  loading.value = true
  try {
    const res = await api('/api/purchase/dashboard/all' + qs({ month: month.value }))
    d.value = res.data
    trend.value = null
    const t = new Date()
    updatedAt.value = t.getFullYear() + '-' + String(t.getMonth() + 1).padStart(2, '0') + '-' + String(t.getDate()).padStart(2, '0') + ' ' + String(t.getHours()).padStart(2, '0') + ':' + String(t.getMinutes()).padStart(2, '0')
  } catch (e) {
    toast(e.message, false)
  } finally {
    loading.value = false
  }
}

const prevMonthText = computed(() => (d.value ? prevMonth(d.value.month) : ''))

function wan(v) {
  const n = Number(v) || 0
  return (n / 10000).toFixed(1)
}

onMounted(() => {
  load()
  loadAllProducts()
})
</script>

<template>
  <div class="dsh-page" :data-theme="dark ? 'dark' : null">
    <div class="dsh-head">
      <div>
        <h2>采购数据驾驶舱</h2>
        <p>
          按录入日期归集
          <template v-if="d"> · 核算月份 {{ d.month }} · 数据更新于 {{ updatedAt }}</template>
          <template v-else> · 加载中…</template>
        </p>
      </div>
      <div class="dsh-tools">
        <span v-if="isStaff" style="font-size:11.5px;color:var(--text-3);">仅显示 {{ staffProject }}</span>
        <el-date-picker v-model="month" type="month" value-format="YYYY-MM" :clearable="false"
          style="width:148px" @change="load" />
        <el-button type="primary" :loading="loading" @click="load">刷新数据</el-button>
        <div class="dsh-seg">
          <button :class="{ on: !dark }" @click="setDark(false)">浅色</button>
          <button :class="{ on: dark }" @click="setDark(true)">深色</button>
        </div>
      </div>
    </div>

    <template v-if="d">
      <div class="dsh-kpis">
        <div class="dsh-kpi">
          <div class="dsh-kpi-t">
            <i class="dsh-kpi-ico"><svg viewBox="0 0 24 24"><path d="M4 8h16M4 8v10a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8" /><path d="M8 8V6a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2" /></svg></i>
            本月采购金额
          </div>
          <div class="dsh-kpi-v">{{ moneyOrDash(d.monthly.overview.amount) }}<small>元</small></div>
          <div class="dsh-kpi-f">
            <span class="dsh-delta" :class="deltaCls(kpiDelta.up)">{{ kpiDelta.text }}</span>
          </div>
          <svg class="dsh-spark" viewBox="0 0 76 26" preserveAspectRatio="none">
            <polyline :points="sparkA" fill="none" :stroke="dark ? '#FF8A8A' : '#FF8A8A'" stroke-width="1.6" />
          </svg>
        </div>

        <div class="dsh-kpi">
          <div class="dsh-kpi-t">
            <i class="dsh-kpi-ico"><svg viewBox="0 0 24 24"><path d="M4 20V10M10 20V4M16 20v-7M22 20H2" /></svg></i>
            年度累计采购金额
          </div>
          <div class="dsh-kpi-v">{{ moneyOrDash(d.ytd.ytd) }}<small>元</small></div>
          <div class="dsh-kpi-f">
            <span class="dsh-delta" :class="deltaCls(ytdSub.up)">{{ ytdSub.text }}</span>
          </div>
          <svg class="dsh-spark" viewBox="0 0 76 26" preserveAspectRatio="none">
            <polyline :points="sparkB" fill="none" :stroke="dark ? '#7FA8FF' : '#165DFF'" stroke-width="1.6" />
          </svg>
        </div>

        <div class="dsh-kpi">
          <div class="dsh-kpi-t">
            <i class="dsh-kpi-ico"><svg viewBox="0 0 24 24"><path d="M3 7h18v13H3z" /><path d="M8 7V4h8v3" /></svg></i>
            本月采购项目
          </div>
          <div class="dsh-kpi-v">{{ d.monthly.overview.projects }}<small>个</small></div>
          <div class="dsh-kpi-f">
            <span class="dsh-delta flat">共 {{ d.monthly.overview.projects }} 个项目</span>
          </div>
        </div>

        <div class="dsh-kpi">
          <div class="dsh-kpi-t">
            <i class="dsh-kpi-ico"><svg viewBox="0 0 24 24"><path d="M6 3h9l4 4v14H6z" /><path d="M9 12h6M9 16h4" /></svg></i>
            本月采购条目
          </div>
          <div class="dsh-kpi-v">{{ d.monthly.overview.items }}<small>条</small></div>
          <div class="dsh-kpi-f">
            <span class="dsh-delta flat">清单外占比 {{ customRate.toFixed(1) }}%</span>
          </div>
        </div>
      </div>

      <div class="dsh-sec">月度分析<span>{{ d.month }} 核算月</span></div>
      <div class="dsh-grid dsh-grid-2">
        <div class="dsh-card">
          <div class="dsh-card-h">
            各项目采购金额
            <span class="dsh-note">单位：万元</span>
            <div class="dsh-seg" style="margin-left:auto">
              <button :class="{ on: cmpMode === 'mom' }" @click="cmpMode = 'mom'">环比</button>
              <button :class="{ on: cmpMode === 'yoy' }" @click="cmpMode = 'yoy'">同比</button>
            </div>
          </div>
          <div class="dsh-card-b">
            <div v-if="projRows.length" class="dsh-hbars">
              <div v-for="r in projRows" :key="r.name" class="dsh-hb">
                <span class="dsh-hb-n">{{ r.name }}</span>
                <span class="dsh-hb-track"><span class="dsh-hb-fill" :style="{ width: Math.max(2, r.pct) + '%' }"></span></span>
                <span class="dsh-hb-v">
                  {{ wan(r.amount) }}
                  <span v-if="r.rate !== null" class="dsh-delta" :class="r.dir">{{ r.rate > 0 ? '+' : '' }}{{ r.rate.toFixed(1) }}%</span>
                </span>
              </div>
            </div>
            <div v-else class="dsh-empty">本月暂无采购项目</div>
          </div>
        </div>

        <div class="dsh-card">
          <div class="dsh-card-h">
            各条线采购金额占比
            <span class="dsh-note">清单外 {{ moneyOrDash(d.custom_ratio.custom_amount) }} 元</span>
          </div>
          <div class="dsh-card-b">
            <ChartBox :option="linesPieOpt(d.monthly.lines, dark)" :height="286" />
          </div>
        </div>
      </div>

      <div class="dsh-sec">预算与流程<span>口径：本月实际 / 年度预算</span></div>
      <div class="dsh-grid dsh-grid-2">
        <div class="dsh-card">
          <div class="dsh-card-h">预算执行<span class="dsh-note">超 100% 标红 · 低于 75% 标橙</span></div>
          <div class="dsh-card-b">
            <div v-if="budRows.length" class="dsh-buds">
              <div v-for="r in budRows" :key="r.name">
                <div class="dsh-bu-l">
                  <b>{{ r.name }}</b>
                  <span v-if="r.budget !== null">{{ wan(r.actual) }} / {{ wan(r.budget) }} 万</span>
                  <span v-else>未设预算 · 实际 {{ wan(r.actual) }} 万</span>
                </div>
                <div class="dsh-bu-row">
                  <div class="dsh-bu-track">
                    <span class="dsh-bu-fill" :class="r.cls" :style="{ width: Math.min(100, r.pct === null ? 0 : r.pct) + '%' }"></span>
                  </div>
                  <div class="dsh-bu-pc" :class="r.cls">{{ r.pct === null ? '—' : r.pct.toFixed(0) + '%' }}</div>
                </div>
              </div>
            </div>
            <div v-else class="dsh-empty">暂无预算数据</div>
          </div>
        </div>

        <div class="dsh-card">
          <div class="dsh-card-h">
            填报进度
            <span class="dsh-note">
              项目 {{ d.fill_progress.filled_projects }}/{{ d.fill_progress.total_projects }} ·
              条目 {{ d.fill_progress.items_submitted }}/{{ d.fill_progress.items_total }} 已提交
            </span>
          </div>
          <table class="dsh-tbl">
            <thead>
              <tr>
                <th>项目</th><th>总条目</th><th>已提交</th><th>已确认</th><th>已退回</th><th>状态</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="!fillRows.length"><td colspan="6" class="dsh-empty" style="padding:24px 0;">本月暂无填报记录</td></tr>
              <tr v-for="r in fillRows" :key="r.project_id">
                <td>{{ r.project_name }}</td>
                <td class="n">{{ r.total }}</td>
                <td class="n">{{ r.submitted }}</td>
                <td class="n">{{ r.confirmed }}</td>
                <td class="n">{{ r.returned }}</td>
                <td><span class="dsh-pill" :class="r.cls"><s></s>{{ r.status }}</span></td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <div class="dsh-sec">年度分析<span>{{ d.year }} 年</span></div>
      <div class="dsh-grid dsh-grid-2">
        <div class="dsh-card">
          <div class="dsh-card-h">年度采购金额逐月趋势<span class="dsh-note">柱：月度 · 面积：累计</span></div>
          <div class="dsh-card-b">
            <ChartBox :option="annualTrendOpt(d.annual.rows, dark)" :height="300" />
          </div>
        </div>
        <div class="dsh-card">
          <div class="dsh-card-h">
            高频采购商品 TOP10
            <span class="dsh-note">按采购次数<span v-if="topWindow(d.top)"> · {{ topWindow(d.top) }}</span></span>
          </div>
          <div class="dsh-card-b">
            <ChartBox v-if="d.top.rows && d.top.rows.length"
              :option="hbarOpt(d.top.rows, 'item_name', 'times', dark ? '#7FA8FF' : '#165DFF', '次', dark, 10)"
              :height="300" />
            <div v-else class="dsh-empty">暂无采购记录</div>
          </div>
        </div>
      </div>

      <div class="dsh-grid" style="grid-template-columns:minmax(0,1fr);">
        <div class="dsh-card">
          <div class="dsh-card-h">年度各项目采购金额<span class="dsh-note">单位：万元</span></div>
          <div class="dsh-card-b">
            <div v-if="annualProjRows.length" class="dsh-hbars">
              <div v-for="r in annualProjRows" :key="r.name" class="dsh-hb">
                <span class="dsh-hb-n">{{ r.name }}</span>
                <span class="dsh-hb-track">
                  <span class="dsh-hb-fill" :style="{ width: Math.max(2, r.pct) + '%' }"></span>
                </span>
                <span class="dsh-hb-v">{{ wan(r.amount) }}</span>
              </div>
            </div>
            <div v-else class="dsh-empty">暂无年度数据</div>
          </div>
        </div>
      </div>

      <div class="dsh-sec">价格监控<span>阈值 {{ pa.threshold }}%</span></div>
      <div class="dsh-grid dsh-grid-2">
        <div class="dsh-card">
          <div class="dsh-card-h">
            价格异常（环比）
            <span class="dsh-note">{{ pa.total > 0 ? '发现 ' + pa.total + ' 种商品波动超阈值' : '本月无异常' }}</span>
          </div>
          <table class="dsh-tbl">
            <thead>
              <tr><th>商品名称</th><th>本月均价</th><th>上月均价</th><th>环比</th><th>采购</th></tr>
            </thead>
            <tbody>
              <tr v-if="!pa.rows.length"><td colspan="5" class="dsh-empty" style="padding:24px 0;">本月无价格异常商品</td></tr>
              <tr v-for="r in pa.rows" :key="r.item_name">
                <td>{{ r.item_name }}</td>
                <td class="n">{{ moneyOrDash(r.cur_price) }} 元</td>
                <td class="n">{{ moneyOrDash(r.prev_price) }} 元</td>
                <td class="n">
                  <span class="dsh-delta" :class="rateCell(r).up ? 'up' : 'down'">{{ rateCell(r).text }}</span>
                </td>
                <td class="n">{{ r.cnt }} 次 / {{ r.qty }} {{ r.units }}</td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="dsh-card">
          <div class="dsh-card-h">
            商品价格趋势
            <span class="dsh-note">单价走势（元）</span>
          </div>
          <div class="dsh-card-b">
            <el-select v-model="itemName" filterable :filter-method="productFilter"
              placeholder="输入商品名称搜索（如 A4纸）" style="width:100%;margin-bottom:10px;" @change="loadTrend">
              <el-option v-for="o in productOpts" :key="o.value" :value="o.value" :label="o.label" />
            </el-select>
            <div v-if="yearAvgText" style="font-size:11.5px;color:var(--text-3);margin-bottom:8px;">年度均价：{{ yearAvgText }}</div>
            <ChartBox v-if="trend" :option="priceTrendOpt(trend, dark)" :height="240" />
            <div v-else class="dsh-empty" style="padding:52px 0;">搜索并选择商品后，展示其各月平均采购单价走势</div>
          </div>
        </div>
      </div>

      <div class="dsh-foot">
        <span>数据来源：采购填报 + 商品库 · 口径按录入日期归集</span>
        <span v-if="d.price_anomalies">价格异常阈值 {{ pa.threshold }}%</span>
      </div>
    </template>
  </div>
</template>
