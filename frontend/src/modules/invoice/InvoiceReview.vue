<template>
  <div class="inv-page">
    <div class="page-head">
      <div>
        <h2>开票审核</h2>
        <p class="muted">按账套隔离待办；主审人仅决定归属，不替代权限点。</p>
      </div>
      <div class="head-actions">
        <select v-model="filters.account_set_id" @change="load" class="mini-select">
          <option value="">全部可见账套</option>
          <option v-for="s in options" :key="s.id" :value="s.id">{{ s.name }} · {{ s.code }}</option>
        </select>
        <button class="btn" @click="load">刷新</button>
      </div>
    </div>

    <div class="tabs">
      <span v-for="t in tabs" :key="t.value" :class="{ on: filters.status === t.value }" @click="filters.status = t.value; load()">
        {{ t.label }}<b v-if="t.count != null">{{ t.count }}</b>
      </span>
    </div>

    <div class="filters-row">
      <input v-model="filters.keyword" placeholder="单号 / 房号 / 业主 / 抬头" @keyup.enter="load">
      <input v-model="filters.from" type="date">
      <span class="muted">至</span>
      <input v-model="filters.to" type="date">
      <button class="btn" @click="load">筛选</button>
      <label class="check"><input type="checkbox" v-model="grouped"> 按账套分组</label>
    </div>

    <div v-if="error" class="err">{{ error }}</div>
    <div v-if="loading" class="muted" style="padding:18px">加载中…</div>

    <template v-else>
      <div v-for="g in groups" :key="g.id" class="group-card">
        <div class="group-head">
          <span class="dot"></span>
          <b>{{ g.name }}</b>
          <span class="muted">{{ g.code }} · {{ g.seller_name }}</span>
          <span class="spacer"></span>
          <span v-if="g.primary" class="primary">主审：{{ g.primary }}</span>
          <span class="count">{{ g.items.length }} 条</span>
        </div>
        <table class="inv-table">
          <thead>
            <tr><th>申请单号</th><th>房号 / 业主</th><th>申报金额</th><th>核定金额</th><th>抬头</th><th>状态</th><th>提交时间</th><th>标记</th><th></th></tr>
          </thead>
          <tbody>
            <tr v-for="r in g.items" :key="r.id" @click="openDetail(r)">
              <td class="mono">{{ r.request_no }}</td>
              <td>{{ r.house_no }}<div class="muted">{{ r.payer_name }}</div></td>
              <td>¥{{ money(r.declared_amount) }}</td>
              <td>{{ r.verified_amount == null ? '—' : '¥' + money(r.verified_amount) }}</td>
              <td>{{ r.title }}</td>
              <td><span class="tag" :class="'st-' + r.status">{{ statusLabel(r.status) }}</span><span v-if="r.simulated" class="sim">模拟</span></td>
              <td class="muted">{{ r.created_at }}</td>
              <td>
                <span v-for="f in flags(r.amount_flag)" :key="f" class="flag">{{ f }}</span>
                <span v-if="r.fail_msg" class="flag danger" :title="r.fail_msg">失败</span>
              </td>
              <td><button class="btn tiny" @click.stop="openDetail(r)">处理</button></td>
            </tr>
          </tbody>
        </table>
      </div>
      <div v-if="!groups.length" class="empty">暂无符合条件的申请单</div>
    </template>

    <div v-if="detail" class="drawer-mask" @mousedown.self="detail = null">
      <aside class="drawer">
        <div class="drawer-head">
          <div>
            <b>{{ detail.request.request_no }}</b>
            <span class="tag" :class="'st-' + detail.request.status">{{ statusLabel(detail.request.status) }}</span>
          </div>
          <button class="btn tiny" @click="detail = null">关闭</button>
        </div>
        <div class="drawer-body">
          <div class="kv-grid">
            <div><span>项目</span><b>{{ detail.request.project_name }}</b></div>
            <div><span>账套 / 主体</span><b>{{ detail.request.account_set_code }} · {{ detail.request.seller_name }}</b></div>
            <div><span>房号</span><b>{{ detail.request.house_no }}</b></div>
            <div><span>业主</span><b>{{ detail.request.payer_name }}</b></div>
            <div><span>费项期间</span><b>{{ detail.request.fee_period || '—' }}</b></div>
            <div><span>申报金额</span><b>¥{{ money(detail.request.declared_amount) }}</b></div>
            <div><span>抬头</span><b>{{ detail.request.title }}（{{ detail.request.title_type === 'company' ? '企业' : '个人' }}）</b></div>
            <div><span>税号</span><b>{{ detail.request.buyer_tax_no || '—' }}</b></div>
            <div><span>邮箱</span><b>{{ detail.request.buyer_email }}</b></div>
            <div><span>税率</span><b>{{ (detail.seller?.default_tax_rate * 100).toFixed(2) }}%</b></div>
          </div>

          <h4>缴费凭证（{{ detail.attachments.length }}）</h4>
          <div class="attachments">
            <a v-for="a in detail.attachments" :key="a.id" :href="a.preview_url" target="_blank" class="att">
              <img v-if="a.mime.startsWith('image/')" :src="a.preview_url" :alt="a.file_name">
              <div v-else class="pdf">PDF</div>
              <span>{{ a.file_name }}</span>
            </a>
          </div>

          <template v-if="detail.request.status === 'submitted'">
            <h4>人工核额</h4>
            <div class="form-row">
              <label>核定金额
                <input v-model.number="review.verified_amount" type="number" min="0.01" step="0.01">
              </label>
              <label class="grow">差异 / 审核说明
                <input v-model="review.review_note" placeholder="改额时必填差异原因">
              </label>
            </div>
            <div class="muted" style="margin:6px 0 10px">申报 ¥{{ money(detail.request.declared_amount) }}，核定后按核定金额开票；通过后将立即调用开票适配器。</div>
            <div class="drawer-actions">
              <button class="btn primary" :disabled="busy" @click="approve">通过并开票</button>
              <button class="btn" :disabled="busy" @click="reject">退回</button>
            </div>
          </template>

          <template v-else>
            <h4>状态与发票</h4>
            <div class="kv-grid">
              <div><span>核定金额</span><b>{{ detail.request.verified_amount == null ? '—' : '¥' + money(detail.request.verified_amount) }}</b></div>
              <div><span>开票实现</span><b>{{ detail.request.issuer || '—' }}<span v-if="detail.request.simulated" class="sim">模拟</span></b></div>
              <div><span>发票号码</span><b class="mono">{{ detail.request.digital_invoice_no || '—' }}</b></div>
              <div><span>开票日期</span><b>{{ detail.request.invoice_date || '—' }}</b></div>
              <div><span>失败原因</span><b>{{ detail.request.fail_msg || '—' }}</b></div>
            </div>
            <div class="drawer-actions">
              <button v-if="detail.request.has_pdf" class="btn" @click="downloadInvoice">下载发票 PDF</button>
              <button v-if="detail.request.has_ofd" class="btn" @click="downloadOfd">下载 OFD</button>
              <button v-if="canIssue && ['failed','approved'].includes(detail.request.status)" class="btn" :disabled="busy" @click="issue">重试开票</button>
              <button v-if="canIssue && ['issued','delivered','delivered_failed'].includes(detail.request.status)" class="btn" :disabled="busy" @click="resend">重发邮件</button>
              <button v-if="canIssue && ['issued','delivered'].includes(detail.request.status)" class="btn danger" :disabled="busy" @click="redFlush">红冲</button>
              <button v-if="canIssue && detail.request.status === 'red_flushed'" class="btn" :disabled="busy" @click="reissue">重开</button>
            </div>
          </template>

          <h4>时间线</h4>
          <div class="logs">
            <div v-for="(l, i) in detail.logs" :key="i" class="log">
              <span class="muted">{{ l.at }}</span>
              <b>{{ actionLabel(l.action) }}</b>
              <span v-if="l.from || l.to" class="muted">{{ l.from || '—' }} → {{ l.to || '—' }}</span>
              <span class="muted">{{ l.operator }}</span>
            </div>
          </div>
        </div>
      </aside>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { api, download } from '@/api/client'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const canIssue = computed(() => auth.can('invoice_issue'))

