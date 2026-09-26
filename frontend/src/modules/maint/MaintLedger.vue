<template>
  <div class="card">
    <h3>{{ label }}维保台账</h3>
    <div class="row" style="flex-wrap:wrap;gap:8px">
      <button class="btn primary sm" @click="openEdit(null)">＋ 新增合同</button>
      <input v-model="f.kw" placeholder="关键词(项目/签约方/备注)" style="width:180px">
      <input v-model="f.efrom" type="date">
      <input v-model="f.eto" type="date">
      <input v-model="f.party" placeholder="签约方" style="width:120px">
      <input v-model="f.amin" type="number" placeholder="金额下限" style="width:100px">
      <input v-model="f.amax" type="number" placeholder="金额上限" style="width:100px">
      <template v-if="isElev">
        <input v-model="f.emin" type="number" placeholder="台数下限" style="width:90px">
        <input v-model="f.emax" type="number" placeholder="台数上限" style="width:90px">
      </template>
      <select v-model="f.year"><option value="">全部年份</option><option v-for="y in yearOpts" :key="y">{{ y }}</option></select>
      <select v-model="f.sortField">
        <option value="end_date">按到期时间</option><option value="party">按签约方</option><option value="amount">按签约金额</option>
        <option v-if="isElev" value="elevator_count">按电梯台数</option>
      </select>
      <button class="btn sm" @click="toggleDir">{{ sort.dir === 'asc' ? '升序 ↑' : '降序 ↓' }}</button>
      <button class="btn sm" @click="exportCsv">导出CSV</button>
      <button class="btn sm" @click="clearFilters">清除筛选</button>
    </div>
    <div class="table-wrap" style="margin-top:10px"><table class="tb">
      <thead><tr><th>项目名称</th><th>签约方</th><th>签约金额</th><th>签订日期</th><th>生效开始</th><th>到期日期</th>
      <template v-if="isElev"><th>电梯台数</th><th>每台单价</th></template><template v-else><th>建筑面积(㎡)</th><th>每㎡单价</th></template>
      <th>员工状态</th><th>备注</th><th>PDF扫描件</th><th>操作</th></tr></thead>
      <tbody>
        <tr v-if="loadErr"><td colspan="12" class="msg err">{{ loadErr }}</td></tr>
        <tr v-else-if="!loaded"><td colspan="12">加载中...</td></tr>
        <tr v-else-if="!rows.length"><td colspan="12" style="text-align:center;color:#94a3b8;padding:24px">无匹配合同记录</td></tr>
        <tr v-for="r in rows" :key="r.id">
          <td>{{ r.project_name }}</td><td>{{ r.party }}</td><td class="num">{{ maintFmtMoney(r.amount) }}</td>
          <td>{{ r.sign_date || '' }}</td><td>{{ r.start_date || '' }}</td><td>{{ r.end_date || '' }}</td>
          <template v-if="isElev"><td>{{ r.elevator_count || 0 }}</td><td>{{ r.price_per_unit ? maintFmtMoney(r.price_per_unit) : '-' }}</td></template>
          <template v-else><td>{{ r.building_area_sqm || '-' }}</td><td>{{ r.price_per_sqm ? maintFmtMoney(r.price_per_sqm) : '-' }}</td></template>
          <td><span :class="badgeCls(r)">{{ badgeText(r) }}</span></td>
          <td>{{ r.remark || '' }}</td>
          <td>
            <template v-if="r.pdf_name"><button class="btn sm" @click="viewPdf(r.id)">预览</button> <button class="btn sm" @click="downloadPdf(r.id)">下载</button></template>
            <span v-else style="color:#94a3b8">无</span>
            <label class="btn sm primary" style="cursor:pointer" :for="'mpdf_' + r.id">上传</label>
            <input :id="'mpdf_' + r.id" type="file" accept="application/pdf,.pdf" style="display:none" @change="uploadPdf(r.id, $event)" />
          </td>
          <td><button class="btn sm" @click="openEdit(r.id)">编辑</button> <button class="btn sm danger" @click="del(r)">删除</button></td>
        </tr>
      </tbody>
    </table></div>
  </div>

  <MaintModal :title="(editId ? '编辑' : '新增') + label + '维保合同'" :show="modalShow" @close="modalShow = false" @save="saveContract">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
      <div style="grid-column:1/3"><label>所属项目名称</label><input v-model="fm.project_name" placeholder="如：盈科国际广场" style="width:100%"></div>
      <div style="grid-column:1/3"><label>签约方（{{ label }}维保单位）</label><select v-model="fm.party" style="width:100%">
        <option value="">-- 请选择签约方 --</option>
        <option v-for="p in partnerOpts" :key="p.name" :value="p.name">{{ p.name }}{{ p.type !== 'both' ? ' (' + (p.type === 'fire' ? '消防' : '电梯') + ')' : '' }}</option>
      </select>
        <div style="font-size:11px;color:#94a3b8;margin-top:2px">可在「签约方维护」中管理维保单位列表</div></div>
      <div><label>合同签约金额（元）</label><input v-model="fm.amount" type="number" placeholder="0" style="width:100%"></div>
      <template v-if="isElev">
        <div><label>电梯维保总台数</label><input v-model="fm.count" type="number" style="width:100%"></div>
        <div><label>每台单价（元）</label><input v-model="fm.price" type="number" style="width:100%" title="自动计算：合同签约金额 ÷ 电梯总台数；也可手动修改覆盖" @input="onPriceInput"></div>
      </template>
      <template v-else>
        <div><label>项目建筑面积（㎡）</label><input v-model="fm.area" type="number" style="width:100%"></div>
        <div><label>每平方米单价（元/㎡）</label><input v-model="fm.price" type="number" style="width:100%" title="自动计算：合同签约金额 ÷ 项目建筑面积；也可手动修改覆盖" @input="onPriceInput"></div>
      </template>
      <div><label>合同签订日期</label><input v-model="fm.sign_date" type="date" style="width:100%"></div>
      <div><label>合同生效开始日期</label><input v-model="fm.start_date" type="date" style="width:100%"></div>
      <div><label>合同到期日期</label><input v-model="fm.end_date" type="date" style="width:100%"></div>
      <div style="grid-column:1/3"><label>特殊要求 / 备注</label><textarea v-model="fm.remark" rows="2" style="width:100%"></textarea></div>
    </div>
  </MaintModal>
