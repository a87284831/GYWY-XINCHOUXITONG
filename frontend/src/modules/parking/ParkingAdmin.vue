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
      <div class="dash-toolbar">
        <span class="muted">统计范围</span>
        <select v-model.number="days" class="mini-select" @change="loadStats">
          <option :value="7">近 7 天</option>
          <option :value="30">近 30 天</option>
          <option :value="90">近 90 天</option>
        </select>
        <span class="spacer"></span>
        <button class="btn" @click="loadStats">刷新</button>
      </div>

      <div class="rpt-kpi-row">
        <RCard
          v-for="c in kpi" :key="c.key" :label="c.label" :value="c.value"
          :delta="c.delta" :sub="c.sub" :color="c.color"
        />
      </div>

      <div class="rpt-grid-2">
        <RSection :title="`登记趋势（近 ${days} 天）`">
          <div style="height:280px"><canvas :ref="(el) => setEl('trend', el)"></canvas></div>
        </RSection>
        <RSection :title="`时段分布（近 ${days} 天）`">
          <div style="height:280px"><canvas :ref="(el) => setEl('hourly', el)"></canvas></div>
        </RSection>
      </div>

      <div class="rpt-grid-2">
        <RSection title="事由分布">
          <div v-if="(stats.by_reason || []).length" style="height:260px">
            <canvas :ref="(el) => setEl('reason', el)"></canvas>
          </div>
          <div v-else class="msg info" style="text-align:center;padding:30px">暂无登记数据</div>
        </RSection>
        <RSection :title="`小区登记排行（近 ${days} 天）`">
          <div
            v-if="(stats.by_project || []).length"
            :style="{ height: Math.max(200, stats.by_project.length * 34) + 'px' }"
          ><canvas :ref="(el) => setEl('project', el)"></canvas></div>
          <div v-else class="msg info" style="text-align:center;padding:30px">暂无登记数据</div>
        </RSection>
      </div>

      <div class="rpt-grid-2">
        <RSection :title="`门登记排行（近 ${days} 天）`">
          <div
            v-if="(stats.by_gate || []).length"
            :style="{ height: Math.max(200, stats.by_gate.length * 34) + 'px' }"
          ><canvas :ref="(el) => setEl('gate', el)"></canvas></div>
          <div v-else class="msg info" style="text-align:center;padding:30px">暂无登记数据</div>
        </RSection>
        <RSection :title="`星期分布（近 ${days} 天）`">
          <div style="height:260px"><canvas :ref="(el) => setEl('weekday', el)"></canvas></div>
        </RSection>
      </div>

      <div class="rpt-grid-2">
        <RSection title="近 6 个月登记">
          <div style="height:260px"><canvas :ref="(el) => setEl('monthly', el)"></canvas></div>
        </RSection>
        <RSection title="最近登记">
          <table v-if="(stats.recent || []).length" class="mini-table">
            <thead><tr><th>时间</th><th>小区 · 门</th><th>车牌</th><th>访问户号</th></tr></thead>
            <tbody>
              <tr v-for="r in stats.recent" :key="r.id">
                <td class="nowrap">{{ shortTime(r.time) }}</td>
                <td>{{ gateLabel(r.project_name, r.gate_name) }}</td>
                <td>{{ r.plate }}</td>
                <td>{{ r.visit_target }}</td>
              </tr>
            </tbody>
          </table>
          <div v-else class="msg info" style="text-align:center;padding:30px">暂无登记数据</div>
        </RSection>
      </div>

      <p class="muted">只统计进场登记；同一司机多次扫码会生成多条记录。统计范围仅影响趋势与分布，累计数始终为全量。</p>
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
                <button class="btn sm primary" :disabled="!g.enabled" @click="openQr(g)">二维码</button>
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

    <div v-if="qr" class="qr-mask" @click.self="qr = null">
      <div class="qr-box">
        <div class="qr-head">
          <h3>停车登记二维码</h3>
          <span class="spacer"></span>
          <button class="btn" @click="qr = null">关闭</button>
        </div>
        <img :src="qr.png" class="qr-img" :alt="qr.label + ' 停车登记二维码'">
        <div class="qr-name">{{ qr.label }}</div>
        <label class="qr-link">入口链接
          <input id="parkingQrLink" :value="qr.url" readonly @focus="$event.target.select()">
        </label>
        <div class="qr-actions">
          <button class="btn" @click="copyQrLink">复制链接</button>
          <button class="btn primary" @click="downloadQrPng">下载 PNG</button>
          <button class="btn" @click="downloadQrSvg">下载 SVG</button>
        </div>
        <p class="muted">打印张贴到对应门岗；门停用、删除或重置 token 后旧二维码立即失效。</p>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import QRCode from 'qrcode'