const statusMap = {
  submitted: '待审核', approved: '审核通过', rejected: '已退回', issuing: '开票中', failed: '开票失败',
  issued: '已开票', delivering: '交付中', delivered: '已交付', delivered_failed: '交付失败',
  red_flushing: '红冲中', red_flushed: '已红冲', red_flush_failed: '红冲失败', cancelled: '已撤回',
}
const tabs = [
  { label: '待审核', value: 'submitted' },
  { label: '开票中', value: 'issuing' },
  { label: '已开票', value: 'issued' },
  { label: '已退回', value: 'rejected' },
  { label: '失败', value: 'failed' },
  { label: '全部', value: 'all' },
]
const filters = reactive({ account_set_id: '', status: 'submitted', keyword: '', from: '', to: '' })
const options = ref([])
const items = ref([])
const loading = ref(false)
const error = ref('')
const grouped = ref(true)
const detail = ref(null)
const review = reactive({ verified_amount: null, review_note: '' })
const busy = ref(false)

const groups = computed(() => {
  if (!grouped.value) {
    return [{ id: 'all', name: '全部申请单', code: '', seller_name: '', primary: '', items: items.value }]
  }
  const map = new Map()
  for (const r of items.value) {
    if (!map.has(r.account_set_id)) {
      const opt = options.value.find((o) => o.id === r.account_set_id)
      map.set(r.account_set_id, {
        id: r.account_set_id,
        name: opt?.name || r.account_set_code,
        code: r.account_set_code,
        seller_name: r.seller_name,
        primary: opt?.primary_reviewer || '',
        items: [],
      })
    }
    map.get(r.account_set_id).items.push(r)
  }
  return [...map.values()]
})