</template>

<script>
// 模块级合同缓存 — 复刻 MAINT.data（跨路由访问不重复拉取，变更后清空强制刷新）
const dataCache = { fire: [], elevator: [] }
</script>

<script setup>
// 消防/电梯维保台账 — 复刻 pageMaintLedger 群（app.js:2841-3105）+ maintContractEdit 弹窗（2975-3040）
import { reactive, ref, computed, watch, onMounted } from 'vue'
import { api } from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import { toast } from '@/utils/toast'
import { maintFmtMoney, maintStatusOf, maintOverlapsYear, filterLedger, buildLedgerExportRows, buildCsv, priceAutoCalc } from './maintLogic'
import MaintModal from './MaintModal.vue'

const props = defineProps({ type: { type: String, default: 'fire' } })
const auth = useAuthStore()

const isElev = props.type === 'elevator'
const label = isElev ? '电梯' : '消防'
const yearOpts = Array.from({ length: 16 }, (_, i) => 2020 + i)
// 导出状态列文本（旧版 MAINT_STATUS_TEXT）
const STATUS_TEXT = { normal: '正常在保', soon: '30天内即将到期', expired: '已过期' }

const loaded = ref(false)
const loadErr = ref('')
const f = reactive({ kw: '', efrom: '', eto: '', party: '', amin: '', amax: '', emin: '', emax: '', year: '', sortField: 'end_date' })
const sort = reactive({ dir: 'asc' }) // 旧版 MAINT.sort 默认升序

