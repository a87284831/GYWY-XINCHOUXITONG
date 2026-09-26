<script setup>
// 采购填报·明细表单（复刻旧 #/fill-form：联想填商品/规格联动/近3月采购/本地草稿/保存与提交/退回重报）
import { ref, computed, watch, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api } from '@/api/client'
import { toast } from '@/utils/toast'
import { useAuthStore } from '@/stores/auth'
import { ElMessageBox } from 'element-plus'
import {
  FILL_LINES, newRow, isRowReadonly, canCopyRow, canRevokeRow, canDeleteRow,
  rowSaveable, validateRow, rowPayload, saveSummary, freqCell, draftKey,
} from './fillLogic'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const isAdmin = computed(() => !!auth.user && auth.user.role === 'admin')

const month = ref(String(route.query.month || new Date().toISOString().slice(0, 7)))
const projectId = ref(Number(route.query.project_id || 0)) // admin 代填项目
const win = ref(null)
const rows = ref([])
const loading = ref(false)
const saving = ref(false)
const defaultLine = ref(FILL_LINES[0])
let keySeq = 0

const monthText = computed(() => Number(month.value.slice(0, 4)) + ' 年 ' + Number(month.value.slice(5)) + ' 月')
const returnedCount = computed(() => rows.value.filter((r) => r.status === 'returned').length)
const savedCount = computed(() => rows.value.filter((r) => r.id).length)
const saveableCount = computed(() => rows.value.filter((r) => rowSaveable(r, isAdmin.value)).length)
const fillerName = computed(() => (auth.user && (auth.user.project_name || auth.user.name)) || '')

// —— 本地草稿（2 秒防抖，仅未入库行） ——
const DKEY = computed(() => draftKey(month.value, projectId.value))
let draftTimer = null
watch(rows, () => {
  if (loading.value) return
  clearTimeout(draftTimer)
  draftTimer = setTimeout(saveDraft, 2000)
}, { deep: true })

function saveDraft() {
  const list = rows.value.filter((r) => !r.id).map((r) => ({
    line: r.line, item_name: r.item_name, spec: r.spec, brand: r.brand, unit: r.unit,
    quantity: r.quantity, stock: r.stock, reason: r.reason, use_location: r.use_location,
    remark: r.remark, is_custom: r.is_custom, status: r.status, product_id: r.product_id,
  }))
  try {
    if (list.length) localStorage.setItem(DKEY.value, JSON.stringify(list))
    else localStorage.removeItem(DKEY.value)
  } catch (e) { /* 存储不可用忽略 */ }
}
function clearDraft() {
  try { localStorage.removeItem(DKEY.value) } catch (e) { /* 忽略 */ }
}

async function loadWindow() {
  try {
    win.value = (await api('/api/purchase/fill/window?month=' + month.value)).data
  } catch (e) { /* 窗口加载失败不阻断 */ }
}

async function refillSpecs(r) {
  try {
    const d = (await api('/api/purchase/products/by-name?line=' + encodeURIComponent(r.line || '') + '&name=' + encodeURIComponent(r.item_name))).data
    r.specs = d.specs || []
    r.brand = d.brand || ''
    r.unit = d.unit || ''
    if (r.spec) {
      const hit = r.specs.find((s) => s.spec === r.spec)
      if (hit) {
        r.product_id = hit.id
        r.unit = hit.unit || r.unit
        r.brand = hit.brand || r.brand
      } else {
        r.is_custom = true
        r.product_id = null
      }
    } else {
      r.product_id = null
    }
  } catch (e) { /* 联想失败按原样保留 */ }
}

async function loadRows() {
  loading.value = true
  try {
    const q = '?month=' + month.value + (isAdmin.value && projectId.value ? '&project_id=' + projectId.value : '')
    const res = await api('/api/purchase/fill/items' + q)
    rows.value = (res.data || []).map((n) => ({
      key: ++keySeq, id: n.id, line: n.line, item_name: n.item_name, spec: n.spec, brand: n.brand, unit: n.unit,
      quantity: Number(n.quantity), stock: Number(n.stock), reason: n.reason, use_location: n.use_location, remark: n.remark,
      is_custom: n.is_custom == 1, status: n.status, product_id: n.product_id || null, price: n.price,
      return_reason: n.return_reason || '', returned_at: n.returned_at || '',
      nameOptions: [], specs: [], freq: null, loadingName: false, _isNew: false,
    }))
    // 本地草稿恢复：仅当服务端无草稿行（避免与已存草稿重复）
    let restored = 0
    try {
      const raw = localStorage.getItem(DKEY.value)
      if (raw && !rows.value.some((n) => n.status === 'draft')) {
        const list = JSON.parse(raw)
        if (Array.isArray(list) && list.length) {
          list.forEach((m) => {
            const r = Object.assign(newRow(++keySeq, defaultLine.value), m)
            r.key = ++keySeq
            rows.value.push(r)
          })
          restored = list.length
        }
      }
    } catch (e) { /* 草稿损坏忽略 */ }
    if (restored > 0) {
      for (const r of rows.value.filter((l) => l.item_name && !l.is_custom && (!l.specs || !l.specs.length))) {
        await refillSpecs(r)
      }
      toast('已从本地草稿恢复 ' + restored + ' 行，请核对后保存')
    }
    if (!rows.value.length) rows.value.push(newRow(++keySeq, defaultLine.value))
  } catch (e) {
    toast(e.message, false)
  } finally {
    loading.value = false
  }
}

