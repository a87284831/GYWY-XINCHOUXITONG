<script setup>
// 清单外商品审核（复刻旧 #/customs：归入候选标准商品 / 新建入库 / 忽略确认通过）
import { ref, computed, onMounted, nextTick } from 'vue'
import { api } from '@/api/client'
import { toast } from '@/utils/toast'
import { useAuthStore } from '@/stores/auth'
import { seedCustomForm, mapPayload, saveCustomPayload, productOptions, productLabel, findProduct } from './adminLogic'

const auth = useAuthStore()
const rows = ref([])
const loading = ref(false)

const editVisible = ref(false)
const editForm = ref({ name:'', brand:'', spec:'', unit:'', line:'', category:'', alias:'', quantity:'', stock:'', use_location:'', remark:'' })
const editRow = ref(null)
const lines = ref([])
const cats = ref([])
const saving = ref(false)

const dupList = ref([])
const dupPick = ref(null)
const dupRow = ref(null)

function uid() {
  return (auth.user && auth.user.id) || 0
}

// 已检索过的商品 id → 商品对象。Element Plus 选中后会清空搜索词并重跑 remote-method，
// 靠这份缓存保住选中项标签，避免下拉框变空白
const productMap = new Map()
function rememberProducts(list) {
  ;(list || []).forEach((p) => {
    if (p && p.id !== null && p.id !== undefined) productMap.set(String(p.id), p)
  })
}

async function load() {
  loading.value = true
  try {
    const d = await api('/api/purchase/admin/customs')
    rows.value = d.data.map((r) => {
      rememberProducts(r.candidates)
      return { ...r, _sel: null, _selItem: null, _search: [], _saveAlias: false }
    })
  } catch (e) {
    toast(e.message, false)
  } finally {
    loading.value = false
  }
}

// 商品库远程搜索：下拉内输入关键字时实时检索标准商品（不限于后端预置候选）
const searchRowId = ref(null)
let searchSeq = 0
async function remoteSearch(row, kw) {
  const q = (kw || '').trim()
  // 空关键字保留上一次结果（选中商品时 Element Plus 会回调空串，清空会导致标签丢失）
  if (!q) return
  const seq = ++searchSeq
  searchRowId.value = row.id
  try {
    const d = await api('/api/purchase/products/search?keyword=' + encodeURIComponent(q))
    if (seq === searchSeq) {
      row._search = d.data || []
      rememberProducts(row._search)
    }
  } catch (e) {
    if (seq === searchSeq) row._search = []
  } finally {
    if (seq === searchSeq) searchRowId.value = null
  }
}

// 直接归入候选商品（旧路径保留给内部复用，实际走归入确认弹窗）
// 归入确认弹窗：先 preview 拿到对照与校验，再由管理员确认
const mapVisible = ref(false)
const mapRow = ref(null)
const mapPreview = ref(null)
const mapSpec = ref('')
const mapUnit = ref('')
const mapGuard = ref(null)
const mapSaving = ref(false)
const mapSaveAlias = ref(false)
let mapTimer = null

async function openMap(row) {
  if (!row._sel) {
    toast('请先选择要归入的标准商品', false)
    return
  }
  mapRow.value = row
  mapSpec.value = row.spec || ''
  mapUnit.value = row.unit || ''
  mapSaveAlias.value = !!row._saveAlias
  mapPreview.value = null
  mapGuard.value = null
  mapVisible.value = true
  await runPreview()
}

async function runPreview() {
  if (!mapRow.value || !mapRow.value._sel) return
  try {
    const d = await api('/api/purchase/admin/customs/map-preview', {
      body: { item_id: mapRow.value.id, product_id: mapRow.value._sel, spec: mapSpec.value, unit: mapUnit.value },
    })
    if (!d.ok) {
      toast(d.msg || '预检失败', false)
      return
    }
    mapPreview.value = d.data
    mapGuard.value = d.data.guard
  } catch (e) {
    toast(e.message, false)
  }
}

// 规格/单位改动后自动重新预检（防抖）
function onMapInput() {
  clearTimeout(mapTimer)
  mapTimer = setTimeout(runPreview, 300)
}

// 归入前后对照（规格/单位显示当前输入值）
const compareRows = computed(() => {
  const p = mapPreview.value
  if (!p) return []
  return [
    { field: '商品名称', from: p.item.item_name || '-', to: p.plan.name || '-' },
    { field: '品牌', from: p.item.brand || '-', to: p.plan.brand || '-' },
    { field: '条线 / 分类', from: '-', to: ((p.plan.line || '-') + ' / ' + (p.plan.category || '-')) },
    { field: '规格', from: p.item.spec || '-', to: mapSpec.value || '-' },
    { field: '单位', from: p.item.unit || '-', to: mapUnit.value || '-' },
  ]
})

