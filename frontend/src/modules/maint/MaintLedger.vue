<template>
  <div class="card">
    <h3>{{ label }}维保台账</h3>
    <div class="row" style="flex-wrap:wrap;gap:8px">
      <button class="btn primary sm" @click="openEdit(null)"><Icon name="plus" /> 新增合同</button>
      <input v-model="f.kw" placeholder="关键词(项目/签约方/备注)" style="width:160px">
      <select v-model="f.project" style="max-width:160px"><option value="">全部项目</option><option v-for="p in projectOpts" :key="p" :value="p">{{ p }}</option></select>
      <select v-model="f.status" style="width:130px"><option value="">全部状态</option><option v-for="s in MAINT_STATUS_OPTS" :key="s" :value="s">{{ MAINT_STATUS_LABELS[s] }}</option></select>
      <input v-model="f.efrom" type="date">
      <input v-model="f.eto" type="date">
      <input v-model="f.party" placeholder="签约方" style="width:110px">
      <input v-model="f.amin" type="number" placeholder="金额下限" style="width:90px">
      <input v-model="f.amax" type="number" placeholder="金额上限" style="width:90px">
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
      <button class="btn sm" @click="exportXlsx">导出Excel</button>
      <button class="btn sm" @click="clearFilters">清除筛选</button>
    </div>
    <div class="table-wrap" style="margin-top:10px"><table class="tb">
      <thead><tr><th>项目名称</th><th>签约方</th><th>签约金额</th><th>签订日期</th><th>生效开始</th><th>到期日期</th>
      <template v-if="isElev"><th>电梯台数</th><th>每台单价</th></template><template v-else><th>建筑面积(㎡)</th><th>每㎡单价</th></template>
      <th>合同状态</th><th>备注</th><th>PDF扫描件</th><th>操作</th></tr></thead>
      <tbody>
        <tr v-if="loadErr"><td colspan="12" class="msg err">{{ loadErr }}</td></tr>
        <tr v-else-if="!loaded"><td colspan="12">加载中...</td></tr>
        <tr v-else-if="!rows.length"><td colspan="12" style="text-align:center;color:#94a3b8;padding:24px">无匹配合同记录</td></tr>
        <tr v-for="r in rows" :key="r.id">
          <td>{{ r.project_name }}</td><td>{{ r.party }}</td><td class="num">{{ moneyOrDash(r.amount) }}</td>
          <td>{{ r.sign_date || '' }}</td><td>{{ r.start_date || '' }}</td><td>{{ r.end_date || '' }}</td>
          <template v-if="isElev"><td>{{ fmtEmpty(r.elevator_count) }}</td><td>{{ r.price_per_unit ? moneyOrDash(r.price_per_unit) : '-' }}</td></template>
          <template v-else><td>{{ r.building_area_sqm || '-' }}</td><td>{{ r.price_per_sqm ? moneyOrDash(r.price_per_sqm) : '-' }}</td></template>
          <td><span :class="badgeOf(r).cls">{{ badgeOf(r).text }}</span></td>
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

  <MaintModal :title="(editId ? '编辑' : '新增') + label + '维保合同'" :show="modalShow" :wide="!!editId" :show-save="tab === 'info'" @close="modalShow = false" @save="saveContract">
    <div v-if="editId" style="display:flex;gap:6px;margin-bottom:14px;border-bottom:1px solid var(--divider);padding-bottom:10px">
      <button v-for="t in TABS" :key="t.key" class="btn sm" :class="{ primary: tab === t.key }" @click="tab = t.key">{{ t.label }}</button>
    </div>

    <div v-show="tab === 'info'" style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
      <div><label>所属项目名称 <span style="color:#ef4444">*</span></label><input v-model="fm.project_name" placeholder="如：盈科国际广场"></div>
      <div><label>合同编号</label><input v-model="fm.contract_no" placeholder="如：HX-2026-001"></div>
      <div style="grid-column:1/3"><label>签约方（{{ label }}维保单位）</label><select v-model="fm.party">
        <option value="">-- 请选择签约方 --</option>
        <option v-for="p in partnerOpts" :key="p.name" :value="p.name">{{ p.name }}{{ p.type !== 'both' ? ' (' + (p.type === 'fire' ? '消防' : '电梯') + ')' : '' }}</option>
      </select>
        <div style="font-size:11px;color:#94a3b8;margin-top:2px">可在「签约方维护」中管理维保单位列表</div></div>
      <div><label>签约主体（甲方）</label><input v-model="fm.party_a" placeholder="甲方单位全称"></div>
      <div><label>合同签约金额（元） <span style="color:#ef4444">*</span></label><input v-model="fm.amount" type="number" placeholder="0"></div>
      <div><label>乙方负责人</label><input v-model="fm.party_contact"></div>
      <div><label>乙方联系电话</label><input v-model="fm.party_phone"></div>
      <template v-if="isElev">
        <div><label>电梯维保总台数 <span style="color:#ef4444">*</span></label><input v-model="fm.count" type="number"></div>
        <div><label>每台单价（元）</label><input v-model="fm.price" type="number" title="自动计算：合同签约金额 ÷ 电梯总台数；也可手动修改覆盖" @input="onPriceInput"></div>
      </template>
      <template v-else>
        <div><label>项目建筑面积（㎡） <span style="color:#ef4444">*</span></label><input v-model="fm.area" type="number"></div>
        <div><label>每平方米单价（元/㎡）</label><input v-model="fm.price" type="number" title="自动计算：合同签约金额 ÷ 项目建筑面积；也可手动修改覆盖" @input="onPriceInput"></div>
      </template>
      <div><label>合同签订日期</label><input v-model="fm.sign_date" type="date"></div>
      <div><label>合同生效开始日期</label><input v-model="fm.start_date" type="date"></div>
      <div><label>合同到期日期</label><input v-model="fm.end_date" type="date"><div style="font-size:11px;color:#94a3b8;margin-top:2px">不填将列为「无到期日」异常</div></div>
      <div style="display:flex;align-items:flex-end;padding-bottom:6px"><label style="display:flex;align-items:center;gap:6px;cursor:pointer"><input type="checkbox" v-model="fm.includes_supplies" style="width:auto"> 含配件耗材</label></div>
      <div style="grid-column:1/3"><label>维保服务内容</label><textarea v-model="fm.service_scope" rows="2" placeholder="维保范围、频次、响应时效等"></textarea></div>
      <div style="grid-column:1/3"><label>特殊要求 / 备注</label><textarea v-model="fm.remark" rows="2"></textarea></div>
    </div>

    <!-- 附件管理 -->
    <div v-show="tab === 'atts'">
      <div class="row" style="gap:8px;align-items:center;flex-wrap:wrap">
        <select v-model="attKind" style="width:130px"><option v-for="(v, k) in ATT_KINDS" :key="k" :value="k">{{ v }}</option></select>
        <label class="btn sm primary" style="cursor:pointer" for="matt_file">选择并上传</label>
        <input id="matt_file" type="file" style="display:none" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx,.zip" @change="uploadAtt">
        <span style="font-size:11.5px;color:#94a3b8">支持 pdf/图片/word/excel/zip，单个 ≤50MB</span>
      </div>
      <div class="table-wrap" style="margin-top:10px"><table class="tb">
        <thead><tr><th>分类</th><th>文件名</th><th>大小</th><th>上传人</th><th>上传时间</th><th>操作</th></tr></thead>
        <tbody>
          <tr v-if="!atts.length"><td colspan="6" style="text-align:center;color:#94a3b8;padding:18px">暂无附件</td></tr>
          <tr v-for="a in atts" :key="a.id">
            <td><span class="tag blue">{{ a.kind_label }}</span></td><td>{{ a.name }}</td><td>{{ sizeFmt(a.size) }}</td>
            <td>{{ a.uploaded_by || '-' }}</td><td>{{ fmtTime(a.created_at) }}</td>
            <td><button class="btn sm" @click="downloadAtt(a)">下载</button> <button class="btn sm danger" @click="delAtt(a)">删除</button></td>
          </tr>
        </tbody>
      </table></div>
    </div>

    <!-- 履约记录 -->
    <div v-show="tab === 'records'">
      <div style="display:grid;grid-template-columns:130px 110px 1fr;gap:8px;align-items:end;background:var(--bg-soft,#f8fafc);padding:10px;border-radius:8px">
        <div><label>日期</label><input v-model="recFm.record_date" type="date"></div>
        <div><label>类型</label><select v-model="recFm.kind"><option v-for="(v, k) in RECORD_KINDS" :key="k" :value="k">{{ v }}</option></select></div>
        <div><label>内容 <span style="color:#ef4444">*</span></label><input v-model="recFm.content" placeholder="如：完成季度维保，更换 2 个烟感"></div>
        <div style="grid-column:1/3"><label>处理结果</label><input v-model="recFm.result" placeholder="如：设备恢复正常"></div>
        <div style="text-align:right"><button class="btn sm primary" @click="saveRecord">{{ recEditId ? '保存修改' : '添加记录' }}</button> <button v-if="recEditId" class="btn sm" @click="resetRecFm">取消编辑</button></div>
      </div>
      <div class="table-wrap" style="margin-top:10px"><table class="tb">
        <thead><tr><th>日期</th><th>类型</th><th>内容</th><th>处理结果</th><th>附件</th><th>操作</th></tr></thead>
        <tbody>
          <tr v-if="!records.length"><td colspan="6" style="text-align:center;color:#94a3b8;padding:18px">暂无履约记录</td></tr>
          <tr v-for="r in records" :key="r.id">
            <td>{{ r.record_date }}</td><td><span class="tag" :class="r.kind === 'rectify' ? 'orange' : r.kind === 'inspection' ? 'purple' : 'green'">{{ r.kind_label }}</span></td>
            <td style="max-width:220px;white-space:normal">{{ r.content }}</td><td style="max-width:160px;white-space:normal">{{ r.result || '-' }}</td>
            <td>
              <div v-for="a in r.attachments" :key="a.id" style="white-space:nowrap">
                <a href="javascript:;" style="color:var(--brand-700,#1d4ed8)" @click="downloadAtt(a)">{{ a.name }}</a>
                <a href="javascript:;" style="color:#dc2626;margin-left:4px" @click="delAtt(a, true)">×</a>
              </div>
              <label class="btn sm" style="cursor:pointer;margin-top:2px" :for="'ratt_' + r.id">传附件</label>
              <input :id="'ratt_' + r.id" type="file" style="display:none" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx,.zip" @change="uploadRecAtt(r.id, $event)">
            </td>
            <td><button class="btn sm" @click="editRecord(r)">编辑</button> <button class="btn sm danger" @click="delRecord(r)">删除</button></td>
          </tr>
        </tbody>
      </table></div>
    </div>

    <!-- 变更记录 -->
    <div v-show="tab === 'logs'">
      <div v-if="!logs.length" style="text-align:center;color:#94a3b8;padding:24px">暂无变更记录</div>
      <div v-for="l in logs" :key="l.id" style="border-bottom:1px dashed var(--divider);padding:8px 2px">
        <div style="font-size:12.5px;color:var(--text-2)">
          <b>{{ l.account || '系统' }}</b> {{ l.action === 'create' ? '创建了合同' : '修改了合同' }} · {{ fmtTime(l.time) }}
        </div>
        <div v-for="(c, fname) in l.changes" :key="fname" style="font-size:12.5px;margin-top:2px">
          <span class="tag gray">{{ fname }}</span> <span style="color:#94a3b8;text-decoration:line-through">{{ c.old }}</span> → <span style="color:var(--text-1)">{{ c.new }}</span>
        </div>
      </div>
    </div>
  </MaintModal>