import { api, download } from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import { createCharts, rBar, rHBar, rLine, rDoughnut, PALETTE } from '@/modules/report/chartKit'
import RCard from '@/modules/report/RCard.vue'
import RSection from '@/modules/report/RSection.vue'
import {
  qs, kpiCards, pageList, gateLabel, qrFileName,
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

const stats = ref({
  kpi: {}, series: [], hourly: [], by_project: [], by_gate: [],
  by_reason: [], by_weekday: [], monthly: [], recent: [],
})
const kpi = computed(() => kpiCards(stats.value.kpi))
const days = ref(30)
const charts = createCharts()
const els = {}
function setEl(key, el) { els[key] = el }
function shortTime(t) { return String(t || '').slice(5, 16) }

const filters = reactive({ from: '', to: '', project_id: '', gate_id: '' })
const rows = ref([])
const total = ref(0)
const page = ref(1)
const pager = computed(() => pageList(total.value, page.value))

const gates = ref([])
const projectOptions = ref([])
const gateForm = reactive({ id: 0, project_id: 0, name: '', enabled: true, reset_token: false })
const qr = ref(null)

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
    const d = await api('/api/parking/stats?days=' + days.value)
    stats.value = {
      kpi: d.kpi || {},
      series: d.series || [],
      hourly: d.hourly || [],
      by_project: d.by_project || [],
      by_gate: d.by_gate || [],
      by_reason: d.by_reason || [],
      by_weekday: d.by_weekday || [],
      monthly: d.monthly || [],
      recent: d.recent || [],
    }
    await drawDashboard()
  } catch (e) { fail(e) }
}

const countCatX = {
  ticks: { color: '#64748b', font: { size: 10 } },
  grid: { color: 'rgba(148,163,184,.15)' },
}
const countNumY = {
  beginAtZero: true,
  ticks: { color: '#64748b', font: { size: 10 }, precision: 0 },
  grid: { color: 'rgba(148,163,184,.15)' },
}
const countNumX = {
  beginAtZero: true,
  ticks: { color: '#64748b', font: { size: 10 }, precision: 0 },
  grid: { color: 'rgba(148,163,184,.15)' },
}
const countCatY = {
  ticks: { color: '#475569', font: { size: 11 } },
  grid: { display: false },
}

async function drawDashboard() {
  await nextTick()
  const s = stats.value
  if (els.trend) {
    rLine(charts, 'trend', els.trend, s.series.map((x) => x.label), [
      { label: '登记数', data: s.series.map((x) => x.count), color: '#f97316', fill: true },
    ], null, { scales: { x: countCatX, y: countNumY } })
  }
  if (els.hourly) {
    rBar(charts, 'hourly', els.hourly, s.hourly.map((x) => x.label), s.hourly.map((x) => x.count), {
      label: '登记数', color: '#2563eb', opts: { scales: { x: countCatX, y: countNumY } },
    })
  }
  if (els.reason && s.by_reason.length) {
    const labels = s.by_reason.map((x) => x.reason || '未填写')
    const values = s.by_reason.map((x) => x.count)
    const sum = values.reduce((a, b) => a + b, 0)
    rDoughnut(charts, 'reason', els.reason, labels, values, PALETTE, '合计', sum, (v) => v + ' 次')
  }
  if (els.project && s.by_project.length) {
    rHBar(charts, 'project', els.project, s.by_project.map((x) => x.project_name || '未命名小区'), s.by_project.map((x) => x.count), '#f97316', (v) => v + ' 次', {
      scales: { x: countNumX, y: countCatY },
    })
  }
  if (els.gate && s.by_gate.length) {
    rHBar(charts, 'gate', els.gate, s.by_gate.map((x) => gateLabel(x.project_name, x.gate_name)), s.by_gate.map((x) => x.count), '#0d9488', (v) => v + ' 次', {
      scales: { x: countNumX, y: countCatY },
    })
  }
  if (els.weekday) {
    rBar(charts, 'weekday', els.weekday, s.by_weekday.map((x) => x.label), s.by_weekday.map((x) => x.count), {
      label: '登记数', color: '#8b5cf6', opts: { scales: { x: countCatX, y: countNumY } },
    })
  }
  if (els.monthly) {
    rBar(charts, 'monthly', els.monthly, s.monthly.map((x) => x.label), s.monthly.map((x) => x.count), {
      label: '登记数', color: '#0d9488', opts: { scales: { x: countCatX, y: countNumY } },
    })
  }
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
  if (tab.value === 'dashboard' && key !== 'dashboard') charts.destroyAll()
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

async function openQr(g) {
  error.value = ''
  try {
    const label = gateLabel(g.project_name, g.name)
    const qrCanvas = document.createElement('canvas')
    await QRCode.toCanvas(qrCanvas, g.entry_url, { width: 640, margin: 2, errorCorrectionLevel: 'M' })
    const pad = 48
    const labelH = 84
    const canvas = document.createElement('canvas')
    canvas.width = qrCanvas.width + pad * 2
    canvas.height = qrCanvas.height + pad * 2 + labelH
    const ctx = canvas.getContext('2d')
    ctx.fillStyle = '#ffffff'
    ctx.fillRect(0, 0, canvas.width, canvas.height)
    ctx.drawImage(qrCanvas, pad, pad)
    ctx.fillStyle = '#0f172a'
    ctx.textAlign = 'center'
    ctx.font = '600 30px "Microsoft YaHei", sans-serif'
    ctx.fillText(label.slice(0, 24), canvas.width / 2, qrCanvas.height + pad + 44)
    const svgRaw = await QRCode.toString(g.entry_url, { type: 'svg', margin: 2, width: 640 })
    qr.value = {
      label,
      url: g.entry_url,
      png: canvas.toDataURL('image/png'),
      svg: withQrSvgLabel(svgRaw, label),
    }
  } catch (e) {
    error.value = '二维码生成失败：' + e.message
  }
}

function withQrSvgLabel(svg, label) {
  const esc = (s) => String(s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]))
  const vb = (svg.match(/viewBox="([^"]+)"/) || [])[1] || '0 0 41 41'
  const inner = svg.replace(/^[\s\S]*?<svg[^>]*>/, '').replace(/<\/svg>\s*$/, '')
  return '<svg xmlns="http://www.w3.org/2000/svg" width="680" height="752" viewBox="0 0 680 752">'
    + '<rect width="680" height="752" fill="#ffffff"/>'
    + '<svg x="20" y="20" width="640" height="640" viewBox="' + vb + '">' + inner + '</svg>'
    + '<text x="340" y="706" text-anchor="middle" font-family="Microsoft YaHei, sans-serif" font-size="28" font-weight="600" fill="#0f172a">'
    + esc(String(label).slice(0, 24)) + '</text></svg>'
}