// —— 商品联想与联动 ——
async function searchNames(row, kw, cb) {
  if (!kw || !kw.trim()) { row.nameOptions = []; cb([]); return }
  row.loadingName = true
  try {
    const list = (await api('/api/purchase/products/search?keyword=' + encodeURIComponent(kw.trim()))).data || []
    const names = []
    list.forEach((p) => { if ((!row.line || p.line === row.line) && !names.includes(p.name)) names.push(p.name) })
    row.nameOptions = names.slice(0, 20).map((n) => ({ value: n }))
  } catch (e) {
    row.nameOptions = []
  } finally {
    row.loadingName = false
  }
  cb(row.nameOptions)
}

async function loadFreq(r) {
  r.freq = null
  if (!r.item_name || r.is_custom) return
  try {
    const qs = '?product_id=' + (r.product_id || 0) +
      '&item_name=' + encodeURIComponent(r.item_name) +
      '&spec=' + encodeURIComponent(r.spec || '') +
      '&unit=' + encodeURIComponent(r.unit || '') +
      '&line=' + encodeURIComponent(r.line || '') +
      '&project_id=' + (projectId.value || 0) +
      '&month=' + month.value
    r.freq = (await api('/api/purchase/fill/frequency' + qs)).data
  } catch (e) { /* 频次失败静默 */ }
}

async function selectName(r, name) {
  r.spec = ''; r.brand = ''; r.unit = ''; r.specs = []; r.freq = null; r.product_id = null
  if (!name) return
  try {
    const d = (await api('/api/purchase/products/by-name?line=' + encodeURIComponent(r.line || '') + '&name=' + encodeURIComponent(name))).data
    r.specs = d.specs || []
    r.brand = d.brand || ''
    r.unit = d.unit || ''
    if (r.specs.length === 1) {
      r.spec = r.specs[0].spec
      r.unit = r.specs[0].unit || r.unit
      r.brand = r.specs[0].brand || r.brand
      r.product_id = r.specs[0].id
      loadFreq(r)
    }
  } catch (e) { /* 联想失败静默 */ }
}

function selectSpec(r) {
  const hit = (r.specs || []).find((s) => s.spec === r.spec)
  if (hit) {
    r.unit = hit.unit || r.unit
    r.brand = hit.brand || r.brand
    r.product_id = hit.id
  }
  loadFreq(r)
}

// 切条线/切清单外：重置商品绑定字段（忠实旧版 de/pe）
function resetProduct(r) {
  r.item_name = ''; r.spec = ''; r.brand = ''; r.unit = ''
  r.nameOptions = []; r.specs = []; r.freq = null; r.product_id = null
}

// —— 行操作 ——
function addRow() {
  rows.value.push(newRow(++keySeq, defaultLine.value))
}
function copyRow(r) {
  const t = newRow(++keySeq, '')
  t.line = r.line
  t.is_custom = r.is_custom
  t.item_name = r.item_name
  t.spec = r.spec
  t.brand = r.brand
  t.unit = r.unit
  if (!r.is_custom) {
    t.product_id = r.product_id
    t.specs = (r.specs || []).map((s) => ({ ...s }))
    t.freq = r.freq ? { ...r.freq } : null
  }
  t.quantity = 0
  t.stock = r.stock
  t.reason = r.reason
  t.use_location = r.use_location
  rows.value.push(t)
  toast('已复制为一行，请修改数量后保存')
}
async function deleteRow(r) {
  if (r.id) {
    try {
      await ElMessageBox.confirm('确定删除「' + r.item_name + '」这条记录吗？', '删除确认', { type: 'warning' })
    } catch (e) { return }
    try {
      await api('/api/purchase/fill/items/' + r.id, { method: 'DELETE' })
      toast('已删除')
      await loadRows()
    } catch (e) {
      toast(e.message, false)
    }
  } else {
    rows.value = rows.value.filter((t) => t.key !== r.key)
  }
}
async function revokeRow(r) {
  try {
    await api('/api/purchase/fill/items/' + r.id, { method: 'PUT', body: { status: 'draft' } })
    toast('已撤销提交，可继续修改')
    await loadRows()
  } catch (e) {
    toast(e.message, false)
  }
}

