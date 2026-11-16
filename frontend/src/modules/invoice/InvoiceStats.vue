<template>
  <div class="inv-stats">
    <div class="page-head">
      <div>
        <h2>发票查询统计</h2>
        <p class="muted">按可访问账套切分；项目账号只能看到本项目账套。</p>
      </div>
      <div class="head-actions">
        <select v-model="filters.account_set_id" class="mini-select" @change="load">
          <option value="">全部可见账套</option>
          <option v-for="s in options" :key="s.id" :value="s.id">{{ s.name }}</option>
        </select>
        <input v-model="filters.from" type="date" class="mini-select">
        <span class="muted">至</span>
        <input v-model="filters.to" type="date" class="mini-select">
        <button class="btn" @click="load">查询</button>
        <button class="btn" @click="exportExcel">导出明细</button>
      </div>
    </div>

    <div v-if="error" class="err">{{ error }}</div>

    <div class="kpis">
      <div class="kpi"><span>开票申请</span><b>{{ summary.total }}</b><small>全部状态</small></div>
      <div class="kpi"><span>已开票金额</span><b>¥{{ money(summary.amount) }}</b><small>按核定金额累计</small></div>
      <div class="kpi warn"><span>待审核</span><b>{{ summary.pending }}</b><small>需要人工核额</small></div>
      <div class="kpi danger"><span>失败</span><b>{{ summary.failed }}</b><small>含交付失败</small></div>
      <div class="kpi"><span>红冲</span><b>{{ summary.red }}</b><small>含红冲中</small></div>
    </div>

    <div class="cols">
      <div class="card">
        <h3>状态分布</h3>
        <div v-for="s in statusRows" :key="s.status" class="bar-row">
          <span class="bar-label">{{ statusLabel(s.status) }}</span>
          <div class="bar"><i :style="{ width: pct(s.count) }"></i></div>
          <span class="bar-val">{{ s.count }}</span>
        </div>
        <div v-if="!statusRows.length" class="empty">暂无数据</div>
      </div>

      <div class="card">
        <h3>按账套</h3>
        <table class="mini-table">
          <thead><tr><th>账套</th><th>主体</th><th>单量</th><th>金额</th></tr></thead>
          <tbody>
            <tr v-for="r in byAccount" :key="r.account_set_id">
              <td>{{ r.account_set_code }}</td><td class="muted">{{ r.seller_name }}</td>
              <td>{{ r.count }}</td><td>¥{{ money(r.amount) }}</td>
            </tr>
          </tbody>
        </table>
        <div v-if="!byAccount.length" class="empty">暂无数据</div>
      </div>
    </div>

    <div class="card">
      <h3>按项目</h3>
      <table class="mini-table">
        <thead><tr><th>项目</th><th>单量</th><th>金额</th></tr></thead>
        <tbody>
          <tr v-for="r in byProject" :key="r.project_id">
            <td>{{ r.project_name }}</td><td>{{ r.count }}</td><td>¥{{ money(r.amount) }}</td>
          </tr>
        </tbody>
      </table>
      <div v-if="!byProject.length" class="empty">暂无数据</div>
    </div>

    <div class="card">
      <h3>月度趋势</h3>
      <div v-for="m in monthly" :key="m.month" class="bar-row">
        <span class="bar-label">{{ m.month }}</span>
        <div class="bar"><i class="trend" :style="{ width: monthPct(m.amount) }"></i></div>
        <span class="bar-val">{{ m.count }} 单 · ¥{{ money(m.amount) }}</span>
      </div>
      <div v-if="!monthly.length" class="empty">暂无数据</div>
    </div>

    <p class="muted" style="margin-top:12px">明细可在「开票审核」按状态筛选，或点击右上角导出 Excel。</p>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { api, download } from '@/api/client'

const statusMap = { submitted: '待审核', approved: '审核通过', rejected: '已退回', issuing: '开票中', failed: '开票失败', issued: '已开票', delivering: '交付中', delivered: '已交付', delivered_failed: '交付失败', red_flushing: '红冲中', red_flushed: '已红冲', red_flush_failed: '红冲失败', cancelled: '已撤回' }
const filters = reactive({ account_set_id: '', from: '', to: '' })
const options = ref([])
const summary = ref({ total: 0, amount: 0, pending: 0, failed: 0, red: 0 })
const statusRows = ref([])
const byAccount = ref([])
const byProject = ref([])
const monthly = ref([])
const error = ref('')