function money(n) { return Number(n || 0).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }
function statusLabel(s) { return statusMap[s] || s }
function flags(v) { return (v || '').split(',').filter(Boolean).map((x) => ({ large: '大额', duplicate: '重复提交', over_avg: '金额异常' }[x] || x)) }
function actionLabel(a) {
  return ({ status_transition: '状态变更', owner_submit: '业主提交', owner_resubmit: '业主重提', credentials_update: '凭据变更', reviewers_update: '审核人变更', project_binding_save: '项目绑定', delivery_alert: '交付告警' }[a] || a)
}

async function loadOptions() {
  const data = await api('/api/invoice/admin/account-options')
  options.value = data.items || []
}
async function load() {
  loading.value = true; error.value = ''
  try {
    const q = new URLSearchParams()
    if (filters.status !== 'all') q.set('status', filters.status)
    if (filters.account_set_id) q.set('account_set_id', filters.account_set_id)
    if (filters.keyword) q.set('keyword', filters.keyword)
    if (filters.from) q.set('from', filters.from)
    if (filters.to) q.set('to', filters.to)
    q.set('per_page', '100')
    const data = await api('/api/invoice/admin/requests?' + q.toString())
    items.value = data.items || []
  } catch (e) { error.value = e.message } finally { loading.value = false }
}
async function openDetail(r) {
  try {
    const data = await api('/api/invoice/admin/requests/' + r.id)
    detail.value = data
    review.verified_amount = data.request.verified_amount ?? data.request.declared_amount
    review.review_note = data.request.review_note || ''
  } catch (e) { error.value = e.message }
}
async function approve() {
  if (!review.verified_amount || review.verified_amount <= 0) { error.value = '请填写核定金额'; return }
  const declared = Number(detail.value.request.declared_amount || 0)
  if (Math.abs(Number(review.verified_amount) - declared) > 0.009 && !String(review.review_note || '').trim()) {
    error.value = '改额必须填写差异原因'; return
  }
  busy.value = true
  try {
    await api('/api/invoice/admin/requests/' + detail.value.request.id + '/approve', { body: { verified_amount: review.verified_amount, review_note: review.review_note } })
    await openDetail(detail.value.request)
    await load()
  } catch (e) { error.value = e.message } finally { busy.value = false }
}
async function reject() {
  const reason = prompt('请填写退回原因')
  if (!reason) return
  busy.value = true
  try {
    await api('/api/invoice/admin/requests/' + detail.value.request.id + '/reject', { body: { reason } })
    await openDetail(detail.value.request)
    await load()
  } catch (e) { error.value = e.message } finally { busy.value = false }
}
async function issue() {
  busy.value = true
  try { await api('/api/invoice/admin/requests/' + detail.value.request.id + '/issue', { method: 'POST', body: {} }); await openDetail(detail.value.request); await load() }
  catch (e) { error.value = e.message } finally { busy.value = false }
}
async function resend() {
  busy.value = true
  try { await api('/api/invoice/admin/requests/' + detail.value.request.id + '/resend', { method: 'POST', body: {} }); await openDetail(detail.value.request) }
  catch (e) { error.value = e.message } finally { busy.value = false }
}
async function redFlush() {
  const reason = prompt('请填写红冲原因'); if (!reason) return
  busy.value = true
  try { await api('/api/invoice/admin/requests/' + detail.value.request.id + '/red-flush', { body: { reason } }); await openDetail(detail.value.request); await load() }
  catch (e) { error.value = e.message } finally { busy.value = false }
}
async function reissue() {
  if (!confirm('确认按原申请信息重开一张新发票？新发票会生成独立申请单号。')) return
  busy.value = true
  try {
    await api('/api/invoice/admin/requests/' + detail.value.request.id + '/reissue', { method: 'POST' })
    alert('已创建重开申请单，可在列表查看')
    await load()
  } catch (e) { error.value = e.message } finally { busy.value = false }
}
function downloadInvoice() {
  download('/api/invoice/admin/requests/' + detail.value.request.id + '/invoice', detail.value.request.request_no + '.pdf')
}
function downloadOfd() {
  download('/api/invoice/admin/requests/' + detail.value.request.id + '/invoice?f=ofd', detail.value.request.request_no + '.ofd')
}

