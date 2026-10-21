<script setup>
// 标准商品库管理（复刻旧 #/products：筛选分页/新增编辑/别名/模板导入/未绑定提示；全部 admin-only）
import { ref, reactive, computed, onMounted } from 'vue'
import { api, download } from '@/api/client'
import { toast } from '@/utils/toast'
import { moneyOrDash } from '@/utils/format'
import { ElMessageBox } from 'element-plus'
import { FILL_LINES } from './fillLogic'
import { aliasList, unboundSummary } from './summaryLogic'

const lines = ref('')
const keyword = ref('')
const rows = ref([])
const total = ref(0)
const page = ref(1)
const pageSize = ref(20)
const loading = ref(false)

const unbound = ref(null)
const showUnbound = ref(false)

// 编辑/新增弹窗
const editVisible = ref(false)
const editForm = reactive({ id: null, line: '环境', category: '', name: '', brand: '', spec: '', unit: '' })

// 别名弹窗
const aliasVisible = ref(false)
const aliasProduct = ref(null)
const aliasRows = ref([])
const aliasInput = ref('')

// 批量导入弹窗
const importVisible = ref(false)
const importFile = ref(null)
const importing = ref(false)
const importResult = ref(null)

async function load() {
  loading.value = true
  try {
    const qs = '?page=' + page.value + '&page_size=' + pageSize.value +
      (lines.value ? '&line=' + encodeURIComponent(lines.value) : '') +
      (keyword.value.trim() ? '&keyword=' + encodeURIComponent(keyword.value.trim()) : '')
    const res = await api('/api/purchase/products' + qs)
    rows.value = res.data || []
    total.value = res.total || 0
  } catch (e) {
    toast(e.message, false)
  } finally {
    loading.value = false
  }
}

function search() {
  page.value = 1
  load()
}

async function loadUnbound() {
  try {
    unbound.value = (await api('/api/purchase/products/unbound')).data
  } catch (e) { /* 提示条失败不阻断 */ }
}
const ub = computed(() => unboundSummary(unbound.value))

function openCreate() {
  Object.assign(editForm, { id: null, line: '环境', category: '', name: '', brand: '', spec: '', unit: '' })
  editVisible.value = true
}
function openEdit(r) {
  Object.assign(editForm, { id: r.id, line: r.line, category: r.category, name: r.name, brand: r.brand, spec: r.spec, unit: r.unit })
  editVisible.value = true
}
async function saveEdit() {
  if (!editForm.line || !editForm.name) { toast('条线和商品名称必填', false); return }
  if (!editForm.spec) { toast('规格型号必填', false); return }
  if (!editForm.unit) { toast('单位必填', false); return }
  try {
    if (editForm.id) {
      await api('/api/purchase/products/' + editForm.id, { method: 'PUT', body: { ...editForm } })
    } else {
      await api('/api/purchase/products', { body: { ...editForm } })
    }
    toast('已保存')
    editVisible.value = false
    load()
  } catch (e) {
    toast(e.message, false)
  }
}

async function removeProduct(r) {
  try {
    await ElMessageBox.confirm('确定删除「' + r.name + '（' + r.spec + '）」吗？', '删除确认', { type: 'warning' })
  } catch (e) { return }
  try {
    await api('/api/purchase/products/' + r.id, { method: 'DELETE' })
    toast('已删除')
    load()
  } catch (e) {
    toast(e.message, false)
  }
}

async function openAlias(r) {
  aliasProduct.value = r
  aliasInput.value = ''
  try {
    aliasRows.value = (await api('/api/purchase/products/' + r.id + '/synonyms')).data || []
    aliasVisible.value = true
  } catch (e) {
    toast(e.message, false)
  }
}
async function addAlias() {
  if (!aliasInput.value.trim()) return
  try {
    await api('/api/purchase/synonyms', { body: { product_id: aliasProduct.value.id, alias: aliasInput.value.trim() } })
    toast('已添加别名')
    aliasInput.value = ''
    aliasRows.value = (await api('/api/purchase/products/' + aliasProduct.value.id + '/synonyms')).data || []
    load()
  } catch (e) {
    toast(e.message, false)
  }
}
async function removeAlias(a) {
  try {
    await api('/api/purchase/synonyms/' + a.id, { method: 'DELETE' })
    toast('已删除别名')
    aliasRows.value = (await api('/api/purchase/products/' + aliasProduct.value.id + '/synonyms')).data || []
    load()
  } catch (e) {
    toast(e.message, false)
  }
}

