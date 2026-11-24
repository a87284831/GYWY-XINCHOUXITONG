<template>
  <div class="parking-admin">
    <div class="page-head">
      <div>
        <h2>停车场登记</h2>
        <p class="muted">外来司机扫码登记进场；二维码请用下面「门与入口 URL」中的入口地址自行生成。</p>
      </div>
      <div class="head-actions">
        <button v-for="t in tabs" :key="t.key" class="btn" :class="{ primary: tab === t.key }" @click="switchTab(t.key)">
          {{ t.label }}
        </button>
      </div>
    </div>

    <div v-if="error" class="err">{{ error }}</div>
    <div v-if="okMsg" class="okbar">{{ okMsg }}</div>

    <!-- 1. 数据驾驶舱 -->
    <template v-if="tab === 'dashboard'">
      <div class="kpis">
        <div v-for="c in kpi" :key="c.key" class="kpi">
          <span>{{ c.label }}</span><b>{{ c.value }}</b><small>{{ c.hint }}</small>
        </div>
      </div>
      <div class="cols">
        <div class="card">
          <h3>近 7 日登记趋势</h3>
          <div v-for="s in trend" :key="s.date" class="bar-row">
            <span class="bar-label">{{ s.date }}</span>
            <div class="bar"><i :style="{ width: s.pct }"></i></div>
            <span class="bar-val">{{ s.count }} 次</span>
          </div>
          <div v-if="!trend.length" class="empty">暂无数据</div>
        </div>
        <div class="card">
          <h3>今日小区分布 TOP</h3>
          <div v-for="p in projects" :key="p.project_name" class="bar-row">
            <span class="bar-label">{{ p.project_name }}</span>
            <div class="bar"><i class="trend" :style="{ width: p.pct }"></i></div>
            <span class="bar-val">{{ p.count }} 次</span>
          </div>
          <div v-if="!projects.length" class="empty">今日暂无登记</div>
        </div>
      </div>
      <p class="muted">只统计进场登记；同一司机多次扫码会生成多条记录。</p>
    </template>

    <!-- 2. 登记数据 -->
    <template v-else-if="tab === 'list'">
      <div class="card filters">
        <input v-model="filters.from" type="date" class="mini-select">
        <span class="muted">至</span>
        <input v-model="filters.to" type="date" class="mini-select">
        <select v-if="isAdmin" v-model="filters.project_id" class="mini-select">
          <option value="">全部小区</option>
          <option v-for="p in projectOptions" :key="p.id" :value="p.id">{{ p.name }}</option>
        </select>
        <select v-if="isAdmin" v-model="filters.gate_id" class="mini-select">
          <option value="">全部门</option>
          <option v-for="g in gates" :key="g.id" :value="g.id">{{ g.label }}</option>
        </select>
        <button class="btn primary" @click="search">查询</button>
        <button class="btn" @click="exportExcel">导出 Excel</button>
        <span class="spacer"></span>
        <span class="muted">共 {{ total }} 条</span>
      </div>

      <div class="card">
        <table class="mini-table">
          <thead>
            <tr>
              <th>登记时间</th><th>小区</th><th>门</th><th>车牌号</th><th>司机姓名</th><th>手机号</th><th>访问户号/受访人</th><th>事由</th>
              <template v-if="isAdmin"><th v-for="f in activeFields" :key="f.key">{{ f.label }}</th></template>
            </tr>
          </thead>
          <tbody>
            <tr v-for="r in rows" :key="r.id">
              <td class="nowrap">{{ r.time }}</td><td>{{ r.project_name }}</td><td>{{ r.gate_name }}</td>
              <td>{{ r.plate }}</td><td>{{ r.name }}</td><td>{{ r.phone }}</td><td>{{ r.visit_target }}</td><td>{{ r.reason }}</td>
              <template v-if="isAdmin"><td v-for="f in activeFields" :key="f.key">{{ (r.extra || {})[f.key] || '' }}</td></template>
            </tr>
          </tbody>
        </table>
        <div v-if="!rows.length" class="empty">暂无登记记录</div>
        <div v-if="pager.pages > 1" class="pager">
          <button class="btn sm" :disabled="pager.current <= 1" @click="gotoPage(pager.current - 1)">上一页</button>
          <button v-for="p in pager.items" :key="p" class="btn sm" :class="{ primary: p === pager.current }" @click="gotoPage(p)">{{ p }}</button>
          <button class="btn sm" :disabled="pager.current >= pager.pages" @click="gotoPage(pager.current + 1)">下一页</button>
        </div>
      </div>
    </template>

    <!-- 3. 门与入口 URL（仅管理员） -->
    <template v-else-if="tab === 'gates'">
      <div class="card">
        <h3>{{ gateForm.id ? '编辑门' : '新增门' }}</h3>
        <div class="form-grid">
          <label>小区
            <select v-model.number="gateForm.project_id" :disabled="!!gateForm.id">
              <option :value="0">请选择小区</option>
              <option v-for="p in projectOptions" :key="p.id" :value="p.id">{{ p.name }}</option>
            </select>
          </label>
          <label>门名称<input v-model="gateForm.name" maxlength="60" placeholder="如 东门 / 南门"></label>
          <label class="chk"><input v-model="gateForm.enabled" type="checkbox"> 启用（停用后旧二维码立即失效）</label>
          <label v-if="gateForm.id" class="chk"><input v-model="gateForm.reset_token" type="checkbox"> 重置入口 token（旧二维码失效）</label>
        </div>
        <div class="inline-actions">
          <button class="btn primary" :disabled="busy" @click="saveGate">保存</button>
          <button v-if="gateForm.id" class="btn" @click="resetGateForm">取消编辑</button>
          <span class="muted">入口 URL 由「小区名 · 门名」标签 + 随机 token 组成，复制后自行生成二维码。</span>
        </div>
      </div>

      <div class="card">
        <h3>门列表</h3>
        <table class="mini-table">
          <thead><tr><th>小区</th><th>门</th><th>入口 URL</th><th>状态</th><th>操作</th></tr></thead>
          <tbody>
            <tr v-for="g in gates" :key="g.id">
              <td>{{ g.project_name }}</td>
              <td>{{ g.name }}<div class="muted">{{ g.label }}</div></td>
              <td class="url">{{ g.entry_url }}</td>
              <td><span class="badge" :class="{ gray: !g.enabled }">{{ g.enabled ? '启用' : '停用' }}</span></td>
              <td class="ops">
                <button class="btn sm" @click="copyUrl(g.entry_url)">复制 URL</button>
                <button class="btn sm" @click="editGate(g)">编辑</button>
                <button class="btn sm danger" @click="removeGate(g)">删除</button>
              </td>
            </tr>
          </tbody>
        </table>
        <div v-if="!gates.length" class="empty">暂无门，请先新增</div>
      </div>
    </template>

    <!-- 4. 登记字段设置（仅管理员） -->
    <template v-else-if="tab === 'config'">
      <div class="card">
        <h3>事由选项</h3>
        <div class="chips">
          <span v-for="(r, i) in config.reasons" :key="r + i" class="chip">{{ r }} <b class="x" @click="config.reasons.splice(i, 1)">×</b></span>
          <span v-if="!config.reasons.length" class="muted">未配置时默认：拜访 / 送货 / 施工 / 快递 / 其他</span>
        </div>
        <div class="inline-actions">
          <input v-model="newReason" class="mini-select" maxlength="20" placeholder="新增事由（最多 20 字）" @keyup.enter="addReason">
          <button class="btn" @click="addReason">添加</button>
        </div>
      </div>

      <div class="card">
        <h3>自定义字段</h3>
        <div v-for="(f, i) in config.fields" :key="i" class="field-row">
          <input v-model="f.label" class="mini-select w160" maxlength="30" placeholder="字段名称">
          <select v-model="f.type" class="mini-select">
            <option value="text">文本</option><option value="number">数字</option><option value="radio">单选</option>
          </select>
          <input v-if="f.type === 'radio'" v-model="f.optionsText" class="mini-select grow" placeholder="选项，用逗号分隔">
          <span v-else class="grow"></span>
          <label class="chk"><input v-model="f.required" type="checkbox"> 必填</label>
          <label class="chk"><input v-model="f.enabled" type="checkbox"> 启用</label>
          <button class="btn sm danger" @click="config.fields.splice(i, 1)">删除</button>
        </div>
        <div v-if="!config.fields.length" class="empty">暂无自定义字段</div>
        <div class="inline-actions">
          <button class="btn" @click="addField">＋ 添加字段</button>
          <button class="btn primary" :disabled="busy" @click="saveConfig">保存字段设置</button>
        </div>
        <p class="muted">字段 key 由系统按名称生成，改名称不会丢历史数据；停用字段不再出现在司机端与导出列。</p>
      </div>
    </template>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { api, download } from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import {
  qs, kpiCards, trendBars, projectBars, pageList,
} from './parkingLogic'