// 筛选归一化 — 复刻 maintGetFilters（kw/party 小写、金额台数转 Number 或 null）
const filters = computed(() => ({
  kw: (f.kw || '').trim().toLowerCase(),
  efrom: (f.efrom || '').trim(), eto: (f.eto || '').trim(),
  party: (f.party || '').trim().toLowerCase(),
  amin: f.amin === '' || f.amin == null ? null : Number(f.amin),
  amax: f.amax === '' || f.amax == null ? null : Number(f.amax),
  emin: f.emin === '' || f.emin == null ? null : Number(f.emin),
  emax: f.emax === '' || f.emax == null ? null : Number(f.emax),
  year: (f.year || '').trim(),
  sortField: f.sortField,
}))

const rows = computed(() => filterLedger(dataCache[props.type], filters.value, props.type, sort, null))

async function load() {
  loadErr.value = ''
  if (!dataCache[props.type].length) {
    try {
      const r = await api(`/api/maintenance/contracts?type=${props.type}`)
      dataCache[props.type] = r.contracts || []
    } catch (e) { loadErr.value = e.message; return }
  }
  loaded.value = true
}

function toggleDir() { sort.dir = sort.dir === 'asc' ? 'desc' : 'asc' }

// 旧版「清除筛选」仅重置 focus 聚焦（focus 仅由已下线的驾驶舱设置），常规筛选输入保留
function clearFilters() {}

function badgeCls(r) {
  const st = maintStatusOf(r.end_date)
  return st === 'expired' ? 'tag red' : st === 'soon' ? 'tag orange' : 'tag green'
}
function badgeText(r) {
  const st = maintStatusOf(r.end_date)
  return st === 'expired' ? '已过期' : st === 'soon' ? '即将到期' : '正常在保'
}

/* ---------- 合同编辑弹窗 ---------- */
const modalShow = ref(false)
const editId = ref(null)
const partners = ref([])
const partnerOpts = computed(() => partners.value.filter((p) => p.type === 'both' || p.type === props.type))
const fm = reactive({ project_name: '', party: '', amount: '', count: '', area: '', price: '', sign_date: '', start_date: '', end_date: '', remark: '' })

async function loadPartners() {
  try { const r = await api('/api/maintenance/partners'); partners.value = r.partners || [] } catch (e) { partners.value = [] }
}

// 单价自动计算联动（复刻 manual/auto 标志逻辑：金额或台数/面积变更时自动算，用户手动改过单价后不再覆盖）
let priceManual = false
let autoFlag = false
function onPriceInput() { if (!autoFlag) priceManual = true }
function calcPrice() {
  if (priceManual) return
  fm.price = priceAutoCalc(fm.amount, isElev ? fm.count : fm.area, isElev)
}
watch(() => [fm.amount, isElev ? fm.count : fm.area], () => { autoFlag = true; calcPrice(); autoFlag = false })

async function openEdit(id) {
  await loadPartners()
  editId.value = id
  const row = id ? dataCache[props.type].find((r) => r.id === id) : null
  fm.project_name = row ? (row.project_name ?? '') : ''
  fm.party = row ? (row.party ?? '') : ''
  fm.amount = row ? (row.amount ?? '') : ''
  if (isElev) fm.count = row ? (row.elevator_count ?? '') : ''
  else fm.area = row ? (row.building_area_sqm ?? '') : ''
  fm.price = row ? (row.price_per_unit ?? row.price_per_sqm ?? '') : ''
  fm.sign_date = row ? (row.sign_date ?? '') : ''
  fm.start_date = row ? (row.start_date ?? '') : ''
  fm.end_date = row ? (row.end_date ?? '') : ''
  fm.remark = row ? (row.remark ?? '') : ''
  priceManual = false
  modalShow.value = true
  calcPrice() // 旧版弹窗打开即按金额÷台数/面积重算展示价
}