function triggerDownload(href, name) {
  const a = document.createElement('a')
  a.href = href
  a.download = name
  document.body.appendChild(a)
  a.click()
  a.remove()
}

function downloadQrPng() {
  if (!qr.value) return
  triggerDownload(qr.value.png, qrFileName(qr.value.label) + '.png')
}

function downloadQrSvg() {
  if (!qr.value) return
  const blob = new Blob([qr.value.svg], { type: 'image/svg+xml;charset=utf-8' })
  const url = URL.createObjectURL(blob)
  triggerDownload(url, qrFileName(qr.value.label) + '.svg')
  setTimeout(() => URL.revokeObjectURL(url), 4000)
}

async function copyQrLink() {
  if (!qr.value) return
  try {
    await navigator.clipboard.writeText(qr.value.url)
    flash('入口链接已复制')
  } catch (e) {
    const el = document.getElementById('parkingQrLink')
    if (!el) return
    el.focus()
    el.select()
    try {
      document.execCommand('copy')
      flash('入口链接已复制')
    } catch (err) {
      error.value = '复制失败，请手动选择链接复制'
    }
  }
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
onBeforeUnmount(() => charts.destroyAll())

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
.dash-toolbar{display:flex;gap:8px;align-items:center;margin-bottom:14px}
.dash-toolbar .btn{height:32px}
.card{background:#fff;border:1px solid #edf0f5;border-radius:12px;padding:14px;margin-bottom:12px}
.card h3{margin:0 0 12px;font-size:14px}
.filters{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.filters .btn{height:32px}
.spacer{flex:1}
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
.qr-mask{position:fixed;inset:0;background:rgba(15,23,42,.45);z-index:60;display:flex;align-items:center;justify-content:center;padding:20px}
.qr-box{background:#fff;border-radius:14px;padding:18px 20px 16px;width:420px;max-width:100%;box-shadow:0 20px 60px rgba(15,23,42,.25)}
.qr-head{display:flex;align-items:center;gap:8px;margin-bottom:12px}
.qr-head h3{margin:0;font-size:15px}
.qr-img{display:block;width:320px;max-width:100%;margin:0 auto;border:1px solid #eef2f7;border-radius:10px}
.qr-name{text-align:center;font-size:14px;font-weight:600;color:#0f172a;margin:8px 0 12px}
.qr-link{display:flex;flex-direction:column;gap:5px;font-size:11px;color:#64748b}
.qr-link input{height:34px;border:1px solid #dbe0e8;border-radius:8px;padding:0 9px;font-size:11px;background:#f8fafc;color:#334155}
.qr-actions{display:flex;gap:8px;margin-top:12px}
.qr-actions .btn{flex:1}
@media(max-width:1100px){.rpt-grid-2{grid-template-columns:1fr}}
</style>