function money(n) { return Number(n || 0).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }
function statusLabel(s) { return statusMap[s] || s }
const maxStatus = computed(() => Math.max(1, ...statusRows.value.map((s) => s.count)))
const maxMonth = computed(() => Math.max(1, ...monthly.value.map((m) => m.amount)))
function pct(n) { return Math.max(2, Math.round((n / maxStatus.value) * 100)) + '%' }
function monthPct(n) { return Math.max(2, Math.round((n / maxMonth.value) * 100)) + '%' }

async function load() {
  error.value = ''
  try {
    const q = new URLSearchParams()
    if (filters.account_set_id) q.set('account_set_id', filters.account_set_id)
    if (filters.from) q.set('from', filters.from)
    if (filters.to) q.set('to', filters.to)
    const data = await api('/api/invoice/admin/stats?' + q.toString())
    summary.value = data.summary
    statusRows.value = data.status
    byAccount.value = data.by_account_set
    byProject.value = data.by_project
    monthly.value = data.monthly
  } catch (e) { error.value = e.message }
}
function exportExcel() {
  const q = new URLSearchParams()
  if (filters.account_set_id) q.set('account_set_id', filters.account_set_id)
  if (filters.from) q.set('from', filters.from)
  if (filters.to) q.set('to', filters.to)
  download('/api/invoice/admin/export?' + q.toString(), 'invoice-requests.xlsx')
}
onMounted(async () => {
  try { const d = await api('/api/invoice/admin/account-options'); options.value = d.items || [] } catch (e) {}
  await load()
})
</script>

<style scoped>
.inv-stats{padding:18px 22px 40px}
.page-head{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;margin-bottom:14px;flex-wrap:wrap}
.page-head h2{margin:0;font-size:19px}
.muted{color:#94a3b8;font-size:12px}
.head-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.mini-select{height:32px;border:1px solid #dbe0e8;border-radius:8px;padding:0 8px;background:#fff;font-size:12px}
.btn{height:32px;padding:0 12px;border-radius:8px;border:1px solid #dbe0e8;background:#fff;font-size:12px;cursor:pointer}
.err{background:#fef2f2;border:1px solid #fecaca;color:#b91c1c;padding:9px 12px;border-radius:8px;font-size:12px;margin-bottom:10px}
.kpis{display:grid;grid-template-columns:repeat(5,1fr);gap:10px;margin-bottom:12px}
.kpi{background:#fff;border:1px solid #edf0f5;border-radius:12px;padding:13px}
.kpi span{font-size:12px;color:#64748b;display:block}
.kpi b{font-size:22px;color:#0f172a;display:block;margin-top:5px}
.kpi small{font-size:11px;color:#94a3b8}
.kpi.warn b{color:#b45309}.kpi.danger b{color:#b91c1c}
.cols{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.card{background:#fff;border:1px solid #edf0f5;border-radius:12px;padding:14px;margin-bottom:12px}
.card h3{margin:0 0 12px;font-size:14px}
.bar-row{display:grid;grid-template-columns:110px 1fr 120px;gap:10px;align-items:center;font-size:11px;margin-bottom:9px}
.bar-label{color:#475569;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.bar{height:8px;background:#f1f5f9;border-radius:999px;overflow:hidden}
.bar i{display:block;height:100%;background:#3b82f6;border-radius:999px}
.bar i.trend{background:#0d9488}
.bar-val{text-align:right;color:#64748b;white-space:nowrap}
.mini-table{width:100%;border-collapse:collapse;font-size:12px}
.mini-table th{text-align:left;color:#94a3b8;font-weight:500;padding:7px 8px;border-bottom:1px solid #f1f4f8}
.mini-table td{padding:8px;border-bottom:1px solid #f5f7fa}
.empty{text-align:center;color:#94a3b8;font-size:12px;padding:22px 0}
@media(max-width:1100px){.kpis{grid-template-columns:repeat(2,1fr)}.cols{grid-template-columns:1fr}}
</style>