async function submitMap(action) {
  if (!mapRow.value) return
  mapRow.value._saveAlias = mapSaveAlias.value
  mapSaving.value = true
  try {
    const d = await api('/api/purchase/admin/customs/map', {
      body: mapPayload(mapRow.value, uid(), { spec: mapSpec.value, unit: mapUnit.value, spec_action: action }),
    })
    if (!d.ok) {
      toast(d.msg || '归入失败', false)
      if (d.code === 'spec_exists') {
        mapGuard.value = { ok: false, code: 'spec_exists', msg: d.msg, existing: d.existing || {} }
      } else if (d.code === 'unit_mismatch') {
        mapGuard.value = d
      }
      return
    }
    toast(d.msg || '已归入')
    mapVisible.value = false
    load()
  } catch (e) {
    toast(e.message, false)
  } finally {
    mapSaving.value = false
  }
}

// 忽略 = 不加入标准库，标记已审核，从清单外列表移除
async function doIgnore(row) {
  try {
    await api('/api/purchase/admin/customs/ignore', { body: { item_id: row.id } })
    toast('已忽略（不加入标准库）')
    load()
  } catch (e) {
    toast(e.message, false)
  }
}

async function loadCats() {
  if (!editForm.value.line) {
    cats.value = []
    return
  }
  try {
    const d = await api('/api/purchase/products/categories?line=' + encodeURIComponent(editForm.value.line))
    let cs = d.data || []
    if (editForm.value.category && cs.indexOf(editForm.value.category) < 0) {
      cs = [editForm.value.category].concat(cs)
    }
    cats.value = cs
  } catch (e) {
    cats.value = []
  }
}

async function openEdit(row) {
  editRow.value = row
  editForm.value = seedCustomForm(row)
  try {
    const d = await api('/api/purchase/products/lines')
    let ls = d.data || []
    if (row.line && ls.indexOf(row.line) < 0) ls = [row.line].concat(ls)
    lines.value = ls
  } catch (e) {
    toast(e.message, false)
  }
  await loadCats()
  dupList.value = []
  dupPick.value = null
  editVisible.value = true
}

function onLineChange(v) {
  editForm.value.line = v
  editForm.value.category = ''
  loadCats()
}

async function submitEdit() {
  const f = editForm.value
  if (!f.name || !f.line || !f.category) {
    toast('请填写商品名称、条线、分类', false)
    return
  }
  saving.value = true
  try {
    const d = await api('/api/purchase/admin/customs/check-duplicate', {
      body: { name: f.name, line: f.line, category: f.category, brand: f.brand, spec: f.spec },
    })
    if (d.ok && d.data && d.data.length > 0) {
      dupList.value = d.data
      dupPick.value = null
      dupRow.value = editRow.value
      return
    }
    await doSave(editRow.value, 'create', 0)
  } catch (e) {
    toast(e.message, false)
  } finally {
    saving.value = false
  }
}

async function doSave(row, mode, pid) {
  const d = await api('/api/purchase/admin/customs/save', {
    body: saveCustomPayload(editForm.value, row, mode, pid, uid()),
  })
  toast(d.msg || '已保存')
  editVisible.value = false
  dupList.value = []
  dupPick.value = null
  dupRow.value = null
  load()
}

function onSelectChange(row, v) {
  if (v === '__new__') {
    row._sel = null
    row._selItem = null
    openEdit(row)
    return
  }
  if (v === null || v === undefined || v === '') {
    row._sel = null
    row._selItem = null
    return
  }
  row._sel = v
  // 先查缓存（选中瞬间搜索结果可能已被清空），再查当前选项
  const hit = productMap.get(String(v)) || findProduct(productOptions(row), v)
  row._selItem = hit || null
  // 把选中项持久化进选项列表：Element Plus 选中后清空搜索词，options 若不含该值会渲染成空白
  if (hit) {
    const list = Array.isArray(row._search) ? row._search.slice() : []
    if (!list.some((x) => String(x.id) === String(v))) list.unshift(hit)
    row._search = list
  }
}

// 把 el-select 实例挂到行上，供选中后手动清空 inputValue
// Vue 3.5 function ref 语义变化：el-table 重渲染/unmount 时会传 null 覆盖旧实例
function setSelectRef(row, el) {
  if (el) row._selectRef = el
}