const auth = useAuthStore()
const isAdmin = computed(() => auth.user && auth.user.role === 'admin')

const tab = ref('dashboard')
const tabs = computed(() => [
  { key: 'dashboard', label: '数据驾驶舱' },
  { key: 'list', label: '登记数据' },
  ...(isAdmin.value ? [
    { key: 'gates', label: '门与入口 URL' },
    { key: 'config', label: '字段设置' },
  ] : []),
])

const error = ref('')
const okMsg = ref('')
const busy = ref(false)
let okTimer = null

const kpi = ref(kpiCards())
const trend = ref([])
const projects = ref([])

const filters = reactive({ from: '', to: '', project_id: '', gate_id: '' })
const rows = ref([])
const total = ref(0)
const page = ref(1)
const pager = computed(() => pageList(total.value, page.value))

const gates = ref([])
const projectOptions = ref([])
const gateForm = reactive({ id: 0, project_id: 0, name: '', enabled: true, reset_token: false })

const config = ref({ reasons: [], fields: [] })
const activeFields = computed(() => (config.value.fields || []).filter((f) => f.enabled))
const newReason = ref('')

function flash(msg) {
  okMsg.value = msg
  if (okTimer) clearTimeout(okTimer)
  okTimer = setTimeout(() => { okMsg.value = '' }, 3500)
}