async function saveContract() {
  const payload = {
    type: props.type, project_name: fm.project_name.trim(), party: fm.party,
    amount: Number(fm.amount) || 0, sign_date: fm.sign_date, start_date: fm.start_date,
    end_date: fm.end_date, remark: fm.remark.trim(),
  }
  if (isElev) { payload.elevator_count = Number(fm.count) || 0; payload.price_per_unit = Number(fm.price) || 0 }
  else { payload.building_area_sqm = Number(fm.area) || 0; payload.price_per_sqm = Number(fm.price) || 0 }
  try {
    if (editId.value) await api('/api/maintenance/contracts/' + editId.value, { method: 'PUT', body: payload })
    else await api('/api/maintenance/contracts', { body: payload })
    modalShow.value = false
    dataCache[props.type] = [] // 强制刷新
    toast('已保存')
    loaded.value = false
    load()
  } catch (e) { toast('保存失败：' + e.message, false) }
}

async function del(r) {
  if (!confirm(`确认删除合同「${r.project_name} / ${r.party}」？\n删除后对应 PDF 附件也会删除，不可恢复。`)) return
  try {
    await api('/api/maintenance/contracts/' + r.id, { method: 'DELETE' })
    dataCache[props.type] = []
    toast('已删除')
    loaded.value = false
    load()
  } catch (e) { toast('删除失败：' + e.message, false) }
}

/* ---------- PDF 上传/预览/下载（X-Token 鉴权，直连 fetch） ---------- */
async function uploadPdf(id, e) {
  const file = e.target.files[0]
  e.target.value = ''
  if (!file) return
  try {
    const res = await fetch(`/api/maintenance/contracts/${id}/pdf`, { method: 'POST', headers: { 'Content-Type': 'application/pdf', 'X-Token': auth.token }, body: file })
    if (!res.ok) throw new Error('上传失败')
    dataCache[props.type] = []
    toast('PDF 已上传')
    loaded.value = false
    load()
  } catch (err) { toast('PDF 上传失败：' + err.message, false) }
}

async function viewPdf(id) {
  try {
    const res = await fetch(`/api/maintenance/contracts/${id}/pdf`, { headers: { 'X-Token': auth.token } })
    if (!res.ok) throw new Error('加载失败')
    const blob = await res.blob()
    const url = URL.createObjectURL(blob)
    window.open(url, '_blank')
    setTimeout(() => URL.revokeObjectURL(url), 60000)
  } catch (e) { toast('PDF 预览失败：' + e.message, false) }
}

async function downloadPdf(id) {
  try {
    const res = await fetch(`/api/maintenance/contracts/${id}/pdf?download=1`, { headers: { 'X-Token': auth.token } })
    if (!res.ok) throw new Error('下载失败')
    const blob = await res.blob()
    const cd = res.headers.get('Content-Disposition') || ''
    const m = cd.match(/filename="?([^"]+)"?/)
    const fname = m ? decodeURIComponent(m[1]) : 'contract.pdf'
    const a = document.createElement('a')
    a.href = URL.createObjectURL(blob); a.download = fname
    document.body.appendChild(a); a.click(); a.remove()
  } catch (e) { toast('PDF 下载失败：' + e.message, false) }
}

/* ---------- 导出CSV（复刻 maintExportLedger：仅应用关键词+年份筛选） ---------- */
function exportCsv() {
  const kw = filters.value.kw
  const yr = filters.value.year
  let list = dataCache[props.type].slice()
  if (kw) list = list.filter((r) => (r.project_name + r.party + r.remark).toLowerCase().includes(kw))
  if (yr) list = list.filter((r) => maintOverlapsYear(r.start_date, r.end_date, yr))
  if (!list.length) { toast('当前无数据可导出', false); return }
  const out = buildLedgerExportRows(list, isElev, (d) => STATUS_TEXT[maintStatusOf(d)])
  const csv = buildCsv(label + '维保台账.csv', out)
  const blob = new Blob([csv], { type: 'text/csv;charset=utf-8' })
  const a = document.createElement('a')
  a.href = URL.createObjectURL(blob); a.download = label + '维保台账.csv'
  document.body.appendChild(a); a.click(); a.remove()
}

onMounted(load)
</script>