// EP 2.14.6 单选 filterable 选中后 handleOptionSelect 不清空 inputValue（EP bug），
// 导致 shouldShowPlaceholder=false → #label 插槽被挡不渲染 → 框内残留搜索词或空白。
// EP 自身有 expanded watcher 会在下拉关闭时清空，但 Vue 3.5 + el-table-column slot
// 环境下触发时机不稳定，故在 @visible-change="false" 时强制清一遍确保正确渲染。
function clearSelectInput(row) {
  nextTick(() => {
    const sel = row._selectRef
    if (sel && sel.states && sel.states.inputValue !== undefined) {
      sel.states.inputValue = ''
    }
  })
}

// 选中项显示文案（label 插槽兜底：即使 options 未命中也不出现空白）
function selectedLabel(row, v) {
  if (v === null || v === undefined || v === '') return ''
  const hit = (row && row._selItem && String(row._selItem.id) === String(v))
    ? row._selItem
    : (productMap.get(String(v)) || findProduct(productOptions(row), v))
  return hit ? productLabel(hit) : ''
}

onMounted(load)
</script>

<template>
  <div>
    <div style="display:flex;align-items:center;">
      <span style="font-size:18px;font-weight:700;">清单外商品审核（归入标准商品库）</span>
      <el-button type="primary" style="margin-left:10px" :loading="loading" @click="load">刷新</el-button>
    </div>

    <el-alert type="info" :closable="false" style="margin:12px 0"
      title="项目填报人员填写的清单外商品在此统一审核。可直接从候选商品中选择「归入」，或选择「＋ 新建标准商品入库」编辑全部字段后直接入库；勾选「保存别名」后，项目下次填写相同名称会自动匹配。" />

    <el-table v-loading="loading" :data="rows" border stripe>
      <el-table-column type="index" label="#" width="48" />
      <el-table-column prop="project_name" label="项目" width="130" />
      <el-table-column label="条线" width="70">
        <template #default="{ row }">
          <el-tag size="small" effect="plain">{{ row.line }}</el-tag>
        </template>
      </el-table-column>
      <el-table-column label="填报内容" min-width="300">
        <template #default="{ row }">
          <div style="font-weight:600">{{ row.item_name }}</div>
          <div style="color:#909399;font-size:12px;margin-top:2px">
            品牌：{{ row.brand || '-' }}　规格：{{ row.spec || '-' }}　单位：{{ row.unit || '-' }}
          </div>
          <div style="color:#909399;font-size:12px">分类：{{ row.category || '-' }}</div>
        </template>
      </el-table-column>
      <el-table-column label="归入标准商品" min-width="250">
        <template #default="{ row }">
          <el-select :model-value="row._sel" filterable remote
            :ref="(el) => setSelectRef(row, el)"
            :remote-method="(kw) => remoteSearch(row, kw)" :loading="searchRowId === row.id"
            clearable placeholder="输入关键字搜索商品库，或选「＋新建标准商品入库」"
            style="width:230px"
            @update:model-value="(v) => onSelectChange(row, v)"
            @visible-change="(v) => !v && clearSelectInput(row)">
            <template #label="{ value }">
              <span v-if="selectedLabel(row, value)">{{ selectedLabel(row, value) }}</span>
              <span v-else>{{ value }}</span>
            </template>
            <el-option label="＋ 新建标准商品入库" value="__new__" />
            <el-option v-for="c in productOptions(row)" :key="c.id"
              :label="productLabel(c)" :value="c.id" />
          </el-select>
        </template>
      </el-table-column>
      <el-table-column label="操作" width="260" fixed="right">
        <template #default="{ row }">
          <el-button size="small" type="success" style="margin-right:8px" @click="openEdit(row)">新建入库</el-button>
          <el-button size="small" type="primary" :disabled="!row._sel" @click="openMap(row)">归入</el-button>
          <el-button size="small" type="info" @click="doIgnore(row)">忽略</el-button>
        </template>
      </el-table-column>
    </el-table>

    <el-empty v-if="!loading && rows.length === 0" description="暂无清单外商品" />

    <!-- 归入标准商品确认弹窗：名称强制统一、规格作为该商品的新增规格、单位必须一致 -->
    <el-dialog v-model="mapVisible" title="归入标准商品确认" width="640px" :close-on-click-modal="false">
      <div v-if="mapPreview">
        <div style="margin-bottom:10px;font-weight:600;color:#303133">
          归入目标：{{ mapPreview.product.name }}（{{ mapPreview.product.spec || '-' }}）
          <span v-if="mapPreview.product.brand" style="color:#909399">·{{ mapPreview.product.brand }}</span>
        </div>
        <el-table :data="compareRows" border size="small">
          <el-table-column prop="field" label="字段" width="110" />
          <el-table-column prop="from" label="员工填报" />
          <el-table-column prop="to" label="归入后" />
        </el-table>

        <el-form label-width="90px" style="margin-top:14px">
          <el-form-item label="规格型号">
            <el-input v-model="mapSpec" placeholder="归入后作为该标准商品的一个规格" @input="onMapInput" />
          </el-form-item>
          <el-form-item label="单位">
            <el-input v-model="mapUnit" placeholder="必须与标准商品单位一致" @input="onMapInput" />
          </el-form-item>
          <el-form-item label="保存别名">
            <el-checkbox v-model="mapSaveAlias">把员工原名称存为别名（下次填报自动匹配）</el-checkbox>
          </el-form-item>
        </el-form>
      </div>

      <el-alert v-if="mapGuard && mapGuard.code === 'unit_mismatch'" type="error" :closable="false" show-icon
        :title="mapGuard.msg" style="margin-top:8px" />
      <el-alert v-if="mapGuard && mapGuard.code === 'spec_exists'" type="warning" :closable="false" show-icon
        :title="mapGuard.msg + '。可删除此记录，或修改上方规格型号后重新校验'" style="margin-top:8px" />

      <template #footer>
        <el-button @click="mapVisible = false">取消</el-button>
        <el-button v-if="mapGuard && mapGuard.code === 'spec_exists'" type="danger" :loading="mapSaving"
          @click="submitMap('delete')">删除此记录</el-button>
        <el-button v-else type="primary" :loading="mapSaving"
          :disabled="!mapGuard || !mapGuard.ok || !mapSpec" @click="submitMap('add')">确认归入</el-button>
      </template>
    </el-dialog>

    <!-- 编辑入库（新建/归入标准商品） -->
    <el-dialog v-model="editVisible" title="新建标准商品入库" width="520px">
      <el-form label-width="80px">
        <el-form-item label="商品名称" required>
          <el-input v-model="editForm.name" placeholder="必填" />
        </el-form-item>
        <el-form-item label="条线" required>
          <el-select v-model="editForm.line" placeholder="选择条线" style="width:100%" @change="onLineChange">
            <el-option v-for="l in lines" :key="l" :label="l" :value="l" />
          </el-select>
        </el-form-item>
        <el-form-item label="分类" required>
          <el-select v-model="editForm.category" placeholder="选择或输入分类" filterable allow-create style="width:100%">
            <el-option v-for="c in cats" :key="c" :label="c" :value="c" />
          </el-select>
        </el-form-item>
        <el-form-item label="品牌">
          <el-input v-model="editForm.brand" placeholder="可修改" />
        </el-form-item>
        <el-form-item label="规格">
          <el-input v-model="editForm.spec" placeholder="可修改" />
        </el-form-item>
        <el-form-item label="单位">
          <el-input v-model="editForm.unit" placeholder="可修改" />
        </el-form-item>
        <el-form-item label="别名">
          <el-input v-model="editForm.alias" placeholder="员工填写的原名称（可修改，保存后项目可搜索到）" />
        </el-form-item>
        <el-form-item label="现有库存">
          <el-input v-model="editForm.stock" placeholder="可修改" />
        </el-form-item>
        <el-form-item label="备注">
          <el-input v-model="editForm.remark" placeholder="可修改" />
        </el-form-item>
      </el-form>

      <div v-if="dupList.length" style="margin-top:12px;padding:10px;border-radius:6px;background:#fdf6ec">
        <el-alert type="warning" :closable="false" show-icon title="标准库存在同名商品，请选择处理方式" />
        <el-select v-model="dupPick" placeholder="选择已有商品（归入或新增规格）" filterable clearable
          style="width:100%;margin-top:8px">
          <el-option v-for="d in dupList" :key="d.id" :label="d.name + '（' + (d.spec || '-') + '）'" :value="d.id" />
        </el-select>
        <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:8px;flex-wrap:wrap">
          <el-button @click="dupList = []; dupPick = null">取消</el-button>
          <el-button type="warning" @click="doSave(dupRow, 'create', 0)">仍要新建商品</el-button>
          <el-button type="success" :disabled="!dupPick" @click="doSave(dupRow, 'add_spec', dupPick)">给该商品新增规格</el-button>
          <el-button type="primary" :disabled="!dupPick" @click="doSave(dupRow, 'merge', dupPick)">归入已有规格</el-button>
        </div>
      </div>

      <template #footer>
        <el-button @click="editVisible = false">取消</el-button>
        <el-button type="primary" :loading="saving" @click="submitEdit">确认入库</el-button>
      </template>
    </el-dialog>
  </div>
</template>