function fail(e) {
  error.value = e && e.message ? e.message : '操作失败'
}

async function loadStats() {
  try {
    const d = await api('/api/parking/stats')
    kpi.value = kpiCards(d.kpi)
    trend.value = trendBars(d.series)
    projects.value = projectBars(d.by_project)
  } catch (e) { fail(e) }
}

async function loadList() {
  error.value = ''
  try {
    const q = qs({ ...filters, page: page.value })
    const d = await api('/api/parking/list' + q)
    rows.value = d.rows || []
    total.value = Number(d.total) || 0
  } catch (e) { fail(e) }
}

async function loadGates() {
  if (!isAdmin.value) return
  try {
    const d = await api('/api/parking/gates')
    gates.value = d.gates || []
  } catch (e) { fail(e) }
}

async function loadConfig() {
  if (!isAdmin.value) return
  try {
    const d = await api('/api/parking/config')
    applyConfig(d.config || {})
  } catch (e) { fail(e) }
}

function applyConfig(c) {
  config.value = {
    reasons: Array.isArray(c.reasons) ? c.reasons.slice() : [],
    fields: (Array.isArray(c.fields) ? c.fields : []).map((f) => ({
      key: f.key || '',
      label: f.label || '',
      type: f.type || 'text',
      required: !!f.required,
      enabled: f.enabled !== false,
      optionsText: (Array.isArray(f.options) ? f.options : []).join(','),
    })),
  }
}