function downloadTemplate() {
  download('/api/purchase/products/template', '广盈物业_标准商品库批量导入模板.xlsx')
}

function onImportFile(e) {
  importFile.value = e.target.files[0] || null
}

async function startImport() {
  if (!importFile.value) { toast('请选择模板文件', false); return }
  importing.value = true
  try {
    const fd = new FormData()
    fd.append('file', importFile.value)
    const res = await api('/api/purchase/products/import', { method: 'POST', form: fd })
    importResult.value = res
    toast('导入完成：新增 ' + (res.inserted || 0) + ' 条，更新 ' + (res.updated || 0) + ' 条')
    load()
    loadUnbound()
  } catch (e) {
    toast(e.message, false)
  } finally {
    importing.value = false
  }
}

function openImport() {
  importResult.value = null
  importFile.value = null
  importVisible.value = true
}

onMounted(() => {
  load()
  loadUnbound()
})
</script>

<template>
  <div>
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
      <div style="font-size:18px;font-weight:700;">标准商品库管理</div>
      <div style="flex:1 1 auto"></div>
      <el-button @click="downloadTemplate">下载导入模板</el-button>
      <el-button type="warning" @click="openImport">批量导入</el-button>
      <el-button type="primary" @click="openCreate">新增商品</el-button>
    </div>

    <div v-if="unbound && ub.rows > 0" class="dash-card" style="padding:10px 14px;margin:12px 0;">
      <div style="font-size:12.5px;color:#b45309;">
        存档中有 {{ ub.rows }} 行采购记录未绑定商品库商品（{{ ub.kinds }} 种，涉及金额 ￥{{ moneyOrDash(ub.amount) }}）。
        其中 {{ ub.bindableKinds }} 种可按“名称+规格+单位”自动绑定，其余为商品库缺失商品（{{ ub.missingRows }} 行）
        <el-link type="primary" style="font-size:12px;vertical-align:baseline;" @click="showUnbound = !showUnbound">{{ showUnbound ? '收起' : '展开明细' }}</el-link>
      </div>
      <el-table v-if="showUnbound" :data="unbound.data" border size="small" style="width:100%;margin-top:8px;" max-height="300">
        <el-table-column prop="item_name" label="商品名称" min-width="140" />
        <el-table-column prop="spec" label="规格" min-width="90" />
        <el-table-column prop="unit" label="单位" width="70" />
        <el-table-column prop="rows" label="行数" width="70" align="right" />
        <el-table-column label="金额" width="110" align="right">
          <template #default="{ row }">{{ moneyOrDash(row.amount) }}</template>
        </el-table-column>
        <el-table-column prop="last_month" label="最近月份" width="90" />
        <el-table-column label="状态" width="100" align="center">
          <template #default="{ row }">
            <el-tag size="small" :type="row.bindable ? 'success' : 'danger'">{{ row.bindable ? '可自动绑定' : '商品库缺失' }}</el-tag>
          </template>
        </el-table-column>
      </el-table>
    </div>

    <div style="display:flex;gap:8px;margin:12px 0;flex-wrap:wrap;">
      <el-select v-model="lines" style="width:140px" placeholder="全部条线" @change="search">
        <el-option value="" label="全部条线" />
        <el-option v-for="l in FILL_LINES" :key="l" :value="l" :label="l" />
      </el-select>
      <el-input v-model="keyword" placeholder="搜索商品/规格/品牌" style="width:240px" clearable @keyup.enter="search" />
      <el-button type="primary" @click="search">查询</el-button>
    </div>

    <el-table v-loading="loading" :data="rows" border size="small" style="width:100%">
      <el-table-column prop="line" label="条线" width="80" />
      <el-table-column prop="category" label="分类" min-width="110" />
      <el-table-column prop="name" label="商品名称" min-width="140" />
      <el-table-column prop="brand" label="品牌" min-width="90" />
      <el-table-column prop="spec" label="规格型号" min-width="110" />
      <el-table-column prop="unit" label="单位" width="70" />
      <el-table-column label="别名" min-width="120">
        <template #default="{ row }">{{ aliasList(row.aliases).join('、') }}</template>
      </el-table-column>
      <el-table-column label="操作" width="180" align="center">
        <template #default="{ row }">
          <el-button link type="primary" size="small" @click="openEdit(row)">编辑</el-button>
          <el-button link type="success" size="small" @click="openAlias(row)">+ 别名</el-button>
          <el-button link type="danger" size="small" @click="removeProduct(row)">删除</el-button>
        </template>
      </el-table-column>
    </el-table>

    <div style="display:flex;justify-content:space-between;align-items:center;margin-top:10px;flex-wrap:wrap;gap:8px;">
      <el-pagination v-model:current-page="page" :page-size="pageSize" :total="total" layout="total, prev, pager, next" @current-change="load" />
      <span style="font-size:12px;color:#999;">共 {{ total }} 条商品。同一商品多规格为多条记录；项目填报时先选名称再选规格。</span>
    </div>

    <!-- 新增/编辑商品 -->
    <el-dialog v-model="editVisible" :title="editForm.id ? '编辑商品' : '新增商品'" width="440px">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
        <div>
          <div style="font-size:12.5px;color:#6b7280;margin-bottom:4px;">条线</div>
          <el-select v-model="editForm.line" style="width:100%">
            <el-option v-for="l in FILL_LINES" :key="l" :value="l" :label="l" />
          </el-select>
        </div>
        <div>
          <div style="font-size:12.5px;color:#6b7280;margin-bottom:4px;">一级分类</div>
          <el-input v-model="editForm.category" placeholder="如：保洁用品 / 工程工具" />
        </div>
        <div style="grid-column:span 2;">
          <div style="font-size:12.5px;color:#6b7280;margin-bottom:4px;">商品名称</div>
          <el-input v-model="editForm.name" placeholder="如：麻花钻头" />
        </div>
        <div style="grid-column:span 2;">
          <div style="font-size:12.5px;color:#6b7280;margin-bottom:4px;">品牌</div>
          <el-input v-model="editForm.brand" placeholder="清单内品牌，没有留空" />
        </div>
        <div style="grid-column:span 2;">
          <div style="font-size:12.5px;color:#6b7280;margin-bottom:4px;">规格型号</div>
          <el-input v-model="editForm.spec" placeholder="如：8MM / 10MM / 12MM（必填）" />
        </div>
        <div>
          <div style="font-size:12.5px;color:#6b7280;margin-bottom:4px;">单位</div>
          <el-input v-model="editForm.unit" placeholder="如：个、箱、把（必填）" />
        </div>
      </div>
      <template #footer>
        <el-button @click="editVisible = false">取消</el-button>
        <el-button type="primary" @click="saveEdit">保存</el-button>
      </template>
    </el-dialog>

    <!-- 别名管理 -->
    <el-dialog v-model="aliasVisible" :title="aliasProduct ? aliasProduct.name + '（' + aliasProduct.spec + '）别名' : '别名'" width="420px">
      <div style="display:flex;gap:8px;margin-bottom:10px;">
        <el-input v-model="aliasInput" placeholder="输入别名，如 A4" @keyup.enter="addAlias" />
        <el-button type="primary" @click="addAlias">添加</el-button>
      </div>
      <el-table :data="aliasRows" border size="small" style="width:100%">
        <el-table-column prop="alias" label="别名" />
        <el-table-column label="操作" width="80" align="center">
          <template #default="{ row }">
            <el-button link type="danger" size="small" @click="removeAlias(row)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>
    </el-dialog>

    <!-- 批量导入 -->
    <el-dialog v-model="importVisible" title="批量导入商品" width="520px">
      <div style="font-size:12.5px;color:#6b7280;margin-bottom:10px;">
        先下载模板，按模板格式填写后上传。条线 / 一级分类 / 商品名称 / 规格型号 / 单位 为必填；品牌可空；相同记录自动覆盖更新。
      </div>
      <div style="display:flex;gap:10px;align-items:center;margin-bottom:10px;flex-wrap:wrap;">
        <el-button @click="downloadTemplate">下载模板</el-button>
        <label style="font-size:13px;">选择填写好的模板文件 <input type="file" accept=".xlsx,.xls" @change="onImportFile" /></label>
      </div>
      <div v-if="importResult" style="font-size:13px;margin-bottom:8px;">
        新增 {{ importResult.inserted || 0 }} 条，更新 {{ importResult.updated || 0 }} 条
        <template v-if="(importResult.errors || []).length">，{{ importResult.errors.length }} 条异常：</template>
        <div v-if="(importResult.errors || []).length" style="color:#dc2626;font-size:12px;margin-top:4px;max-height:140px;overflow:auto;">
          <div v-for="(e, i) in importResult.errors" :key="i">· {{ e }}</div>
        </div>
      </div>
      <template #footer>
        <el-button @click="importVisible = false">关闭</el-button>
        <el-button type="primary" :loading="importing" @click="startImport">开始导入</el-button>
      </template>
    </el-dialog>
  </div>
</template>