async function saveAll(submit) {
  saving.value = true
  let saved = 0
  let failed = 0
  try {
    for (const r of rows.value) {
      if (!rowSaveable(r, isAdmin.value)) continue
      const err = validateRow(r, rows.value.indexOf(r) + 1)
      if (err) {
        toast(err, false)
        failed++
        continue
      }
      const payload = rowPayload(r, month.value, projectId.value, isAdmin.value, submit)
      if (r.id) await api('/api/purchase/fill/items/' + r.id, { method: 'PUT', body: payload })
      else await api('/api/purchase/fill/items', { body: payload })
      saved++
    }
    const s = saveSummary(saved, failed, submit)
    toast(s.text, s.type === 'success')
    clearDraft()
    await loadRows()
  } catch (e) {
    toast(e.message, false)
  } finally {
    saving.value = false
  }
}

onMounted(async () => {
  await Promise.all([loadWindow(), loadRows()])
})
</script>

<template>
  <div>
    <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
      <el-button @click="router.push('/purchase/fill')">返回</el-button>
      <div style="font-size:17px;font-weight:700;">{{ monthText }} · 采购需求填报</div>
      <el-tag size="small" :type="win && win.open ? 'success' : 'danger'">{{ win && win.open ? '填报开放中' : '填报未开放' }}</el-tag>
      <span style="font-size:12.5px;color:#94a3b8;">
        填报人为：{{ fillerName }}
        （窗口：{{ win && win.window ? win.window.start_date + ' 至 ' + win.window.end_date : '未配置' }}）
      </span>
    </div>
    <div style="font-size:12px;color:#94a3b8;margin:6px 0 12px;">每月 20-23 日为填报窗口，逾期截止</div>

    <el-alert v-if="returnedCount > 0" type="warning" :closable="false" show-icon style="margin-bottom:12px;"
      :title="'招采已退回 ' + returnedCount + ' 条记录要求修改，请查看红色行中的退回原因，修改后点击「提交全部」重新提交'" />

    <div class="dash-card" style="padding:12px 14px;margin-bottom:12px;">
      <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
        <div style="font-weight:600;color:#2b5a9e;font-size:14px;">批量填写</div>
        <span style="color:#666;font-size:13px;">默认条线：</span>
        <el-select v-model="defaultLine" style="width:120px">
          <el-option v-for="l in FILL_LINES" :key="l" :value="l" :label="l" />
        </el-select>
        <span style="color:#999;font-size:12px;">新行自动沿用，行内可单独修改</span>
        <div style="flex:1 1 auto"></div>
        <el-button type="primary" :loading="saving" @click="saveAll(false)">保存全部 {{ saveableCount }}</el-button>
        <el-button type="success" :loading="saving" @click="saveAll(true)">提交全部 {{ saveableCount }}</el-button>
        <el-button @click="addRow">新增一行</el-button>
        <el-button @click="rows = [Object.assign(newRow(++keySeq, defaultLine.value))]">重置</el-button>
      </div>
      <div style="margin-top:8px;color:#999;font-size:12px;">
        提示：输入商品名称时直接打字即联想；选中后自动带出规格/品牌/单位并显示近3月采购；一行填完按回车新增下一行；清单外商品在行内打开开关直接手填。
      </div>
    </div>

    <el-table v-loading="loading" :data="rows" border stripe size="small" style="width:100%" max-height="640"
      :row-class-name="({ row }) => (row.status === 'returned' ? 'row-returned' : '')">
      <el-table-column label="条线" width="100">
        <template #default="{ row }">
          <el-select v-model="row.line" size="small" :disabled="isRowReadonly(row, isAdmin)" @change="resetProduct(row)">
            <el-option v-for="l in FILL_LINES" :key="l" :value="l" :label="l" />
          </el-select>
        </template>
      </el-table-column>
      <el-table-column label="商品名称" min-width="180">
        <template #default="{ row }">
          <el-autocomplete v-if="!row.is_custom" v-model="row.item_name" size="small" style="width:100%"
            :fetch-suggestions="(q, cb) => searchNames(row, q, cb)" :trigger-on-focus="false"
            placeholder="输入名称即联想" :disabled="isRowReadonly(row, isAdmin)"
            @select="(item) => selectName(row, item.value)" />
          <el-input v-else v-model="row.item_name" size="small" placeholder="清单外商品名称" :disabled="isRowReadonly(row, isAdmin)" />
        </template>
      </el-table-column>
      <el-table-column label="规格型号" width="150">
        <template #default="{ row }">
          <el-select v-if="!row.is_custom" v-model="row.spec" size="small" style="width:100%" placeholder="选择规格"
            :disabled="isRowReadonly(row, isAdmin)" @change="selectSpec(row)">
            <el-option v-for="s in row.specs" :key="s.id" :value="s.spec" :label="s.spec" />
          </el-select>
          <el-input v-else v-model="row.spec" size="small" placeholder="规格（选填）" :disabled="isRowReadonly(row, isAdmin)" />
        </template>
      </el-table-column>
      <el-table-column label="品牌" width="120">
        <template #default="{ row }">
          <el-input v-model="row.brand" size="small" :placeholder="row.is_custom ? '品牌（选填）' : '清单内自动'" :disabled="isRowReadonly(row, isAdmin)" />
        </template>
      </el-table-column>
      <el-table-column label="单位" width="90">
        <template #default="{ row }">
          <el-input v-model="row.unit" size="small" placeholder="如箱/个" :disabled="isRowReadonly(row, isAdmin)" />
        </template>
      </el-table-column>
      <el-table-column label="数量" width="100">
        <template #default="{ row }">
          <el-input-number v-model="row.quantity" size="small" style="width:100%" :min="0" :controls="false" :disabled="isRowReadonly(row, isAdmin)" />
        </template>
      </el-table-column>
      <el-table-column label="库存" width="100">
        <template #default="{ row }">
          <el-input-number v-model="row.stock" size="small" style="width:100%" :min="0" :controls="false" :disabled="isRowReadonly(row, isAdmin)" />
        </template>
      </el-table-column>
      <el-table-column label="申购原因" min-width="130">
        <template #default="{ row }">
          <el-input v-model="row.reason" size="small" placeholder="选填" :disabled="isRowReadonly(row, isAdmin)" />
        </template>
      </el-table-column>
      <el-table-column label="使用位置" min-width="110">
        <template #default="{ row }">
          <el-input v-model="row.use_location" size="small" placeholder="工程类填" :disabled="isRowReadonly(row, isAdmin)" />
        </template>
      </el-table-column>
      <el-table-column label="近3月采购" width="130">
        <template #default="{ row }">
          <template v-if="freqCell(row.freq).kind === 'ok'">
            <el-tooltip :content="freqCell(row.freq).detail.join('\n')" placement="top">
              <el-tag size="small" type="warning" effect="light">{{ freqCell(row.freq).text }}</el-tag>
            </el-tooltip>
          </template>
          <span v-else style="color:#ccc;font-size:12px;">{{ freqCell(row.freq).text }}</span>
        </template>
      </el-table-column>
      <el-table-column label="清单外" width="66" align="center">
        <template #default="{ row }">
          <el-switch v-model="row.is_custom" :disabled="isRowReadonly(row, isAdmin)" @change="resetProduct(row)" />
        </template>
      </el-table-column>
      <el-table-column label="状态" width="96" align="center">
        <template #default="{ row }">
          <el-tag v-if="row.status === 'confirmed'" size="small" type="success">已确认</el-tag>
          <el-tag v-else-if="row.status === 'submitted'" size="small" type="primary">已提交</el-tag>
          <el-tag v-else-if="row.status === 'returned'" size="small" type="danger">
            <el-tooltip :content="row.return_reason || '请修改后重新提交'" placement="top"><span>已退回</span></el-tooltip>
          </el-tag>
          <el-tag v-else-if="row.status === 'draft'" size="small" type="info">草稿</el-tag>
          <span v-else style="color:#999;font-size:12px;">新</span>
        </template>
      </el-table-column>
      <el-table-column label="单价(参考)" width="92">
        <template #default="{ row }">
          <span v-if="row.price > 0" style="color:#f59e0b;">¥{{ Number(row.price).toFixed(2) }}</span>
          <span v-else style="color:#ccc;font-size:12px;">待报价</span>
        </template>
      </el-table-column>
      <el-table-column label="操作" width="108" align="center">
        <template #default="{ row }">
          <el-button v-if="canCopyRow(row)" link type="primary" size="small" @click="copyRow(row)">复制</el-button>
          <el-button v-if="canRevokeRow(row)" link type="warning" size="small" @click="revokeRow(row)">撤销</el-button>
          <el-button v-if="canDeleteRow(row, isAdmin)" link type="danger" size="small" @click="deleteRow(row)">删除</el-button>
        </template>
      </el-table-column>
    </el-table>

    <div style="padding:8px 14px;color:#999;font-size:12px;display:flex;justify-content:space-between;flex-wrap:wrap;gap:6px;">
      <span>共 {{ rows.length }} 行（已存 {{ savedCount }} 条）</span>
      <span>输入内容 2 秒后自动存为本地草稿，防止误关丢失；提交后不可再修改，招采确认前可撤销提交；被退回的行红色标出，修改后重新提交</span>
    </div>
  </div>
</template>

<style scoped>
:deep(.row-returned) { background: #fdf0f0 !important; }
:deep(.row-returned:hover > td) { background: #fbe6e6 !important; }
</style>