async function loadProjects() {
  if (!isAdmin.value) return
  try {
    const d = await api('/api/projects')
    projectOptions.value = d.projects || []
  } catch (e) { fail(e) }
}

async function switchTab(key) {
  tab.value = key
  error.value = ''
  if (key === 'dashboard') await loadStats()
  else if (key === 'list') {
    if (isAdmin.value && !gates.value.length) await loadGates()
    await loadList()
  } else if (key === 'gates') await loadGates()
  else if (key === 'config') await loadConfig()
}

function search() {
  page.value = 1
  loadList()
}

function gotoPage(p) {
  page.value = p
  loadList()
}

function exportExcel() {
  download('/api/parking/export' + qs(filters), '停车登记.xlsx')
}

function editGate(g) {
  Object.assign(gateForm, {
    id: g.id, project_id: g.project_id, name: g.name, enabled: !!g.enabled, reset_token: false,
  })
  window.scrollTo({ top: 0, behavior: 'smooth' })
}

function resetGateForm() {
  Object.assign(gateForm, { id: 0, project_id: 0, name: '', enabled: true, reset_token: false })
}

async function saveGate() {
  error.value = ''
  if (!gateForm.project_id) { error.value = '请选择小区'; return }
  if (!String(gateForm.name || '').trim()) { error.value = '请填写门名称'; return }
  busy.value = true
  try {
    const body = {
      project_id: gateForm.project_id,
      name: String(gateForm.name).trim(),
      enabled: !!gateForm.enabled,
    }
    if (gateForm.id) Object.assign(body, { id: gateForm.id, reset_token: !!gateForm.reset_token })
    await api('/api/parking/gates', { body })
    resetGateForm()
    await loadGates()
    flash('门已保存')
  } catch (e) { fail(e) } finally { busy.value = false }
}

async function removeGate(g) {
  if (!window.confirm(`确认删除「${g.label}」？删除后该门二维码立即失效，历史登记保留。`)) return
  try {
    await api('/api/parking/gates/delete', { body: { id: g.id } })
    await loadGates()
    flash('门已删除')
  } catch (e) { fail(e) }
}

async function copyUrl(url) {
  try {
    await navigator.clipboard.writeText(url)
  } catch (e) {
    const ta = document.createElement('textarea')
    ta.value = url
    document.body.appendChild(ta)
    ta.select()
    try { document.execCommand('copy') } catch (err) { /* 忽略：部分浏览器禁用 */ }
    ta.remove()
  }
  flash('入口 URL 已复制，可用它生成二维码')
}

function addReason() {
  const r = String(newReason.value || '').trim().slice(0, 20)
  if (!r) return
  if (config.value.reasons.includes(r)) { error.value = '该事由已存在'; return }
  config.value.reasons.push(r)
  newReason.value = ''
  error.value = ''
}

function addField() {
  config.value.fields.push({ key: '', label: '', type: 'text', required: false, enabled: true, optionsText: '' })
}

async function saveConfig() {
  error.value = ''
  const fields = []
  for (const f of config.value.fields) {
    const label = String(f.label || '').trim()
    if (!label) { error.value = '字段名称不能为空'; return }
    fields.push({
      key: f.key,
      label,
      type: f.type,
      required: !!f.required,
      enabled: !!f.enabled,
      options: f.type === 'radio'
        ? String(f.optionsText || '').split(/[,，\n]/).map((s) => s.trim()).filter(Boolean)
        : [],
    })
  }
  busy.value = true
  try {
    const d = await api('/api/parking/config', {
      body: { reasons: config.value.reasons, fields },
    })
    applyConfig(d.config || {})
    flash('字段设置已保存，司机端即时生效')
  } catch (e) { fail(e) } finally { busy.value = false }
}

onMounted(async () => {
  if (isAdmin.value) {
    await Promise.all([loadProjects(), loadGates(), loadConfig()])
  }
  await loadStats()
})
</script>