onMounted(async () => {
  try { await loadOptions() } catch (e) { /* 配置权限缺失不影响审核 */ }
  await load()
})
</script>

<style scoped>
.inv-page{padding:18px 22px 40px}
.page-head{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;margin-bottom:12px}
.page-head h2{margin:0;font-size:19px}
.muted{color:#94a3b8;font-size:12px}
.head-actions{display:flex;gap:8px;align-items:center}
.mini-select{height:32px;border:1px solid #dbe0e8;border-radius:8px;padding:0 8px;background:#fff;font-size:12px}
.tabs{display:flex;gap:6px;border-bottom:1px solid #e5e7eb;margin-bottom:10px}
.tabs span{padding:8px 12px;font-size:13px;color:#6b7280;cursor:pointer;border-bottom:2px solid transparent}
.tabs span.on{color:#1d4ed8;border-bottom-color:#2563eb;font-weight:600}
.tabs b{margin-left:5px;font-size:11px;color:#94a3b8}
.filters-row{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:12px}
.filters-row input{height:32px;border:1px solid #dbe0e8;border-radius:8px;padding:0 10px;font-size:12px}
.filters-row input[type=date]{width:140px}
.check{font-size:12px;color:#475569;display:flex;gap:4px;align-items:center}
.btn{height:32px;padding:0 12px;border-radius:8px;border:1px solid #dbe0e8;background:#fff;font-size:12px;cursor:pointer}
.btn.primary{background:#2563eb;border-color:#2563eb;color:#fff}
.btn.tiny{height:26px;padding:0 9px;font-size:11px}
.btn.danger{color:#dc2626;border-color:#fecaca}
.btn:disabled{opacity:.55}
.err{background:#fef2f2;border:1px solid #fecaca;color:#b91c1c;padding:9px 12px;border-radius:8px;font-size:12px;margin-bottom:10px}
.group-card{background:#fff;border:1px solid #edf0f5;border-radius:12px;margin-bottom:12px;overflow:hidden}
.group-head{display:flex;align-items:center;gap:8px;padding:10px 14px;background:#fafbfd;border-bottom:1px solid #eef1f5;font-size:12px}
.dot{width:8px;height:8px;border-radius:50%;background:#16a34a}
.primary{background:#dcfce7;color:#15803d;border-radius:999px;padding:2px 8px;font-size:11px}
.count{background:#eef4ff;color:#1d4ed8;border-radius:999px;padding:2px 8px;font-size:11px}
.spacer{flex:1}
.inv-table{width:100%;border-collapse:collapse;font-size:12px}
.inv-table th{text-align:left;color:#94a3b8;font-weight:500;background:#fff;padding:9px 12px;border-bottom:1px solid #f1f4f8;white-space:nowrap}
.inv-table td{padding:10px 12px;border-bottom:1px solid #f5f7fa;vertical-align:top}
.inv-table tbody tr{cursor:pointer}
.inv-table tbody tr:hover{background:#f8fbff}
.mono{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:11px}
.tag{display:inline-block;border-radius:999px;padding:2px 8px;font-size:11px;background:#eef2f7;color:#475569;white-space:nowrap}
.st-submitted{background:#fff7e6;color:#b45309}
.st-approved,.st-issued,.st-delivered{background:#e8f7ee;color:#15803d}
.st-failed,.st-delivered_failed,.st-red_flush_failed{background:#fee2e2;color:#b91c1c}
.st-rejected,.st-cancelled{background:#f1f5f9;color:#64748b}
.st-issuing,.st-delivering,.st-red_flushing{background:#e0f2fe;color:#0369a1}
.sim{display:inline-block;background:#fef3c7;color:#92400e;border-radius:999px;font-size:10px;padding:1px 6px;margin-left:4px}
.flag{display:inline-block;background:#fffbeb;color:#b45309;border:1px solid #fde68a;border-radius:4px;font-size:10px;padding:1px 4px;margin-right:3px}
.flag.danger{background:#fef2f2;color:#b91c1c;border-color:#fecaca}
.empty{text-align:center;color:#94a3b8;font-size:13px;padding:40px 0}
.drawer-mask{position:fixed;inset:0;background:rgba(15,23,42,.28);z-index:50;display:flex;justify-content:flex-end}
.drawer{width:min(720px,96vw);background:#fff;height:100%;display:flex;flex-direction:column;box-shadow:-8px 0 30px rgba(15,23,42,.15)}
.drawer-head{display:flex;align-items:center;justify-content:space-between;padding:14px 16px;border-bottom:1px solid #eef1f5}
.drawer-body{overflow:auto;padding:14px 16px 24px}
.drawer-body h4{font-size:13px;margin:16px 0 8px}
.kv-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px 14px;font-size:12px}
.kv-grid span{display:block;color:#94a3b8;font-size:11px;margin-bottom:2px}
.attachments{display:flex;gap:10px;flex-wrap:wrap}
.att{width:118px;text-decoration:none;color:#475569;font-size:10px}
.att img,.att .pdf{width:118px;height:88px;object-fit:cover;border:1px solid #e5e7eb;border-radius:8px;background:#f8fafc}
.att .pdf{display:flex;align-items:center;justify-content:center;color:#94a3b8;font-size:12px}
.att span{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;margin-top:3px}
.form-row{display:flex;gap:10px;align-items:flex-end}
.form-row label{display:flex;flex-direction:column;gap:4px;font-size:11px;color:#64748b}
.form-row input{height:34px;border:1px solid #dbe0e8;border-radius:8px;padding:0 10px;font-size:12px}
.form-row .grow{flex:1}
.drawer-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px}
.logs{font-size:11px;color:#475569}
.log{display:flex;gap:8px;padding:6px 0;border-bottom:1px dashed #eef1f5;flex-wrap:wrap}
</style>