</template>

<script>
// 模块级合同缓存 — 复刻 MAINT.data（跨路由访问不重复拉取，变更后清空强制刷新）
const dataCache = { fire: [], elevator: [] }
</script>

<script setup>
// 消防/电梯维保台账 — 复刻 pageMaintLedger 群（app.js:2841-3105）+ maintContractEdit 弹窗（2975-3040）
// 升级版：6 档状态、项目/状态快捷筛选、新合同字段、多附件、履约记录、变更留痕、服务端 xlsx 导出
import { reactive, ref, computed, watch, onMounted } from 'vue'
import { api, download } from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import { toast } from '@/utils/toast'
import { maintStatusOf, filterLedger, priceAutoCalc, MAINT_STATUS_LABELS, MAINT_STATUS_TAG, MAINT_STATUS_OPTS } from './maintLogic'
import { moneyOrDash, fmtEmpty } from '@/utils/format'
import MaintModal from './MaintModal.vue'
import Icon from '@/components/Icon.vue'

const props = defineProps({ type: { type: String, default: 'fire' } })
const auth = useAuthStore()

// 消防/电梯台账复用同一组件，路由切换时组件实例被复用，必须响应式跟随 props.type
const isElev = computed(() => props.type === 'elevator')
const label = computed(() => (props.type === 'elevator' ? '电梯' : '消防'))
const yearOpts = Array.from({ length: 16 }, (_, i) => 2020 + i)
const TABS = [{ key: 'info', label: '合同信息' }, { key: 'atts', label: '附件管理' }, { key: 'records', label: '履约记录' }, { key: 'logs', label: '变更记录' }]
const ATT_KINDS = { contract: '合同正文', supplement: '补充协议', inspection: '年检报告', qualification: '资质证书', other: '其他' }
const RECORD_KINDS = { maintain: '维保', rectify: '整改', inspection: '年检' }