<style scoped>
.parking-admin{padding:18px 22px 40px}
.page-head{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;margin-bottom:14px;flex-wrap:wrap}
.page-head h2{margin:0;font-size:19px}
.muted{color:#94a3b8;font-size:12px}
.head-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.head-actions .btn{height:32px}
.mini-select{height:32px;border:1px solid #dbe0e8;border-radius:8px;padding:0 8px;background:#fff;font-size:12px}
.mini-select:disabled{background:#f8fafc;color:#94a3b8}
.err{background:#fef2f2;border:1px solid #fecaca;color:#b91c1c;padding:9px 12px;border-radius:8px;font-size:12px;margin-bottom:10px}
.okbar{background:#f0fdf4;border:1px solid #bbf7d0;color:#15803d;padding:9px 12px;border-radius:8px;font-size:12px;margin-bottom:10px}
.kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:12px}
.kpi{background:#fff;border:1px solid #edf0f5;border-radius:12px;padding:13px}
.kpi span{font-size:12px;color:#64748b;display:block}
.kpi b{font-size:22px;color:#0f172a;display:block;margin-top:5px}
.kpi small{font-size:11px;color:#94a3b8}
.cols{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.card{background:#fff;border:1px solid #edf0f5;border-radius:12px;padding:14px;margin-bottom:12px}
.card h3{margin:0 0 12px;font-size:14px}
.filters{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.filters .btn{height:32px}
.spacer{flex:1}
.bar-row{display:grid;grid-template-columns:110px 1fr 100px;gap:10px;align-items:center;font-size:11px;margin-bottom:9px}
.bar-label{color:#475569;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.bar{height:8px;background:#f1f5f9;border-radius:999px;overflow:hidden}
.bar i{display:block;height:100%;background:#f97316;border-radius:999px}
.bar i.trend{background:#0d9488}
.bar-val{text-align:right;color:#64748b;white-space:nowrap}
.mini-table{width:100%;border-collapse:collapse;font-size:12px}
.mini-table th{text-align:left;color:#94a3b8;font-weight:500;padding:7px 8px;border-bottom:1px solid #f1f4f8}
.mini-table td{padding:8px;border-bottom:1px solid #f5f7fa;vertical-align:top}
.nowrap{white-space:nowrap}
.url{max-width:320px;word-break:break-all;color:#475569;font-size:11px;line-height:1.5}
.ops{white-space:nowrap}
.ops .btn{margin-right:4px}
.empty{text-align:center;color:#94a3b8;font-size:12px;padding:22px 0}
.pager{display:flex;gap:6px;justify-content:flex-end;margin-top:12px}
.pager .btn{height:28px;padding:0 10px}
.form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;margin-bottom:12px}
.form-grid label{display:flex;flex-direction:column;gap:5px;font-size:12px;color:#475569}
.form-grid input,.form-grid select{height:34px;border:1px solid #dbe0e8;border-radius:8px;padding:0 10px;background:#fff;font-size:13px}
.form-grid label.chk,.chk{flex-direction:row;align-items:center;gap:6px;white-space:nowrap}
.chk input{height:auto}
.inline-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.inline-actions .btn{height:32px}
.chips{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px}
.chip{display:inline-flex;align-items:center;gap:6px;background:#fff7ed;border:1px solid #fed7aa;color:#9a3412;border-radius:999px;padding:4px 10px;font-size:12px}
.chip .x{cursor:pointer;font-weight:400}
.field-row{display:flex;gap:8px;align-items:center;margin-bottom:9px;flex-wrap:wrap}
.field-row .btn{height:28px}
.w160{width:160px}
.grow{flex:1}
.badge{display:inline-block;background:#ecfdf5;color:#047857;border-radius:999px;font-size:11px;padding:2px 8px}
.badge.gray{background:#f1f5f9;color:#64748b}
.btn.sm{height:28px;padding:0 10px;font-size:12px}
@media(max-width:1100px){.kpis{grid-template-columns:repeat(2,1fr)}.cols{grid-template-columns:1fr}}
</style>