const loaded = ref(false)
const loadErr = ref('')
const f = reactive({ kw: '', project: '', status: '', efrom: '', eto: '', party: '', amin: '', amax: '', emin: '', emax: '', year: '', sortField: 'end_date' })
const sort = reactive({ dir: 'asc' }) // 旧版 MAINT.sort 默认升序

// 筛选归一化 — 复刻 maintGetFilters（kw/party 小写、金额台数转 Number 或 null）
const filters = computed(() => ({
  kw: (f.kw || '').trim().toLowerCase(),
  project: f.project || '',
  status: f.status || '',
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
// 项目下拉选项来自当前台账数据去重
const projectOpts = computed(() => [...new Set(dataCache[props.type].map((r) => r.project_name).filter(Boolean))].sort((a, b) => a.localeCompare(b, 'zh')))

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

function refresh() { dataCache[props.type] = []; loaded.value = false; load() }
function toggleDir() { sort.dir = sort.dir === 'asc' ? 'desc' : 'asc' }

// 旧版「清除筛选」仅重置 focus 聚焦（focus 仅由已下线的驾驶舱设置），常规筛选输入保留
function clearFilters() {}

// 状态徽章：优先用后端下发的分级（status/status_label），缺失时前端现算兜底
function badgeOf(r) {
  const st = r.status || maintStatusOf(r.end_date)
  return { cls: MAINT_STATUS_TAG[st] || 'tag green', text: r.status_label || MAINT_STATUS_LABELS[st] || st }
}

/* ---------- 合同编辑弹窗 ---------- */
const modalShow = ref(false)
const editId = ref(null)
const tab = ref('info')
const partners = ref([])
const partnerOpts = computed(() => partners.value.filter((p) => p.type === 'both' || p.type === props.type))
const fm = reactive({ project_name: '', contract_no: '', party: '', party_a: '', party_contact: '', party_phone: '', amount: '', count: '', area: '', price: '', sign_date: '', start_date: '', end_date: '', service_scope: '', includes_supplies: false, remark: '' })

async function loadPartners() {
  try { const r = await api('/api/maintenance/partners'); partners.value = r.partners || [] } catch (e) { partners.value = [] }
}

// 单价自动计算联动（复刻 manual/auto 标志逻辑：金额或台数/面积变更时自动算，用户手动改过单价后不再覆盖）
let priceManual = false
let autoFlag = false
function onPriceInput() { if (!autoFlag) priceManual = true }
function calcPrice() {
  if (priceManual) return
  fm.price = priceAutoCalc(fm.amount, isElev.value ? fm.count : fm.area, isElev.value)
}
watch(() => [fm.amount, isElev.value ? fm.count : fm.area], () => { autoFlag = true; calcPrice(); autoFlag = false })

async function openEdit(id) {
  await loadPartners()
  editId.value = id
  tab.value = 'info'
  const row = id ? dataCache[props.type].find((r) => r.id === id) : null
  fm.project_name = row ? (row.project_name ?? '') : ''
  fm.contract_no = row ? (row.contract_no ?? '') : ''
  fm.party = row ? (row.party ?? '') : ''
  fm.party_a = row ? (row.party_a ?? '') : ''
  fm.party_contact = row ? (row.party_contact ?? '') : ''
  fm.party_phone = row ? (row.party_phone ?? '') : ''
  fm.amount = row ? (row.amount ?? '') : ''
  if (isElev.value) fm.count = row ? (row.elevator_count ?? '') : ''
  else fm.area = row ? (row.building_area_sqm ?? '') : ''
  fm.price = row ? (row.price_per_unit ?? row.price_per_sqm ?? '') : ''
  fm.sign_date = row ? (row.sign_date ?? '') : ''
  fm.start_date = row ? (row.start_date ?? '') : ''
  fm.end_date = row ? (row.end_date ?? '') : ''
  fm.service_scope = row ? (row.service_scope ?? '') : ''
  fm.includes_supplies = !!(row && Number(row.includes_supplies))
  fm.remark = row ? (row.remark ?? '') : ''
  priceManual = false
  modalShow.value = true
  calcPrice() // 旧版弹窗打开即按金额÷台数/面积重算展示价
  if (id) { loadAtts(); loadRecords(); loadLogs() }
}

async function saveContract() {
  const payload = {
    type: props.type, project_name: fm.project_name.trim(), contract_no: fm.contract_no.trim(),
    party: fm.party, party_a: fm.party_a.trim(), party_contact: fm.party_contact.trim(), party_phone: fm.party_phone.trim(),
    amount: Number(fm.amount) || 0, sign_date: fm.sign_date, start_date: fm.start_date,
    end_date: fm.end_date, service_scope: fm.service_scope.trim(), includes_supplies: !!fm.includes_supplies, remark: fm.remark.trim(),
  }
  if (isElev.value) { payload.elevator_count = Number(fm.count) || 0; payload.price_per_unit = Number(fm.price) || 0 }
  else { payload.building_area_sqm = Number(fm.area) || 0; payload.price_per_sqm = Number(fm.price) || 0 }
  try {
    if (editId.value) await api('/api/maintenance/contracts/' + editId.value, { method: 'PUT', body: payload })
    else await api('/api/maintenance/contracts', { body: payload })
    modalShow.value = false
    toast('已保存')
    refresh()
  } catch (e) { toast('保存失败：' + e.message, false) }
}

async function del(r) {
  if (!confirm(`确认删除合同「${r.project_name} / ${r.party}」？\n删除后对应附件、履约记录、变更记录也会一并删除，不可恢复。`)) return
  try {
    await api('/api/maintenance/contracts/' + r.id, { method: 'DELETE' })
    toast('已删除')
    refresh()
  } catch (e) { toast('删除失败：' + e.message, false) }
}

/* ---------- 附件管理（合同级） ---------- */
const atts = ref([])
const attKind = ref('contract')

async function loadAtts() {
  try { const r = await api(`/api/maintenance/contracts/${editId.value}/attachments`); atts.value = r.attachments || [] } catch (e) { atts.value = [] }
}

async function uploadAtt(e) {
  const file = e.target.files[0]
  e.target.value = ''
  if (!file) return
  const fd = new FormData()
  fd.append('kind', attKind.value)
  fd.append('file', file)
  try {
    await api(`/api/maintenance/contracts/${editId.value}/attachments`, { form: fd })
    toast('附件已上传')
    loadAtts()
  } catch (err) { toast('附件上传失败：' + err.message, false) }
}

function downloadAtt(a) {
  download(`/api/maintenance/attachments/${a.id}/download`, a.name || '附件')
}

async function delAtt(a, fromRecord) {
  if (!confirm(`确认删除附件「${a.name}」？`)) return
  try {
    await api('/api/maintenance/attachments/' + a.id, { method: 'DELETE' })
    toast('已删除')
    if (fromRecord) loadRecords(); else loadAtts()
  } catch (e) { toast('删除失败：' + e.message, false) }
}

function sizeFmt(n) {
  n = Number(n) || 0
  return n >= 1048576 ? (n / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(n / 1024)) + ' KB'
}
function fmtTime(s) { return (s || '').slice(0, 16).replace('T', ' ') }

/* ---------- 履约记录 ---------- */
const records = ref([])
const recEditId = ref(null)
const recFm = reactive({ record_date: new Date().toISOString().slice(0, 10), kind: 'maintain', content: '', result: '' })

async function loadRecords() {
  try { const r = await api(`/api/maintenance/contracts/${editId.value}/records`); records.value = r.records || [] } catch (e) { records.value = [] }
}

function resetRecFm() {
  recEditId.value = null
  recFm.record_date = new Date().toISOString().slice(0, 10)
  recFm.kind = 'maintain'; recFm.content = ''; recFm.result = ''
}

function editRecord(r) {
  recEditId.value = r.id
  recFm.record_date = r.record_date; recFm.kind = r.kind; recFm.content = r.content; recFm.result = r.result || ''
}

async function saveRecord() {
  if (!recFm.content.trim()) { toast('请填写记录内容', false); return }
  const payload = { record_date: recFm.record_date, kind: recFm.kind, content: recFm.content.trim(), result: recFm.result.trim() }
  try {
    if (recEditId.value) await api('/api/maintenance/records/' + recEditId.value, { method: 'PUT', body: payload })
    else await api(`/api/maintenance/contracts/${editId.value}/records`, { body: payload })
    resetRecFm()
    toast('已保存')
    loadRecords()
  } catch (e) { toast('保存失败：' + e.message, false) }
}

async function delRecord(r) {
  if (!confirm(`确认删除 ${r.record_date} 的${r.kind_label}记录？其附件一并删除。`)) return
  try {
    await api('/api/maintenance/records/' + r.id, { method: 'DELETE' })
    toast('已删除')
    loadRecords()
  } catch (e) { toast('删除失败：' + e.message, false) }
}

async function uploadRecAtt(recordId, e) {
  const file = e.target.files[0]
  e.target.value = ''
  if (!file) return
  const fd = new FormData()
  fd.append('file', file)
  try {
    await api(`/api/maintenance/records/${recordId}/attachment`, { form: fd })
    toast('附件已上传')
    loadRecords()
  } catch (err) { toast('附件上传失败：' + err.message, false) }
}

/* ---------- 变更记录 ---------- */
const logs = ref([])
async function loadLogs() {
  try { const r = await api(`/api/maintenance/contracts/${editId.value}/logs`); logs.value = r.logs || [] } catch (e) { logs.value = [] }
}

/* ---------- PDF 上传/预览/下载（X-Token 鉴权，直连 fetch） ---------- */
async function uploadPdf(id, e) {
  const file = e.target.files[0]
  e.target.value = ''
  if (!file) return
  try {
    const res = await fetch(`/api/maintenance/contracts/${id}/pdf`, { method: 'POST', headers: { 'Content-Type': 'application/pdf', 'X-Token': auth.token }, body: file })
    if (!res.ok) throw new Error('上传失败')
    toast('PDF 已上传')
    refresh()
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

/* ---------- 导出 Excel（服务端 xlsx，含新字段与 6 档状态） ---------- */
function exportXlsx() {
  download(`/api/maintenance/contracts/export?type=${props.type}`, label.value + '维保台账.xlsx')
}

onMounted(load)
// 同一组件在 maintFire / maintElev 两条路由间复用时，切换后清空加载态并按新类型重新拉取
watch(() => props.type, (t) => {
  if (t !== 'elevator' && f.sortField === 'elevator_count') f.sortField = 'end_date'
  f.project = ''
  loadErr.value = ''
  loaded.value = false
  load()
})
</script>
